<?php

namespace App\Services\Conversation;

use App\Models\Company;
use App\Models\Fornitore;
use Illuminate\Support\Facades\Cache;

/** Installazione demo: chi scrive sceglie se provare il finanziamento o la fornitura di energia, cioè con quale company lavorare. */
class DemoMode
{
    public const FINANZIAMENTO = 'demo_finanziamento';

    public const FORNITURA = 'demo_fornitura';

    public const CHANGE = 'demo_cambia';

    public function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    /** La demo è attiva e questo numero non ha ancora scelto. */
    public function needsChoice(string $wa): bool
    {
        return $this->enabled() && ! Cache::has($this->key($wa));
    }

    public function isChoice(IncomingMessage $m): bool
    {
        return $this->enabled() && $m->type === 'interactive' && in_array($m->replyId, [self::FINANZIAMENTO, self::FORNITURA], true);
    }

    /** Avviso di demo e scelta tra finanziamento e fornitura. */
    public function prompt(): array
    {
        return [
            Reply::text((string) config('demo.notice')),
            Reply::choice('Cosa vuoi provare?', [self::FINANZIAMENTO => 'Finanziamento', self::FORNITURA => 'Fornitura energia']),
        ];
    }

    /** @return Reply[] */
    public function choose(string $wa, string $choice): array
    {
        $company = $choice === self::FORNITURA
            ? Company::where('type', 'ENERGY')->oldest('id')->first()
            : Company::where(fn ($q) => $q->where('type', '!=', 'ENERGY')->orWhereNull('type'))->oldest('id')->first();

        if (! $company) {
            return [Reply::text('Questa demo non ha una company configurata per la scelta fatta.'), ...$this->prompt()];
        }

        Fornitore::assignToCompany($wa, $company->id);
        Cache::forever($this->key($wa), $company->id);

        return [Reply::text("✅ Demo: stai provando *{$company->name}*. Per cambiare scrivi /demo.")];
    }

    private function key(string $wa): string
    {
        return 'demo.company.'.Fornitore::nationalNumber($wa);
    }
}
