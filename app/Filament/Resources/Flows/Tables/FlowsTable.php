<?php

namespace App\Filament\Resources\Flows\Tables;

use App\Models\Flow;
use App\Services\Flows\FlowCloner;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FlowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label('Percorso')->searchable(),
                TextColumn::make('is_test')->label('Versione')->badge()
                    ->color(fn (bool $state) => $state ? 'warning' : 'success')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Prova' : 'Produzione'),
                TextColumn::make('nodes_count')->label('Domande')->counts('nodes'),
                TextColumn::make('header')->label('Intestazione')->limit(60)->placeholder('nessuna'),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
                TextColumn::make('code')->label('Codice')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([TernaryFilter::make('is_test')->label('Versione')->trueLabel('Prova')->falseLabel('Produzione')])
            ->recordActions([
                EditAction::make(),
                Action::make('creaCopiaProva')->label('Crea copia di prova')->icon('heroicon-o-beaker')->color('warning')
                    ->visible(fn (Flow $record) => ! $record->is_test && ! self::hasCopy($record))
                    ->requiresConfirmation()
                    ->modalDescription('Crea una copia identica da modificare e provare. Gli agenti continuano a usare la produzione; la copia la vedono nel menu solo i numeri associati a un utente.')
                    ->action(fn (Flow $record) => self::run(fn () => app(FlowCloner::class)->createTestCopy($record), 'Copia di prova creata')),
                Action::make('ricreaCopiaProva')->label('Rifai la copia di prova')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (Flow $record) => ! $record->is_test && self::hasCopy($record))
                    ->requiresConfirmation()
                    ->modalDescription('Rifà la copia di prova partendo dalla produzione: le modifiche fatte alla copia vanno perse.')
                    ->action(fn (Flow $record) => self::run(fn () => app(FlowCloner::class)->createTestCopy($record, replace: true), 'Copia di prova rifatta')),
                Action::make('pubblica')->label('Pubblica in produzione')->icon('heroicon-o-rocket-launch')->color('success')
                    ->visible(fn (Flow $record) => $record->is_test)
                    ->requiresConfirmation()
                    ->modalDescription('Il contenuto della copia di prova sostituisce quello di produzione: da subito vale per tutti gli agenti, anche per le conversazioni in corso. Si rifiuta se la copia ha errori.')
                    ->action(fn (Flow $record) => self::run(fn () => app(FlowCloner::class)->publish($record), 'Pubblicato in produzione')),
            ]);
    }

    private static function hasCopy(Flow $flow): bool
    {
        return Flow::where('code', $flow->code)->where('is_test', true)->exists();
    }

    private static function run(\Closure $operation, string $done): void
    {
        try {
            $operation();
            Notification::make()->success()->title($done)->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->persistent()->title('Operazione non eseguita')->body($e->getMessage())->send();
        }
    }
}
