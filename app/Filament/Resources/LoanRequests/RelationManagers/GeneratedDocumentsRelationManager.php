<?php

namespace App\Filament\Resources\LoanRequests\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Unico\Core\Models\Document;

/**
 * Documenti prodotti per la pratica con unico-core (informativa, contratto…): tipo, stato di firma e file.
 * I PDF vivono sul disco privato dei documenti del core; potranno essere spostati su Drive o SharePoint con le routine del core.
 */
class GeneratedDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documenti prodotti e firma';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Documento'),
                TextColumn::make('documentType.name')->label('Tipo')->placeholder('-'),
                TextColumn::make('firma')->label('Firma')->badge()
                    ->state(fn (Document $record) => $this->signatureStatus($record))
                    ->color(fn (string $state) => match ($state) {
                        'Firmata' => 'success', 'Inviata al cliente', 'In preparazione' => 'info', 'Non inviata' => 'gray', default => 'danger',
                    }),
                IconColumn::make('is_signed')->label('Firmato')->boolean(),
                TextColumn::make('signed_at')->label('Firmato il')->dateTime()->placeholder('-'),
                TextColumn::make('status')->label('Stato')->badge()->placeholder('-'),
                TextColumn::make('sync_status')->label('Archivio')->badge()->placeholder('-')
                    ->tooltip('Stato della copia su Drive o SharePoint (le routine di unico-core spostano i PDF)'),
                TextColumn::make('created_at')->label('Prodotto il')->dateTime(),
            ])
            ->recordActions([
                Action::make('scarica')->label('Scarica')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record) => $record->currentMedia() !== null)
                    ->action(fn (Document $record) => $this->download($record->currentMedia())),
                Action::make('scaricaOriginale')->label('Originale')->icon('heroicon-o-document')->color('gray')
                    ->visible(fn (Document $record) => $record->is_signed && $record->getFirstMedia(Document::COLLECTION) !== null)
                    ->action(fn (Document $record) => $this->download($record->getFirstMedia(Document::COLLECTION))),
            ]);
    }

    /** Stato di firma leggibile: l'ultima richiesta, o firmato se il documento lo è. */
    private function signatureStatus(Document $document): string
    {
        if ($document->is_signed) {
            return 'Firmata';
        }

        return match ($document->signatureRequests()->latest('id')->first()?->status?->value) {
            null => 'Non inviata',
            'pending' => 'In preparazione',
            'sent' => 'Inviata al cliente',
            'signed' => 'Firmata',
            'declined' => 'Rifiutata',
            'expired' => 'Scaduta',
            'cancelled' => 'Annullata',
            default => 'Errore',
        };
    }

    private function download(\Spatie\MediaLibrary\MediaCollections\Models\Media $media)
    {
        return response()->streamDownload(fn () => print (string) stream_get_contents($media->stream()), $media->file_name);
    }
}
