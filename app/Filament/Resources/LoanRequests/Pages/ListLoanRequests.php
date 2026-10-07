<?php

namespace App\Filament\Resources\LoanRequests\Pages;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoanRequests extends ListRecords
{
    protected static string $resource = LoanRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
