<?php

namespace App\Filament\Resources\Flows\RelationManagers;

use App\Models\FlowNode;
use App\Services\Flows\FlowValidator;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Le domande di un percorso: si modificano testo, etichetta, opzioni e se si può saltare. */
class NodesRelationManager extends RelationManager
{
    protected static string $relationship = 'nodes';

    protected static ?string $title = 'Domande';

    private const TYPES = ['choice' => 'Scelta', 'text' => 'Testo libero', 'file' => 'File', 'code' => 'Codice pratica', 'summary' => 'Riepilogo', 'check' => 'Controllo automatico'];

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

        return $fields;
    }

    /** Valida lo stato proposto prima di scrivere: se c'è un errore non cambia nulla. */
    private function save(FlowNode $record, array $data): FlowNode
    {
        $items = collect($data['options'] ?? []);
        $codes = $items->pluck('code')->map(fn ($c) => (string) $c);
        $options = $items->mapWithKeys(fn ($o) => [(string) $o['code'] => trim((string) $o['title'])])->all();

        $errors = app(FlowValidator::class)->nodeErrors($record, (string) $data['prompt'], $options, (bool) ($data['skippable'] ?? false));
        if ($codes->count() !== $codes->unique()->count()) {
            $errors[] = 'Due opzioni hanno lo stesso codice.';
        }

        if ($errors) {
            Notification::make()->danger()->persistent()->title('Domanda non salvata')->body(implode("\n", array_unique($errors)))->send();

            throw new Halt;
        }

        DB::transaction(function () use ($record, $data, $options) {
            $record->update([
                'prompt' => $data['prompt'],
                'label' => filled($data['label'] ?? null) ? $data['label'] : null,
                'skippable' => (bool) ($data['skippable'] ?? false),
            ]);

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
        });

        return $record->fresh();
    }
}
