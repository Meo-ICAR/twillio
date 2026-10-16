<?php

namespace Tests\Feature\Core;

use App\Filament\Resources\LoanRequests\LoanRequestResource;
use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Filament\Resources\LoanRequests\RelationManagers\CoreDocumentsRelationManager;
use App\Models\User;
use Livewire\Livewire;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Filament\RelationManagers\DocumentsRelationManager;
use Unico\Core\Models\Document;

class LoanRequestCoreDocumentsTabTest extends CoreTestCase
{
    public function test_the_core_documents_tab_is_the_first_relation_of_the_loan_request(): void
    {
        $this->assertSame(CoreDocumentsRelationManager::class, LoanRequestResource::getRelations()[0]);
        $this->assertTrue(is_subclass_of(CoreDocumentsRelationManager::class, DocumentsRelationManager::class));
    }

    public function test_the_core_documents_tab_lists_the_documents_of_the_loan_request_with_the_icon_columns(): void
    {
        $loan = $this->makeLoan();
        $this->actingAs(User::factory()->create());
        $documento = Document::create([
            'documentable_type' => 'loan_request', 'documentable_id' => (string) $loan->id, 'name' => 'Informativa privacy', 'status' => DocumentStatus::UPLOADED,
        ]);

        Livewire::test(CoreDocumentsRelationManager::class, ['ownerRecord' => $loan, 'pageClass' => ViewLoanRequest::class])
            ->assertCanSeeTableRecords([$documento])
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('controlli')
            ->assertTableColumnExists('scadenza')
            ->assertSee('Informativa privacy');
    }
}
