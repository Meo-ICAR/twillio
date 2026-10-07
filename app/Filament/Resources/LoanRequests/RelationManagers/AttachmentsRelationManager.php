<?php

namespace App\Filament\Resources\LoanRequests\RelationManagers;

use App\Filament\Resources\Attachments\Tables\AttachmentsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Allegati';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('kind')
            ->columns([
                TextColumn::make('kind')->label('Tipo')->formatStateUsing(fn (string $state) => AttachmentsTable::KINDS[$state] ?? $state),
                TextColumn::make('status')->label('Esito')->badge()->color(fn (string $state) => AttachmentsTable::COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => AttachmentsTable::STATUSES[$state] ?? $state),
                TextColumn::make('mime')->label('Formato'),
                TextColumn::make('received_at')->label('Ricevuto il')->dateTime(),
            ])
            ->recordActions([AttachmentsTable::downloadAction()]);
    }
}
