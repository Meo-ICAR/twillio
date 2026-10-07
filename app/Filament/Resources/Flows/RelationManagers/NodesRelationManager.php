<?php

namespace App\Filament\Resources\Flows\RelationManagers;

use App\Models\FlowNode;
use App\Services\Checks\CheckRegistry;
use App\Services\Flows\FlowRepository;
use App\Services\Flows\FlowValidator;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/** Le domande di un percorso: si modificano testo, etichetta, opzioni e se si può saltare. */
class NodesRelationManager extends RelationManager
{
    protected static string $relationship = 'nodes';

    protected static ?string $title = 'Domande';

    private const TYPES = ['choice' => 'Scelta', 'text' => 'Testo libero', 'file' => 'File', 'code' => 'Codice pratica', 'summary' => 'Riepilogo', 'check' => 'Controllo automatico', 'message' => 'Messaggio'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')->label('#'),
                TextColumn::make('code')->label('Codice')->searchable(),
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                TextColumn::make('prompt')->label('Domanda')->limit(60)->tooltip(fn (FlowNode $record) => $record->prompt)->searchable(),
                TextColumn::make('options_count')->label('Opzioni')->counts('options'),
                TextColumn::make('salti')->label('Salti')->limit(60)->state(fn (FlowNode $record) => $this->jumpSummary($record)),
                IconColumn::make('skippable')->label('Saltabile')->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('3xl')
                    ->schema(fn (FlowNode $record) => $this->nodeFields($record))
                    ->fillForm(fn (FlowNode $record) => [
                        'prompt' => $record->prompt,
                        'label' => $record->label,
                        'skippable' => $record->skippable,
                        'checks' => collect($record->checks ?? [])->map(fn ($e) => is_array($e) ? $e['name'] : $e)->all(),
                        'jump_by' => $record->jump_by,
                        'jumps' => $record->jumps->map(fn ($j) => ['when' => $j->when_value, 'go_to' => $j->go_to])->all(),
                        'options' => $record->options->map(fn ($o) => ['code' => $o->code, 'title' => $o->title, 'existing' => true])->all(),
                    ])
                    ->using(fn (FlowNode $record, array $data) => $this->save($record, $data)),
            ]);
    }

    /** @return array<int,mixed> */
    private function nodeFields(FlowNode $record): array
    {
        $fields = [
            Textarea::make('prompt')->label('Testo della domanda')->required()->rows(3)->maxLength(FlowValidator::MAX_PROMPT),
            TextInput::make('label')->label('Etichetta nel riepilogo')->maxLength(255),
            Toggle::make('skippable')->label('Si può saltare')
                ->helperText('L\'agente può scrivere «salta» per non rispondere. Serve un\'uscita predefinita: non vale per le domande con salti diversi per ogni risposta.'),
        ];

        if (in_array($record->type, ['text', 'choice', 'file'], true)) {
            // Sulle risposte girano i controlli sulle risposte; sui file, quelli sui documenti.
            $isFile = $record->type === 'file';
            $checks = $isFile ? app(CheckRegistry::class)->documentChecks() : app(CheckRegistry::class)->nodeChecks();
            $fields[] = Select::make('checks')->label($isFile ? 'Controlli sul documento' : 'Controlli sulla risposta')->multiple()->searchable()
                ->options(collect($checks)->map(fn ($c) => $c->label())->all())
                ->helperText(new HtmlString(
                    ($isFile
                        ? 'Girano dopo il caricamento, non subito: l\'agente intanto prosegue. Se non ne scegli nessuno valgono quelli predefiniti del tipo di documento. Il primo che non passa ferma gli altri.'
                        : 'Se un controllo non è soddisfatto il bot ripete la domanda.')
                    .' Girano nell\'ordine in cui sono già agganciati; i parametri (per esempio l\'età minima) restano quelli impostati.'
                    .'<br>'.collect($checks)->map(fn ($c) => '<strong>'.e($c->label()).'</strong>: '.e($c->description()))->implode('<br>')
                ));
        }

        if (in_array($record->type, ['choice', 'summary'], true)) {
            $fields[] = Repeater::make('options')->label('Opzioni di risposta')->reorderable()->maxItems(FlowValidator::MAX_OPTIONS)
                ->addActionLabel('Aggiungi un\'opzione')->columns(2)
                ->schema([
                    Hidden::make('existing'),
                    TextInput::make('code')->label('Codice')->required()->maxLength(60)->regex('/^[A-Za-z0-9_]+$/')
                        ->readOnly(fn (Get $get) => (bool) $get('existing'))
                        ->helperText('Non si cambia dopo la creazione: i salti lo usano.'),
                    TextInput::make('title')->label('Titolo su WhatsApp')->required()->maxLength(FlowValidator::MAX_OPTION_TITLE),
                ]);
        }

        if ($record->type !== 'summary') {
            $fields[] = Section::make('Salti')->description('Dopo la risposta, da quale domanda prosegue il dialogo.')->schema([
                Select::make('jump_by')->label('I salti dipendono da')->required()->live()->options($this->jumpByOptions($record)),
                Repeater::make('jumps')->label('Salti')->reorderable()->minItems(1)->addActionLabel('Aggiungi un salto')->columns(2)
                    ->schema([
                        Select::make('when')->label('Quando')->required()
                            ->options(fn (Get $get) => $this->whenOptions($record, $get('../../jump_by'))),
                        Select::make('go_to')->label('Vai alla domanda')->required()->searchable()
                            ->options($record->flow->nodes()->pluck('code', 'code')->all()),
                    ]),
            ]);
        }

        return $fields;
    }

    /** @return array<string,string> */
    private function jumpByOptions(FlowNode $record): array
    {
        $options = ['answer' => 'La risposta a questa domanda'];

        FlowNode::where('type', 'choice')->whereHas('options')->with('flow')
            ->where(fn ($q) => $q->where('flow_id', $record->flow_id)->orWhereHas('flow', fn ($f) => $f->where('code', 'richiesta')))
            ->where('id', '!=', $record->id)->get()
            ->each(function (FlowNode $node) use (&$options, $record) {
                $options[$node->code] = $node->flow_id === $record->flow_id ? "La risposta a «{$node->code}»" : "Il dato della richiesta «{$node->code}»";
            });

        // Un valore già impostato deve restare scegliibile anche se non rientra tra i suggerimenti.
        $options[$record->jump_by] ??= "Il dato «{$record->jump_by}»";

        return $options;
    }

    /** @return array<string,string> */
    private function whenOptions(FlowNode $record, ?string $jumpBy): array
    {
        if ($record->type === 'check') {
            $base = collect($record->params['outcomes'] ?? [])->mapWithKeys(fn ($label, $key) => [$key => "{$key} ({$label})"])->all();
        } else {
            $source = ($jumpBy && $jumpBy !== 'answer')
                ? FlowNode::where('code', $jumpBy)->orderByRaw('flow_id = ? desc', [$record->flow_id])->first()
                : $record;
            $base = $source?->options()->get()->mapWithKeys(fn ($o) => [$o->code => "{$o->title} ({$o->code})"])->all() ?? [];
        }

        return $base + ['*' => 'Qualsiasi altra risposta'];
    }

    private function jumpSummary(FlowNode $record): string
    {
        $jumps = $record->jumps;
        if ($jumps->isEmpty()) {
            return '-';
        }
        if ($jumps->count() === 1 && $jumps->first()->when_value === '*') {
            return $jumps->first()->go_to;
        }

        return $jumps->map(fn ($j) => ($j->when_value === '*' ? 'altro' : $j->when_value).' → '.$j->go_to)->implode(' · ');
    }

    /** Valida lo stato proposto prima di scrivere: se c'è un errore non cambia nulla. */
    private function save(FlowNode $record, array $data): FlowNode
    {
        $items = collect($data['options'] ?? []);
        $codes = $items->pluck('code')->map(fn ($c) => (string) $c);
        $options = $items->mapWithKeys(fn ($o) => [(string) $o['code'] => trim((string) $o['title'])])->all();

        $checks = $this->mergeChecks($record, $data);
        $jumps = array_key_exists('jumps', $data)
            ? collect($data['jumps'])->map(fn ($j) => ['when' => (string) $j['when'], 'go_to' => (string) $j['go_to']])->values()->all()
            : null;
        $jumpBy = $data['jump_by'] ?? null;

        $validator = app(FlowValidator::class);
        $errors = $validator->nodeErrors($record, (string) $data['prompt'], $options, (bool) ($data['skippable'] ?? false), $checks ?? ($record->checks ?? []), $jumps, $jumpBy);
        if ($codes->count() !== $codes->unique()->count()) {
            $errors[] = 'Due opzioni hanno lo stesso codice.';
        }

        if ($errors) {
            $this->refuse($errors);
        }

        $flow = $record->flow;
        $before = $validator->flowErrors($flow);

        DB::beginTransaction();
        try {
            $record->update([
                'prompt' => $data['prompt'],
                'label' => filled($data['label'] ?? null) ? $data['label'] : null,
                'skippable' => (bool) ($data['skippable'] ?? false),
            ] + ($checks !== null ? ['checks' => $checks ?: null] : []) + ($jumpBy !== null ? ['jump_by' => $jumpBy] : []));

            if (in_array($record->type, ['choice', 'summary'], true)) {
                $existing = $record->options()->get()->keyBy('code');
                $position = 0;
                foreach ($options as $code => $title) {
                    $position++;
                    $option = $existing->get($code);
                    $option
                        ? $option->update(['title' => $title, 'sort_order' => $position])
                        : $record->options()->create(['code' => $code, 'title' => $title, 'sort_order' => $position]);
                }
                $record->options()->whereNotIn('code', array_keys($options))->get()->each->delete();
            }

            if ($jumps !== null) {
                $record->jumps()->get()->each->delete();
                foreach ($jumps as $i => $jump) {
                    $record->jumps()->create(['when_value' => $jump['when'], 'go_to' => $jump['go_to'], 'sort_order' => $i + 1]);
                }
            }

            // La modifica non deve lasciare domande irraggiungibili o salti verso il nulla che prima non c'erano.
            $introduced = array_values(array_diff($validator->flowErrors($flow->fresh()), $before));
            if ($introduced) {
                DB::rollBack();
                app(FlowRepository::class)->forget();
                $this->refuse($introduced);
            }

            DB::commit();
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $e) {
            DB::rollBack();
            app(FlowRepository::class)->forget();

            throw $e;
        }

        return $record->fresh();
    }

    /** @param list<string> $errors */
    private function refuse(array $errors): never
    {
        Notification::make()->danger()->persistent()->title('Domanda non salvata')->body(implode("\n", array_unique($errors)))->send();

        throw new Halt;
    }

    /**
     * Controlli da salvare, nell'ordine in cui erano agganciati; quelli nuovi si aggiungono in fondo.
     * Chi era già agganciato conserva i suoi parametri. Restituisce null se il modulo non gestisce i controlli (domande a file, codice, riepilogo).
     *
     * @return list<string|array<string,mixed>>|null
     */
    private function mergeChecks(FlowNode $record, array $data): ?array
    {
        if (! array_key_exists('checks', $data)) {
            return null;
        }

        $selected = array_values($data['checks'] ?? []);
        $current = collect($record->checks ?? [])->mapWithKeys(fn ($e) => [is_array($e) ? $e['name'] : $e => $e]);

        return $current->only($selected)->values()
            ->merge(collect($selected)->diff($current->keys())->values())
            ->all();
    }
}
