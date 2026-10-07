<?php

namespace App\Filament\Resources\LoanRequests\Pages;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLoanRequest extends ViewRecord
{
    protected static string $resource = LoanRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
