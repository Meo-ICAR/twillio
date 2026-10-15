<?php

namespace Tests\Feature\Core;

use App\Models\LoanRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Unico\Core\Models\Document;

class CoreHarnessTest extends CoreTestCase
{
    public function test_un_documento_del_core_si_aggancia_alla_pratica_con_l_alias_morph(): void
    {
        $loan = $this->makeLoan();
        $module = $this->coreModule();

        $doc = Document::create(['documentable_type' => 'loan_request', 'documentable_id' => $loan->id, 'document_type_id' => $module->document_type_id, 'name' => 'Prova']);

        $this->assertSame(LoanRequest::class, Relation::getMorphedModel('loan_request'));
        $this->assertTrue($doc->fresh()->documentable->is($loan));
    }

    public function test_il_modulo_di_prova_si_compila_con_pdftk(): void
    {
        $pdf = app(\Unico\Core\Pdf\ModulePrinter::class)->render($this->coreModule(), new \Unico\Core\Pdf\ResolvedModuleData([]));

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_un_file_si_salva_sul_documento_con_media_library(): void
    {
        $module = $this->coreModule();
        $doc = Document::create(['documentable_type' => 'loan_request', 'documentable_id' => $this->makeLoan()->id, 'document_type_id' => $module->document_type_id, 'name' => 'Prova']);

        $doc->addMediaFromString($this->fixturePdf())->usingFileName('prova.pdf')->toMediaCollection(Document::COLLECTION);

        $this->assertSame('application/pdf', $doc->getFirstMedia(Document::COLLECTION)->mime_type);
    }
}
