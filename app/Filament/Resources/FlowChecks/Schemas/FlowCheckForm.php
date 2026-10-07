<?php

namespace App\Filament\Resources\FlowChecks\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FlowCheckForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label('Codice')->disabled()->dehydrated(false)
                ->helperText('Non si cambia: le domande agganciano il controllo con questo nome.'),
            TextInput::make('class')->label('Classe')->disabled()->dehydrated(false),
            Toggle::make('is_active')->label('Attivo')
                ->helperText('Un controllo disattivato non si può più agganciare alle domande. Non si può disattivare finché una domanda lo usa.'),
            TextInput::make('sort_order')->label('Ordine nell\'elenco')->numeric()->minValue(0)->required(),
        ]);
    }
}
