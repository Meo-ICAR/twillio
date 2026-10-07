<?php

namespace App\Filament\Resources\FinanziamentoDocuments\Tables;

use App\Models\FinanziamentoDocument;
use App\Models\LoanRequest;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FinanziamentoDocumentsTable
{
    public const COLORS = ['obbligatorio' => 'danger', 'facoltativo' => 'gray', 'integrativo' => 'warning'];

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('product')
            ->columns([
                TextColumn::make('product')->label('Finanziamento')->sortable()
                    ->formatStateUsing(fn (string $state) => LoanRequest::productLabels()[$state] ?? $state),
                TextColumn::make('name')->label('Documento')->searchable(),
                TextColumn::make('requirement')->label('Tipo')->badge()->color(fn (string $state) => self::COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => FinanziamentoDocument::REQUIREMENTS[$state] ?? $state),
                TextColumn::make('ai_kind')->label('Lettura AI')->placeholder('-')
                    ->formatStateUsing(fn (?string $state) => FinanziamentoDocument::AI_KINDS[$state] ?? $state),
                TextColumn::make('sort_order')->label('Ordine')->sortable(),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
                TextColumn::make('code')->label('Codice')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('product')->label('Finanziamento')->options(fn () => LoanRequest::productLabels()),
                SelectFilter::make('requirement')->label('Tipo')->options(FinanziamentoDocument::REQUIREMENTS),
                TernaryFilter::make('is_active')->label('Attivo'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->modalDescription('Le pratiche già create tengono i loro documenti: viene tolto solo dal catalogo.')]);
    }
}
