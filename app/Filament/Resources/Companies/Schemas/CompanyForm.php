<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Azienda e contratto')
                ->columns(2)
                ->schema([
                    Select::make('type')->label('Settore')->options(Company::TYPES)->required()->default('FINANCE'),
                    Select::make('products')->label('Prodotti attivi')->relationship('products', 'name')->multiple()->preload(),
                    Toggle::make('is_trial')->label('In prova (trial)')->live(),
                    DatePicker::make('trial_activated_at')->label('Prova attivata il'),
                    DatePicker::make('trialend_at')->label('La prova termina il')->visible(fn ($get) => (bool) $get('is_trial')),
                    DatePicker::make('activated_at')->label('Contratto attivato il'),
                    TextInput::make('whatsapp_number')->label('Cellulare WhatsApp')->tel()->maxLength(30)
                        ->helperText('Il numero WhatsApp Business dell\'azienda.'),
                    FileUpload::make('logo')->label('Logo')->image()->disk('public')->directory('company-logos')->visibility('public'),
                ]),

            Section::make('Titolare del trattamento')
                ->columnSpanFull()
                ->description('Questi dati compaiono nell\'informativa privacy pubblica (/privacy).')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Ragione sociale')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('address')->label('Sede')->maxLength(255)->columnSpanFull(),
                    TextInput::make('email')->label('Email per la privacy')->email()->maxLength(255),
                    TextInput::make('dpo_email')->label('Email del DPO (se nominato)')->email()->maxLength(255),

                    Textarea::make('retention_perfected')
                        ->label('Conservazione delle pratiche perfezionate')
                        ->helperText('Per quanto tempo e perché vengono conservati i dati delle pratiche perfezionate.')
                        ->rows(4)
                        ->columnSpanFull(),

                    TextInput::make('customer_care_phone')->label('Telefono del customer care')->tel()->maxLength(40)
                        ->helperText('Indicato a chi non è un produttore convenzionato (segnalatore occasionale).'),
                    TextInput::make('customer_care_email')->label('Email del customer care')->email()->maxLength(255),
                    TextInput::make('istruttoria_email')->label('Email dell\'istruttoria')->email()->maxLength(255)
                        ->helperText('Riceve i dati della pratica e gli allegati quando viene inviata in istruttoria.'),
                    TextInput::make('url_preventivatore')->label('URL del preventivatore (CRM)')->url()->maxLength(255)
                        ->helperText('Se vuoto, i dati del preventivo vengono mandati per email all\'istruttoria.'),
                    TextInput::make('url_istruttoria')->label('URL dell\'istruttoria (CRM)')->url()->maxLength(255)
                        ->helperText('Se vuoto, la pratica con gli allegati viene mandata per email all\'istruttoria.'),

                ]),
        ]);
    }
}
