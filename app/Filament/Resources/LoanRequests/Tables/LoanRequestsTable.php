<?php

namespace App\Filament\Resources\LoanRequests\Tables;

use App\Models\LoanRequest;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LoanRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('Codice')->searchable()->sortable(),
                TextColumn::make('is_test')->label('Origine')->badge()->color(fn (bool $state) => $state ? 'warning' : 'gray')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Prova' : 'Reale'),
                TextColumn::make('agent_wa_number')->label('Agente')->searchable(),
                TextColumn::make('product')->label('Prodotto')
                    ->formatStateUsing(fn (string $state) => LoanRequest::productLabels()[$state] ?? $state),
                TextColumn::make('status')->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => LoanRequest::STATUSES[$state] ?? $state),
                TextColumn::make('privacy_received_at')->label('Informativa')->dateTime()->sortable()->placeholder('-'),
                TextColumn::make('perfected_at')->label('Perfezionata')->dateTime()->sortable()->placeholder('-'),
                TextColumn::make('created_at')->label('Creata')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(LoanRequest::STATUSES),
                TernaryFilter::make('is_test')->label('Origine')->trueLabel('Prova')->falseLabel('Reali'),
                SelectFilter::make('product')->label('Prodotto')->options(fn () => LoanRequest::productLabels()),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }
}
