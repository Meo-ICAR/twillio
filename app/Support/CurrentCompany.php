<?php

namespace App\Support;

use App\Models\Company;
use Filament\Tables\Filters\SelectFilter;

/** La company scelta nello switch del pannello (sessione); null = «Tutte». Serve solo da default dei filtri delle tabelle. */
class CurrentCompany
{
    private const KEY = 'current_company_id';

    public static function id(): ?int
    {
        $id = session(self::KEY);

        return $id !== null && Company::whereKey($id)->exists() ? (int) $id : null;
    }

    public static function set(?int $id): void
    {
        session([self::KEY => $id]);
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('company_id')->label('Azienda')
            ->options(fn () => Company::orderBy('name')->pluck('name', 'id')->all())
            ->default(fn () => self::id());
    }
}
