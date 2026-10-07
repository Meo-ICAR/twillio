<?php

namespace App\Filament\Resources\LoanRequests\RelationManagers;

use App\Models\FinanziamentoDocument;
use App\Models\PraticaField;
use App\Services\Flows\FlowRepository;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Dati letti dall'AI dai documenti: proposte finché l'agente non le conferma nel dialogo. Solo lettura. */
class PraticaFieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    protected static ?string $title = 'Dati letti dai documenti';

    private const COLORS = ['proposto' => 'warning', 'confermato' => 'success', 'rifiutato' => 'gray'];

    public function table(Table $table): Table
    {
        $flows = app(FlowRepository::class);

        return $table
            ->recordTitleAttribute('key')
            ->defaultSort('id')
            ->columns([
                TextColumn::make('key')->label('Dato')
                    ->formatStateUsing(fn (string $state) => $flows->node('perfezionamento', $state)['label'] ?? $state),
                TextColumn::make('value')->label('Valore'),
                TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => self::COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn (string $state) => PraticaField::STATUSES[$state] ?? $state),
                TextColumn::make('source_code')->label('Letto da')->placeholder('-')
                    ->state(fn (PraticaField $record) => $record->attachment?->praticaDocument?->name
                        ?? FinanziamentoDocument::where('code', $record->source_code)->value('name') ?? $record->source_code),
                TextColumn::make('confirmed_at')->label('Confermato il')->dateTime()->placeholder('-'),
            ]);
    }
}
