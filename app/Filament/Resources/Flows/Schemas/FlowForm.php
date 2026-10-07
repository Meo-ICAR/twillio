<?php

namespace App\Filament\Resources\Flows\Schemas;

use App\Models\Flow;
use App\Services\Flows\FlowValidator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FlowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            Toggle::make('is_active')->label('Attivo')->helperText('Un percorso disattivato non si può più avviare dal menu.'),
            Textarea::make('header')->label('Intestazione')->rows(4)->maxLength(FlowValidator::MAX_HEADER)->columnSpanFull()
                ->helperText('Facoltativa. Il bot la mostra una sola volta, all\'inizio del dialogo, prima della prima domanda. Vuota = nessuna intestazione. In WhatsApp *testo* è grassetto.'),
            Select::make('start')->label('Prima domanda')->required()
                ->options(fn (?Flow $record) => $record?->nodes()->pluck('code', 'code')->all() ?? []),
            Select::make('restart')->label('Domanda da cui si ricomincia')->required()
                ->options(fn (?Flow $record) => $record?->nodes()->pluck('code', 'code')->all() ?? [])
                ->helperText('Dove riparte il dialogo con «Ricomincia» nel riepilogo.'),
        ]);
    }
}
