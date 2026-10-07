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

    public function test_describe_usa_le_etichette_del_percorso_e_ignora_le_chiavi_interne(): void
    {
        $readable = LoanRequest::describe([
            'codice_fiscale' => 'RSSMRA80A01H501U', 'data_nascita' => '01/01/1980', 'sesso' => 'M',
            '_difformita' => ['x'], 'stato_civile' => 'celibe',
        ], 'perfezionamento');

        $this->assertSame(
            ['Codice fiscale' => 'RSSMRA80A01H501U', 'Data di nascita' => '01/01/1980', 'Sesso' => 'M', 'Stato civile' => 'Celibe/Nubile'],
            $readable
        );
    }
}
