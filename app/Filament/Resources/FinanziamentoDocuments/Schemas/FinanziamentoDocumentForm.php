<?php

namespace App\Filament\Resources\FinanziamentoDocuments\Schemas;

use App\Models\FinanziamentoDocument;
use App\Models\LoanRequest;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class FinanziamentoDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('product')->label('Tipo di finanziamento')->options(fn () => LoanRequest::productLabels())->required()->live(),
            Select::make('requirement')->label('Tipo di documento')->options(FinanziamentoDocument::REQUIREMENTS)->required()
                ->helperText('Obbligatorio e facoltativo si creano con la pratica; integrativo si richiede solo quando serve un approfondimento.'),
            TextInput::make('name')->label('Nome mostrato su WhatsApp')->required()->maxLength(24)
                ->helperText('Massimo 24 caratteri: è il titolo della voce nella lista.'),
            TextInput::make('code')->label('Codice')->required()->maxLength(40)->regex('/^[a-z0-9_]+$/')
                ->helperText('Solo minuscole, numeri e trattino basso. Non si cambia dopo che le pratiche lo usano.')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('product', $get('product'))),
            Textarea::make('description')->label('Descrizione')->rows(2)->columnSpanFull(),
            Select::make('ai_kind')->label('Lettura con AI')->options(FinanziamentoDocument::AI_KINDS)->placeholder('Nessuna: lo controlla l\'istruttore')
                ->helperText('L\'AI legge e confronta con i dati dichiarati solo i documenti con un tipo di lettura.'),
            TextInput::make('sort_order')->label('Ordine')->numeric()->default(0)->minValue(0)->required(),
            Toggle::make('is_active')->label('Attivo')->default(true),
        ]);
    }
}
