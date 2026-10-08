<?php

namespace App\Filament\Resources\Attachments\Schemas;

use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use App\Models\Attachment;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AttachmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('loanRequest.code')->label('Pratica'),
            TextEntry::make('kind')->label('Tipo')
                ->formatStateUsing(fn (string $state) => AttachmentsTable::KINDS[$state] ?? $state),
            TextEntry::make('status')->label('Esito del controllo')->badge()
                ->color(fn (string $state) => AttachmentsTable::COLORS[$state] ?? 'gray')
                ->formatStateUsing(fn (string $state) => AttachmentsTable::STATUSES[$state] ?? $state),
            TextEntry::make('mime')->label('Formato'),
            TextEntry::make('ai_cost')->label('Costo AI')->placeholder('-')->formatStateUsing(fn ($state) => Attachment::formatCost($state))
                ->helperText(fn ($record) => $record->ai_input_tokens !== null ? "{$record->ai_model}: {$record->ai_input_tokens} token in ingresso, {$record->ai_output_tokens} in uscita" : 'Nessuna lettura con l\'AI.'),
            TextEntry::make('difformita')->label('Difformità')->bulleted()->listWithLineBreaks()->columnSpanFull()
                ->visible(fn ($record) => filled($record->analysis['discrepancies'] ?? null))
                ->state(fn ($record) => $record->analysis['discrepancies'] ?? []),
            KeyValueEntry::make('campi')->label('Dati letti dal documento')->keyLabel('Campo')->valueLabel('Valore')->columnSpanFull()
                ->visible(fn ($record) => filled($record->analysis['fields'] ?? null))
                ->state(fn ($record) => collect($record->analysis['fields'] ?? [])->filter(fn ($v) => is_scalar($v) && $v !== '')->map(fn ($v) => is_bool($v) ? ($v ? 'sì' : 'no') : (string) $v)->all()),
            TextEntry::make('received_at')->label('Ricevuto il')->dateTime(),
        ]);
    }
}
