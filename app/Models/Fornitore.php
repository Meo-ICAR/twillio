<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anagrafica degli agenti / collaboratori (tabella `fornitoris`).
 *
 * `company_id` (UUID) e `fornitorirole_id` puntano a tabelle dell'anagrafica madre che qui non ci sono:
 * non si definisce quindi la relazione con {@see Company}, che è il Titolare del trattamento (id numerico).
 */
class Fornitore extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fornitoris';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'stipulated_at' => 'date',
            'oam_at' => 'date',
            'oam_dismissed_at' => 'date',
            'ivass_at' => 'date',
            'dismissed_at' => 'date',
            'available_at' => 'date',
            'natoil' => 'date',
            'contributodalmese' => 'date',
            'is_active' => 'boolean',
            'is_art108' => 'boolean',
            'iscollaboratore' => 'boolean',
            'isdipendente' => 'boolean',
            'issubfornitore' => 'boolean',
            'welcome_bonus' => 'decimal:2',
            'budget' => 'decimal:2',
            'anticipo' => 'decimal:2',
            'anticipo_residuo' => 'decimal:2',
            'contributo' => 'decimal:2',
            'employee_roles' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return Phone::national($phone);
    }
}
