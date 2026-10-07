<?php

namespace App\Filament\Resources\Flows\Pages;

use App\Filament\Resources\Flows\FlowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlows extends ListRecords
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
