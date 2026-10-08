<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Models\Company;
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
                TextColumn::make('type')->label('Settore')->badge()->formatStateUsing(fn (string $state) => Company::TYPES[$state] ?? $state),
                TextColumn::make('is_trial')->label('Prova')->badge()->color(fn (bool $state) => $state ? 'warning' : 'success')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Trial' : 'Contratto'),
                TextColumn::make('trialend_at')->label('Fine prova')->date()->placeholder('-'),
                TextColumn::make('address')->label('Sede')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')->label('Email privacy'),
                TextColumn::make('updated_at')->label('Aggiornata')->dateTime(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
