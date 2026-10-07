<?php

namespace App\Filament\Resources\LoanRequests\Schemas;

use App\Models\LoanRequest;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pratica')->columns(3)->schema([
                TextEntry::make('code')->label('Codice'),
                TextEntry::make('agent_wa_number')->label('Agente (WhatsApp)'),
                TextEntry::make('product')->label('Prodotto')
                    ->formatStateUsing(fn (string $state) => LoanRequest::productLabels()[$state] ?? $state),
                TextEntry::make('status')->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => LoanRequest::STATUSES[$state] ?? $state),
                TextEntry::make('privacy_received_at')->label('Informativa ricevuta il')->dateTime()->placeholder('-'),
                TextEntry::make('perfected_at')->label('Perfezionata il')->dateTime()->placeholder('-'),
                TextEntry::make('created_at')->label('Creata il')->dateTime(),
            ]),
            Section::make('Risposte della richiesta')->schema([
                KeyValueEntry::make('answers')->hiddenLabel()->keyLabel('Domanda')->valueLabel('Risposta')
                    ->state(fn (LoanRequest $record) => LoanRequest::describe($record->answers, 'richiesta')),
            ]),
            Section::make('Dati personali')
                ->visible(fn (LoanRequest $record) => filled($record->personal))
                ->schema([
                    KeyValueEntry::make('personal')->hiddenLabel()->keyLabel('Dato')->valueLabel('Valore')
                        ->state(fn (LoanRequest $record) => LoanRequest::describe($record->personal, 'perfezionamento')),
                ]),
        ]);
    }
}
