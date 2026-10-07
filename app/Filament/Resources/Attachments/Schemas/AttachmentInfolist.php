<?php

namespace App\Filament\Resources\Attachments\Schemas;

use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
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
            TextEntry::make('mime')->label('Formato'),
            TextEntry::make('received_at')->label('Ricevuto il')->dateTime(),
        ]);
    }
}
