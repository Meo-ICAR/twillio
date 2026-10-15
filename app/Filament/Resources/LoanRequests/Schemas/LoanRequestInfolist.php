<?php

namespace App\Filament\Resources\LoanRequests\Schemas;

use App\Models\Attachment;
use App\Models\LoanRequest;
use Filament\Infolists\Components\IconEntry;
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
                    ->formatStateUsing(fn (string $state, LoanRequest $record) => LoanRequest::productLabels($record->company_id)[$state] ?? $state),
                TextEntry::make('status')->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => LoanRequest::STATUSES[$state] ?? $state),
                TextEntry::make('privacy_received_at')->label('Informativa ricevuta il')->dateTime()->placeholder('-'),
                TextEntry::make('privacy_verified_at')->label('Informativa verificata il')->dateTime()->placeholder('-')
                    ->helperText('Verificata dall\'AI (nostro modulo, firmato) o approvata da un operatore: da qui i documenti vengono letti.'),
                TextEntry::make('customer_phone')->label('Cellulare cliente')->placeholder('-'),
                TextEntry::make('customer_email')->label('Email cliente')->placeholder('-'),
                IconEntry::make('direct_contact')->label('Contatto diretto col cliente')->boolean()
                    ->helperText('I documenti si possono chiedere anche direttamente al cliente.'),
                TextEntry::make('ai_cost_total')->label('Costo AI (documenti)')->state(fn (LoanRequest $record) => Attachment::formatCost($record->attachments()->sum('ai_cost')))->placeholder('-'),
                TextEntry::make('parent.code')->label('Modifica del preventivo')->placeholder('-'),
                TextEntry::make('perfected_at')->label('Perfezionata il')->dateTime()->placeholder('-'),
                TextEntry::make('created_at')->label('Creata il')->dateTime(),
            ]),
            Section::make('Firma')->columns(3)
                ->visible(fn (LoanRequest $record) => filled($record->signature_status))
                ->schema([
                    TextEntry::make('signature_status')->label('Stato di firma')->badge()
                        ->formatStateUsing(fn (?string $state) => LoanRequest::SIGNATURE_STATUSES[$state] ?? $state),
                    TextEntry::make('documents_sent_at')->label('Inviati per la firma il')->dateTime()->placeholder('-'),
                    TextEntry::make('signed_at')->label('Firmata il')->dateTime()->placeholder('-'),
                ]),
            Section::make('Risposte della richiesta')->schema([
                KeyValueEntry::make('answers')->hiddenLabel()->keyLabel('Domanda')->valueLabel('Risposta')
                    ->state(fn (LoanRequest $record) => LoanRequest::describe($record->answers, 'richiesta', $record->company_id)),
            ]),
            Section::make('Dati difformi da verificare')
                ->description('Segnalati dal bot e confermati dall\'agente: da controllare in istruttoria.')
                ->visible(fn (LoanRequest $record) => filled($record->personal['_difformita'] ?? null))
                ->schema([
                    TextEntry::make('difformita')->hiddenLabel()->bulleted()->listWithLineBreaks()
                        ->state(fn (LoanRequest $record) => $record->personal['_difformita'] ?? []),
                ]),
         Section::make('Dati personali')
    ->visible(fn (LoanRequest $record) => filled($record->personal))
    ->columnSpanFull()
    ->columns(6)
    ->schema(function (LoanRequest $record) {
        $data = LoanRequest::describe($record->personal, 'perfezionamento', $record->company_id) ?? [];

        return collect($data)->map(function ($value, $key) {
            return TextEntry::make("personal_{$key}")
                ->label($key)
                ->state($value);
        })->toArray();
    }),
        ]);
    }
}
