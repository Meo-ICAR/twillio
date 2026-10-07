<?php

namespace App\Filament\Resources\LoanRequests\Pages;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLoanRequest extends EditRecord
{
    protected static string $resource = LoanRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
