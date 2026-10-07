<?php

namespace App\Filament\Resources\Conversations\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConversationsTable
{
    public const FLOWS = ['richiesta' => 'Richiesta', 'perfezionamento' => 'Perfezionamento'];

    public const STATUSES = ['attiva' => 'Attiva', 'completata' => 'Completata', 'annullata' => 'Annullata'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('wa_number')->label('Agente')->searchable(),
                TextColumn::make('fornitore')->label('Produttore')->placeholder('-')->state(fn ($record) => $record->fornitore?->display_name),
                TextColumn::make('flow')->label('Percorso')->formatStateUsing(fn (string $state) => self::FLOWS[$state] ?? $state),
                TextColumn::make('node')->label('Domanda corrente'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state] ?? $state),
                TextColumn::make('loanRequest.code')->label('Pratica')->placeholder('-'),
                TextColumn::make('updated_at')->label('Ultimo messaggio')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(self::STATUSES),
                SelectFilter::make('flow')->label('Percorso')->options(self::FLOWS),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
