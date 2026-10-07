<?php

namespace App\Filament\Resources\FlowChecks\Tables;

use App\Models\FlowCheck;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlowChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('Codice')->searchable(),
                TextColumn::make('tipo')->label('Su')->badge()->state(fn (FlowCheck $record) => $record->isDocumentCheck() ? 'Documento' : 'Risposta'),
                TextColumn::make('nome')->label('Controllo')->state(fn (FlowCheck $record) => $record->label()),
                TextColumn::make('descrizione')->label('Cosa fa')->wrap()->state(fn (FlowCheck $record) => $record->description()),
                TextColumn::make('usato')->label('Usato in')->bulleted()->listWithLineBreaks()->placeholder('nessuna domanda')
                    ->state(fn (FlowCheck $record) => $record->usedBy()->all()),
                IconColumn::make('is_active')->label('Attivo')->boolean(),
                TextColumn::make('class')->label('Classe')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Si toglie solo dall\'elenco: la classe resta nel codice e si può riaggiungere con «Cerca nuovi controlli».')
                    ->before(function (DeleteAction $action, FlowCheck $record) {
                        $used = $record->usedBy();
                        if ($used->isNotEmpty()) {
                            Notification::make()->danger()->persistent()->title('Controllo in uso')
                                ->body('Lo usano queste domande: '.$used->implode(', ').'. Toglilo prima da lì.')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }
}
