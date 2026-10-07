<?php

namespace App\Filament\Resources\LoanRequests\Tables;

use App\Models\LoanRequest;
use App\Services\Crm\LoanEmailSender;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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
                TextColumn::make('emailed_at')->label('Inviata per email')->dateTime()->placeholder('-')->toggleable(),
                TextColumn::make('created_at')->label('Creata')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(LoanRequest::STATUSES),
                TernaryFilter::make('is_test')->label('Origine')->trueLabel('Prova')->falseLabel('Reali'),
                SelectFilter::make('product')->label('Prodotto')->options(fn () => LoanRequest::productLabels()),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('inviaEmail')->label('Invia per email (forza)')->icon('heroicon-o-envelope')->color('warning')
                        ->requiresConfirmation()->modalDescription('Manda all\'istruttoria dati e allegati delle pratiche selezionate, anche se già inviate.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $sender = app(LoanEmailSender::class);
                            $failed = $records->reject(fn (LoanRequest $loan) => $sender->send($loan));

                            $failed->isEmpty()
                                ? Notification::make()->title($records->count().' pratiche inviate per email')->success()->send()
                                : Notification::make()->title('Invio non riuscito per: '.$failed->pluck('code')->implode(', '))
                                    ->body($sender->recipient() ? 'Controlla la configurazione della posta.' : 'Manca l\'email dell\'istruttoria nella scheda Azienda.')->danger()->persistent()->send();
                        }),
                ]),
            ]);
    }
}
