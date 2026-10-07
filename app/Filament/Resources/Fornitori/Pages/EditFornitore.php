<?php

namespace App\Filament\Resources\Fornitori\Pages;

use App\Filament\Resources\Fornitori\FornitoreResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFornitore extends EditRecord
{
    protected static string $resource = FornitoreResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
