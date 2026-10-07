<?php

namespace App\Filament\Resources\Flows\Pages;

use App\Filament\Resources\Flows\FlowResource;
use Filament\Resources\Pages\EditRecord;

class EditFlow extends EditRecord
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
