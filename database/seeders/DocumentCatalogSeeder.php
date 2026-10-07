<?php

namespace Database\Seeders;

use App\Models\FinanziamentoDocument;
use Illuminate\Database\Seeder;

/**
 * Catalogo iniziale dei documenti per tipo di finanziamento. Sono valori di partenza da rivedere:
 * dopo il primo inserimento si modificano dal pannello e il seeder non li sovrascrive.
 */
class DocumentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $product => $documents) {
            foreach ($documents as $order => [$code, $name, $requirement, $description, $aiKind]) {
                FinanziamentoDocument::firstOrCreate(
                    ['product' => $product, 'code' => $code],
                    ['name' => $name, 'requirement' => $requirement, 'description' => $description, 'ai_kind' => $aiKind, 'sort_order' => $order + 1],
                );
            }
        }
    }

    /** @return array<string, list<array{0:string,1:string,2:string,3:string,4:?string}>> */
    private function catalog(): array
    {
        $identita = ['documento_identita', 'Documento d\'identità', 'obbligatorio', 'Carta d\'identità, passaporto o patente in corso di validità', 'identita'];
        $cf = ['codice_fiscale', 'Codice fiscale', 'obbligatorio', 'Tessera sanitaria o tessera del codice fiscale', 'codice_fiscale'];
        $reddito = ['reddito', 'Documento di reddito', 'obbligatorio', 'Busta paga, cedolino della pensione, CUD o dichiarazione', 'reddito'];
        $estratto = ['estratto_conto', 'Estratto conto bancario', 'facoltativo', 'Ultimo estratto conto: serve a verificare l\'IBAN e gli accrediti', null];
        $integrativi = [
            ['contratto_lavoro', 'Contratto di lavoro', 'integrativo', 'Contratto o lettera di assunzione, per approfondire la posizione lavorativa', null],
            ['dichiarazione_redditi', 'Dichiarazione redditi', 'integrativo', 'Modello 730 o Redditi PF dell\'ultimo anno', null],
            ['altro_integrativo', 'Altro documento', 'integrativo', 'Altro documento richiesto dall\'istruttore', null],
        ];
        $azienda = [
            $identita, $cf,
            ['visura_camerale', 'Visura camerale', 'obbligatorio', 'Visura camerale aggiornata dell\'azienda', null],
            ['bilancio', 'Ultimo bilancio', 'obbligatorio', 'Ultimo bilancio depositato o dichiarazione dei redditi', null],
            ['dichiarazione_iva', 'Dichiarazione IVA', 'facoltativo', 'Ultima dichiarazione IVA', null],
            $estratto,
            ['altro_integrativo', 'Altro documento', 'integrativo', 'Altro documento richiesto dall\'istruttore', null],
            ['perizia_bene', 'Perizia del bene', 'integrativo', 'Perizia o preventivo del bene finanziato', null],
        ];

        return [
            'personale' => [$identita, $cf, $reddito, $estratto, ...$integrativi],
            'finalizzato' => [$identita, $cf, $reddito, ['preventivo_bene', 'Preventivo del bene', 'obbligatorio', 'Preventivo o proposta di acquisto del bene', null], $estratto, ...$integrativi],
            'quinto' => [
                $identita, $cf, $reddito,
                ['certificato_stipendio', 'Certificato di stipendio', 'obbligatorio', 'Certificato di stipendio o di servizio rilasciato dal datore di lavoro', null],
                $estratto, ...$integrativi,
            ],
            'mutuo' => [
                $identita, $cf, $reddito,
                ['dichiarazione_redditi', 'Dichiarazione redditi', 'obbligatorio', 'Modello 730 o Redditi PF dell\'ultimo anno', null],
                ['compromesso', 'Preliminare di acquisto', 'facoltativo', 'Compromesso o proposta di acquisto dell\'immobile', null],
                ['visura_catastale', 'Visura catastale', 'facoltativo', 'Visura catastale dell\'immobile', null],
                $estratto,
                ['perizia_immobile', 'Perizia immobile', 'integrativo', 'Perizia di stima dell\'immobile', null],
                ['atto_provenienza', 'Atto di provenienza', 'integrativo', 'Atto di provenienza dell\'immobile', null],
                ['altro_integrativo', 'Altro documento', 'integrativo', 'Altro documento richiesto dall\'istruttore', null],
            ],
            'leasing' => $azienda,
            'aziendale' => $azienda,
        ];
    }
}
