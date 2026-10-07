<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /** Niente eliminazione: senza azienda l'informativa tornerebbe ai segnaposto. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
