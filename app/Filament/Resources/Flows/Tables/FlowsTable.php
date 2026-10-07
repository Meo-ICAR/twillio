<?php

namespace App\Filament\Resources\Flows\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label('Percorso')->searchable(),
                TextColumn::make('nodes_count')->label('Domande')->counts('nodes'),
                TextColumn::make('header')->label('Intestazione')->limit(60)->placeholder('nessuna'),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
                TextColumn::make('code')->label('Codice')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()]);
    }
}
