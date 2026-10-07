<?php

namespace App\Filament\Resources\Conversations\Schemas;

use App\Filament\Resources\Conversations\Tables\ConversationsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** Non mostra mai i dati in corso della conversazione (possono contenere dati personali). */
class ConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('wa_number')->label('Agente (WhatsApp)'),
            TextEntry::make('flow')->label('Percorso')
                ->formatStateUsing(fn (string $state) => ConversationsTable::FLOWS[$state] ?? $state),
            TextEntry::make('node')->label('Domanda corrente'),
            TextEntry::make('status')->label('Stato')->badge(),
            TextEntry::make('loanRequest.code')->label('Pratica')->placeholder('-'),
            TextEntry::make('created_at')->label('Iniziata il')->dateTime(),
            TextEntry::make('updated_at')->label('Ultimo messaggio')->dateTime(),
        ]);
    }
}
