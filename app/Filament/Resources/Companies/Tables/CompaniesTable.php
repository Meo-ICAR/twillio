<?php

namespace App\Filament\Resources\Companies\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Ragione sociale')->searchable(),
                TextColumn::make('address')->label('Sede'),
                TextColumn::make('email')->label('Email privacy'),
                TextColumn::make('updated_at')->label('Aggiornata')->dateTime(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
