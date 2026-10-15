<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Unico\Core\Signature\PhoneNumber;

#[Fillable(['name', 'email', 'password', 'whatsapp_number'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use BelongsToCompany;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Il numero WhatsApp è associato a un utente del pannello: può provare le versioni di prova dei percorsi. */
    public static function hasTesterNumber(string $waNumber): bool
    {
        return static::whereNotNull('whatsapp_number')->pluck('whatsapp_number')
            ->contains(fn (string $number) => PhoneNumber::same($number, $waNumber));
    }

    /** Gli utenti si creano solo da un amministratore (nessuna registrazione pubblica). */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
