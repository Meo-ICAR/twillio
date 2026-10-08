<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Azienda cliente: è il Titolare del trattamento indicato nell'informativa privacy. */
class Company extends Model
{
    /** Settori in cui opera il servizio. */
    public const TYPES = ['FINANCE' => 'Finance', 'CALL CENTER' => 'Call center', 'HOTEL' => 'Hotel'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_trial' => 'boolean',
            'preventivatore_passkey' => 'encrypted',
            'trialend_at' => 'date',
            'trial_activated_at' => 'date',
            'activated_at' => 'date',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'company_products')->withTimestamps();
    }

    /** La prova è ancora in corso (attiva e non scaduta). */
    public function isTrialActive(): bool
    {
        return $this->is_trial && ($this->trialend_at === null || ! $this->trialend_at->copy()->endOfDay()->isPast());
    }

    /** Esiste un CRM per la fase di preventivazione (altrimenti si manda una email all'istruttoria). */
    public function hasQuoteCrm(): bool
    {
        return filled($this->url_preventivatore);
    }

    /** Esiste un CRM per l'istruttoria (altrimenti si manda una email con dati e allegati). */
    public function hasSubmissionCrm(): bool
    {
        return filled($this->url_istruttoria);
    }

    /** La company del produttore con quel numero WhatsApp; se non è assegnata (o è sconosciuto) vale quella attuale. */
    public static function forWhatsApp(?string $waNumber): ?self
    {
        $company = filled($waNumber) ? Fornitore::findByWhatsApp($waNumber)?->company : null;

        return $company ?? static::current();
    }

    /** L'installazione serve una sola azienda: vale la prima. */
    public static function current(): ?self
    {
        return static::oldest('id')->first();
    }
}
