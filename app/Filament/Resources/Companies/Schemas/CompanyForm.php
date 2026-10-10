<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use App\Services\Crm\CrmRegistry;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
             
          

            Section::make('Azienda e privacy')->columns(2)
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
                    TextInput::make('preventivatore_passkey')->label('Passkey del preventivatore')->password()->revealable()->maxLength(255)
                        ->helperText('Fornita con il servizio di simulazione; serve solo se il preventivatore è quello Mediafacile.'),
                    TextInput::make('url_istruttoria')->label('URL dell\'istruttoria (CRM)')->url()->maxLength(255)
                        ->helperText('Se vuoto, la pratica con gli allegati viene mandata per email all\'istruttoria.'),
                    TextInput::make('istruttoria_passkey')->label('Passkey dell\'istruttoria (CRM)')->password()->revealable()->maxLength(255)
                        ->helperText('Fornita con il servizio di caricamento lead; serve solo se il CRM è quello Mediafacile.'),

                ]),
            Section::make('CRM dell\'istruttoria')
                ->columnSpanFull()
                ->columns(2)
                ->description('Il CRM a cui arriva la pratica perfezionata. Un\'azienda ne usa uno solo.')
                ->schema([
                    Select::make('crm_driver')->label('CRM')
                        ->options(['email' => 'Solo email (senza CRM)'] + app(CrmRegistry::class)->labels())
                        ->placeholder('Automatico (Mediafacile se c\'è l\'URL dell\'istruttoria, altrimenti email)')
                        ->live()
                        ->columnSpanFull(),
                    Group::make([
                        TextInput::make('crm_config.url')->label('Indirizzo di unicoloan')->url()->maxLength(255)->required()
                            ->helperText('Indirizzo di base (https), ad es. https://unicoloan.example.com'),
                        TextInput::make('crm_config.token')->label('Token')->password()->revealable()->required()->maxLength(500),
                        TextInput::make('crm_config.secret')->label('Segreto per la firma')->password()->revealable()->required()->maxLength(500)
                            ->helperText('Token e segreto si ottengono in unicoloan con: php artisan agent-api:client unicoagent'),
                        Select::make('crm_config.analysis')->label('Analisi dei documenti')
                            ->options(['summary' => 'Solo esito e discrepanze', 'full' => 'Anche i dati letti dal documento'])->default('summary')
                            ->helperText('Cosa si manda a unicoloan dell\'analisi già fatta qui.'),
                    ])->columns(2)->columnSpanFull()
                        ->visible(fn (Get $get) => $get('crm_driver') === 'unicoloan'),
                    Group::make([
                        TextInput::make('crm_config.url')->label('Indirizzo')->url()->maxLength(255)->required()
                            ->helperText('Solo https (http è ammesso soltanto in sviluppo).'),
                        Select::make('crm_config.method')->label('Metodo')->options(['POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH'])->default('POST'),
                        Select::make('crm_config.format')->label('Formato')->options(['json' => 'JSON', 'form' => 'Form'])->default('json'),
                        Select::make('crm_config.auth')->label('Autenticazione')
                            ->options(['none' => 'Nessuna', 'bearer' => 'Bearer token', 'header' => 'Intestazione con chiave', 'basic' => 'Utente e password'])
                            ->default('none')->live(),
                        TextInput::make('crm_config.token')->label('Token / chiave')->password()->revealable()->maxLength(500)
                            ->visible(fn (Get $get) => in_array($get('crm_config.auth'), ['bearer', 'header'], true)),
                        TextInput::make('crm_config.header_name')->label('Nome dell\'intestazione')->default('X-Api-Key')->maxLength(100)
                            ->visible(fn (Get $get) => $get('crm_config.auth') === 'header'),
                        TextInput::make('crm_config.username')->label('Utente')->maxLength(255)
                            ->visible(fn (Get $get) => $get('crm_config.auth') === 'basic'),
                        TextInput::make('crm_config.password')->label('Password')->password()->revealable()->maxLength(255)
                            ->visible(fn (Get $get) => $get('crm_config.auth') === 'basic'),
                        Textarea::make('crm_config.body_template')->label('Modello del messaggio (JSON)')->rows(8)->columnSpanFull()
                            ->helperText('Con segnaposto come {{cliente.cognome}}, {{riferimento}}, {{pratica.importo_richiesto}}. Vuoto = si invia l\'intera pratica.'),
                        TextInput::make('crm_config.success_codes')->label('Codici HTTP validi')->placeholder('200,201')
                            ->helperText('Vuoto = qualsiasi 2xx.'),
                        TextInput::make('crm_config.id_path')->label('Dove leggere l\'id della pratica nella risposta')->placeholder('data.id'),
                        TextInput::make('crm_config.ok_path')->label('Esito nella risposta: percorso')->placeholder('stato'),
                        TextInput::make('crm_config.ok_value')->label('Esito nella risposta: valore atteso')->placeholder('OK'),
                    ])->columns(2)->columnSpanFull()
                        ->visible(fn (Get $get) => $get('crm_driver') === 'generic'),
                ]),
                  Section::make('Contratto')
           ->columnSpanFull()
            ->columns(4)
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
        ]);
    }
}
