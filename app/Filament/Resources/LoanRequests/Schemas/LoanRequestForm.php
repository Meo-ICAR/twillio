<?php

namespace App\Filament\Resources\LoanRequests\Schemas;

use App\Models\LoanRequest;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LoanRequestForm
{
    /** Dal pannello si modifica solo lo stato: risposte e dati personali arrivano dal bot. */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Codice')->disabled(),
            TextInput::make('agent_wa_number')->label('Agente (WhatsApp)')->disabled(),
            Select::make('status')->label('Stato')->options(LoanRequest::STATUSES)->required(),
        ]);
    }
}
