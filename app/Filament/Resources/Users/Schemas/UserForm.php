<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\Phone;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required(),
            TextInput::make('email')->label('Email')->email()->required()->unique(ignoreRecord: true),
            TextInput::make('whatsapp_number')->label('Numero WhatsApp')->tel()->maxLength(30)->nullable()
                ->helperText('Se compilato, scrivendo al bot da questo numero si vedono nel menu anche le voci di prova dei percorsi.')
                ->rule(fn (?Model $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record) {
                    $taken = User::whereNotNull('whatsapp_number')->when($record, fn ($q) => $q->where('id', '!=', $record->id))->pluck('whatsapp_number')
                        ->contains(fn ($n) => Phone::same($n, (string) $value));
                    if ($value && $taken) {
                        $fail('Questo numero è già associato a un altro utente.');
                    }
                }),
            TextInput::make('password')->label('Password')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->minLength(10),
        ]);
    }
}
