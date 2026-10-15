<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Unico\Core\Models\Fornitore as CoreFornitore;
use Unico\Core\Signature\PhoneNumber;

/**
 * Anagrafica degli agenti / collaboratori (tabella `fornitoris` su unico-core).
 */
class Fornitore extends CoreFornitore
{
    use BelongsToCompany;

    protected $guarded = [];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'issubfornitore' => 'boolean',
        ]);
    }

    /**
     * La sigla del produttore: quella inserita oppure, se manca, le iniziali del nome (o le prime tre lettere
     * se il nome è una parola sola). Senza nome, «SEG» (segnalatore). Una volta ricavata viene salvata.
     */
    public function ensureSigla(): string
    {
        if (filled($this->sigla)) {
            return $this->sigla;
        }

        $name = Str::upper(Str::ascii((string) $this->display_name));
        $words = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $sigla = match (true) {
            $words === [] => 'SEG',
            count($words) === 1 => substr($words[0], 0, 3),
            default => implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 3))),
        };

        $this->forceFill(['sigla' => $sigla])->saveQuietly();

        return $sigla;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, static::companyColumn());
    }

    /** Agenti attualmente convenzionati (esclude cessati e cancellati). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Denominazione, oppure il nome del referente se manca. */
    public function getDisplayNameAttribute(): ?string
    {
        return filled($this->name) ? $this->name : $this->nome;
    }

    /** L'agente attivo il cui telefono coincide con il numero WhatsApp, in qualunque formato siano scritti. */
    public static function findByWhatsApp(string $waNumber): ?self
    {
        $wanted = self::nationalNumber($waNumber);
        if ($wanted === '') {
            return null;
        }

        return static::active()->whereNotNull('tel')->get()
            ->first(fn (self $f) => self::nationalNumber($f->tel) === $wanted);
    }

    public const OCCASIONAL_TYPE = 'Segnalatore occasionale';

    /** Chi scrive è un produttore convenzionato (attivo)? */
    public static function isProducer(string $waNumber): bool
    {
        return self::findByWhatsApp($waNumber) !== null;
    }

    /**
     * Registra un numero sconosciuto come segnalatore occasionale (non attivo), una volta sola.
     * Un numero già presente, anche non attivo, non si duplica.
     */
    public static function registerOccasional(string $waNumber): self
    {
        $wanted = self::nationalNumber($waNumber);
        $existing = static::withTrashed()->whereNotNull('tel')->get()->first(fn (self $f) => self::nationalNumber($f->tel) === $wanted);

        return $existing ?? static::create(['tel' => $waNumber, 'type' => self::OCCASIONAL_TYPE, 'is_active' => false]);
    }

    /** Solo cifre, senza prefisso internazionale italiano (+39 / 0039). */
    public static function nationalNumber(string $phone): string
    {
        return PhoneNumber::national($phone);
    }
}
