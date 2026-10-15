<?php

namespace App\Filament\Resources\LoanRequests\Pages;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use App\Models\Company;
use App\Services\Conversation\ConversationEngine;
use App\Services\Documents\DocumentProductionException;
use App\Services\Documents\LoanDocumentProducer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewLoanRequest extends ViewRecord
{
    protected static string $resource = LoanRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('reinviaFirma')->label('Reinvia per la firma')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                ->visible(fn () => in_array($this->record->signature_status, ['errore', 'scaduta', 'rifiutata'], true)
                    && Company::forWhatsApp($this->record->agent_wa_number)?->producesDocuments())
                ->action(function () {
                    try {
                        app(LoanDocumentProducer::class)->produce($this->record, Company::forWhatsApp($this->record->agent_wa_number), ConversationEngine::SIGN_STATE);
                        Notification::make()->success()->title('Documenti reinviati per la firma')->send();
                    } catch (DocumentProductionException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
        ];
    }
}
