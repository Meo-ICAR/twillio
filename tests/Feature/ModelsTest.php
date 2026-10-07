<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_i_dati_personali_sono_cifrati_a_riposo(): void
    {
        $loan = LoanRequest::create([
            'code' => 'FIN-2026-0001',
            'agent_wa_number' => '393331112222',
            'product' => 'mutuo',
            'answers' => ['prodotto' => 'mutuo'],
            'personal' => ['nome' => 'Mario'],
        ]);

        $this->assertSame(['nome' => 'Mario'], $loan->fresh()->personal);
        $this->assertStringNotContainsString('Mario', DB::table('loan_requests')->value('personal'));
        $this->assertSame('richiesta', $loan->fresh()->status);
    }

    public function test_la_conversazione_cifra_i_dati_e_collega_la_pratica(): void
    {
        $loan = LoanRequest::create([
            'code' => 'FIN-2026-0002', 'agent_wa_number' => '39333', 'product' => 'quinto', 'answers' => [],
        ]);
        $conv = Conversation::create([
            'wa_number' => '39333', 'flow' => 'perfezionamento', 'node' => 'nome',
            'loan_request_id' => $loan->id, 'data' => ['cognome' => 'Rossi'], 'history' => ['codice'],
        ]);

        $this->assertSame('attiva', $conv->fresh()->status);
        $this->assertSame(['cognome' => 'Rossi'], $conv->fresh()->data);
        $this->assertStringNotContainsString('Rossi', DB::table('conversations')->value('data'));
        $this->assertTrue($conv->loanRequest->is($loan));
    }
}
