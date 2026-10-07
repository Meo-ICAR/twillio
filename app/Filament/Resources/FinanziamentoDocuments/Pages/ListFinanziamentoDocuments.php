<?php

namespace App\Filament\Resources\FinanziamentoDocuments\Pages;

use App\Filament\Resources\FinanziamentoDocuments\FinanziamentoDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinanziamentoDocuments extends ListRecords
{
    protected static string $resource = FinanziamentoDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
