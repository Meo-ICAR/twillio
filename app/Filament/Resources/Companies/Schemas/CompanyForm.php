<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Titolare del trattamento')
                ->description('Questi dati compaiono nell\'informativa privacy pubblica (/privacy).')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Ragione sociale')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('address')->label('Sede')->maxLength(255)->columnSpanFull(),
                    TextInput::make('email')->label('Email per la privacy')->email()->maxLength(255),
                    TextInput::make('dpo_email')->label('Email del DPO (se nominato)')->email()->maxLength(255),
                    TextInput::make('customer_care_phone')->label('Telefono del customer care')->tel()->maxLength(40)
                        ->helperText('Indicato a chi non è un produttore convenzionato (segnalatore occasionale).'),
                    TextInput::make('customer_care_email')->label('Email del customer care')->email()->maxLength(255),
                    TextInput::make('istruttoria_email')->label('Email dell\'istruttoria')->email()->maxLength(255)
                        ->helperText('Riceve i dati della pratica e gli allegati quando viene inviata in istruttoria.'),
                    Textarea::make('retention_perfected')
                        ->label('Conservazione delle pratiche perfezionate')
                        ->helperText('Per quanto tempo e perché vengono conservati i dati delle pratiche perfezionate.')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
