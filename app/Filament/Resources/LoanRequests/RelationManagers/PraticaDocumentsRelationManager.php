<?php

namespace App\Filament\Resources\LoanRequests\RelationManagers;

use App\Models\FinanziamentoDocument;
use App\Models\PraticaDocument;
use App\Services\Documents\AgentNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Documenti della pratica: stato, annotazioni di AI e operatore, e azioni dell'istruttore. */
class PraticaDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'praticaDocuments';

    protected static ?string $title = 'Documenti della pratica';

    private const COLORS = ['da_ricevere' => 'gray', 'ricevuto' => 'info', 'ok' => 'success', 'rejected' => 'danger', 'integrazione_richiesta' => 'warning'];

    private const REQUIREMENT_COLORS = ['obbligatorio' => 'danger', 'facoltativo' => 'gray', 'integrativo' => 'warning'];

    /** Le azioni dell'istruttore servono proprio nella scheda di sola lettura. */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Documento')->sorted(),
                TextColumn::make('requirement')->label('Tipo')->badge()->color(fn (string $state) => self::REQUIREMENT_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => FinanziamentoDocument::REQUIREMENTS[$state] ?? $state),
                TextColumn::make('status')->label('Stato')->sorted()->badge()->color(fn (string $state) => self::COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => PraticaDocument::STATUSES[$state] ?? $state),
                TextColumn::make('storico')->label('Annotazioni')->wrap()->bulleted()->listWithLineBreaks()
                    ->state(fn (PraticaDocument $record) => collect($record->annotations ?? [])
                        ->map(fn (array $n) => ($n['by'] === 'ai' ? 'AI' : 'Operatore').': '.$n['text'])->all()),
                TextColumn::make('received_at')->label('Ricevuto il')->sorted()->dateTime()->placeholder('-'),
            ])
            ->headerActions([$this->requestIntegrativeAction()])
            ->recordActions([
                Action::make('approva')->label('Approva')->color('success')->icon('heroicon-o-check')
                    ->visible(fn (PraticaDocument $record) => $record->status !== 'ok')
                    ->action(fn (PraticaDocument $record) => $record->approve(auth()->id())),
                Action::make('rifiuta')->label('Rifiuta')->color('danger')->icon('heroicon-o-x-mark')
                    ->schema([Textarea::make('note')->label('Cosa non va')->required()->rows(3)])
                    ->action(function (PraticaDocument $record, array $data) {
                        $record->reject($data['note'], auth()->id());
                        $this->notifyAgent($record);
                    }),
                Action::make('integrazione')->label('Chiedi integrazione')->color('warning')->icon('heroicon-o-document-plus')
                    ->schema([Textarea::make('note')->label('Cosa serve in più')->required()->rows(3)])
                    ->action(function (PraticaDocument $record, array $data) {
                        $record->requestIntegration($data['note'], auth()->id());
                        $this->notifyAgent($record);
                    }),
            ]);
    }

    private function requestIntegrativeAction(): Action
    {
        return Action::make('richiediIntegrativo')->label('Richiedi un documento integrativo')->icon('heroicon-o-plus')
            ->schema([
                Select::make('document')->label('Documento')->required()->live()
                    ->options(fn () => FinanziamentoDocument::active()->where('product', $this->getOwnerRecord()->product)->where('requirement', 'integrativo')
                        ->orderBy('sort_order')->pluck('name', 'code')->all() + ['__altro' => 'Altro (scrivi il nome)']),
                TextInput::make('name')->label('Nome del documento')->maxLength(100)
                    ->visible(fn (Get $get) => $get('document') === '__altro')
                    ->required(fn (Get $get) => $get('document') === '__altro'),
                Textarea::make('note')->label('Cosa serve e perché')->required()->rows(3),
            ])
            ->action(function (array $data) {
                $loan = $this->getOwnerRecord();
                $code = $data['document'] === '__altro' ? null : $data['document'];
                $name = $code ? FinanziamentoDocument::where('product', $loan->product)->where('code', $code)->value('name') : $data['name'];

                $this->notifyAgent($loan->requestIntegrativeDocument($code, $name ?? $code, $data['note'], auth()->id()));
            });
    }

    private function notifyAgent(PraticaDocument $slot): void
    {
        if (! app(AgentNotifier::class)->notify($slot)) {
            Notification::make()->title('Agente non avvisato su WhatsApp')->body('Troverà la richiesta in Stato Pratiche.')->warning()->send();
        }
    }
}
