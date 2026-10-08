# Lead al CRM e archiviazione su SharePoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pratica perfezionata, caricare il lead sul CRM Mediafacile (GET, senza documenti) e archiviare in background i documenti su SharePoint tramite una funzione di caricamento finta.

**Architecture:** `MediafacileLeadGateway` implementa l'interfaccia `CrmGateway` già usata da `completePerfezionamento`; `LeadParameters` costruisce i parametri dai dati della pratica. Un job `ArchiveLoanDocuments` in coda cicla gli allegati e li passa a `SharePointUploader` (oggi `LoggingSharePointUploader`, da sostituire con le routine reali). Una nuova domanda nel perfezionamento raccoglie la provincia.

**Tech Stack:** Laravel 13, Eloquent, Http client, SimpleXML, code Laravel, PHPUnit (SQLite in memoria), Filament.

**Spec:** `docs/superpowers/specs/2026-10-08-lead-crm-sharepoint-design.md`

## Global Constraints
- Nessun documento va al CRM: il parametro `file` non si invia mai.
- GET con i parametri nella query: `Passkey`, `cognome`, `nome`, `data_nascita` (`MM-GG-ANNO`), `tipologia`, `importo_richiesto` (decimali con la virgola), `residenza_citta`, `residenza_provincia`, `cellulare`, `email`, `fonte`, `annotazioni`.
- `fonte` = `unicoagent` (fisso, `finanziamento.lead.fonte`).
- `Stato` che comincia con `OK` → il gateway restituisce 200 e salva `IDUU` in `loan_requests.crm_lead_id`; ogni altro esito (KO, XML non valido, HTTP non 2xx, timeout, passkey o URL mancanti) → un codice diverso da 200.
- Nei log solo il tipo di errore: mai dati personali, mai il contenuto dei file.
- `annotazioni`: mai IBAN, numero documento o codice fiscale.
- Valori di `tipologia`: Statale, Pubblico, Privato, Privato altra forma, Privato small business, Medico, Pensionato INPS, Pensionato altri enti, Postale, Ferroviere, Parapubblico.
- Le pratiche di prova (`is_test`) non si archiviano. Un errore dell'archiviazione non blocca né annulla il perfezionamento.
- Titolo di un'opzione WhatsApp: massimo 24 caratteri.
- Commit direttamente su `main` (nessun branch), con `git -c core.fileMode=false`; ogni commit termina con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Nella suite restano 3 fallimenti preesistenti in `FilamentAdminTest` (produttori): non vanno corretti qui, ma vanno nominati nel report.

## Review Focus
1. Indirizzo di residenza senza virgole (es. «Via Roma 1»): la città è l'intero testo; con virgole è l'ultimo segmento → Task 3.
2. Data di nascita non valida o assente: il parametro si omette, nessuna eccezione → Task 3.
3. Provincia scritta per esteso o minuscola («Milano», «mi»): la prima si rifiuta e si richiede, la seconda diventa `MI` → Task 2.
4. Risposta `KO - …`, XML non valido, HTTP 500, timeout: il perfezionamento non avviene e l'agente può riprovare; `crm_lead_id` resta vuoto → Task 3.
5. Passkey o URL mancanti con driver `mediafacile`: nessuna chiamata, esito diverso da 200 → Task 3.
6. Allegato rifiutato, file mancante sul disco o pratica di prova: non si carica; un caricamento che lancia un'eccezione non lascia `documents_archived_at` valorizzato → Task 4.
7. In produzione serve un worker di coda (`php artisan queue:work`, `QUEUE_CONNECTION=database`): senza, l'archiviazione non parte → ricordarlo nel report finale.

---

### Task 1: Colonne, mappatura `tipologia` e chiave del CRM

**Files:**
- Create: `database/migrations/2026_10_08_000004_add_lead_and_archive_columns.php`
- Modify: `database/seeders/QuoteCatalogSeeder.php`, `app/Models/Company.php`, `app/Models/LoanRequest.php`, `app/Filament/Resources/Companies/Schemas/CompanyForm.php`, `app/Models/QuoteEmploymentMap.php`
- Test: `tests/Feature/QuoteCatalogTest.php` (aggiunta), `tests/Feature/LeadColumnsTest.php`

**Interfaces:**
- Produces (usati dal Task 3 e 4): `companies.istruttoria_passkey` (cifrata, `Company::$istruttoria_passkey`); `loan_requests.crm_lead_id` (string, nullable); `loan_requests.documents_archived_at` (datetime, nullable); `quote_employment_map.lead_tipologia`; `QuoteEmploymentMap::resolve(array $answers): ?QuoteEmploymentMap` (riga più specifica per le risposte `lavoro`, `ente_pensione`, `dimensione_azienda`).

- [ ] **Step 1: Scrivere i test che falliscono**

In `tests/Feature/QuoteCatalogTest.php` aggiungere:

```php
    public function test_la_mappatura_ha_anche_il_valore_per_il_lead(): void
    {
        $this->assertSame('Privato', QuoteEmploymentMap::where('tipo_rapporto', 'Privato SPA')->value('lead_tipologia'));
        $this->assertSame('Privato altra forma', QuoteEmploymentMap::where('tipo_rapporto', 'Privato Altra forma')->value('lead_tipologia'));
        $this->assertSame('Pensionato altri enti', QuoteEmploymentMap::where('tipo_rapporto', 'Pensionato INPDAP')->value('lead_tipologia'));
        $this->assertSame('Pensionato INPS', QuoteEmploymentMap::where('tipo_rapporto', 'Pensionato INPS')->value('lead_tipologia'));
    }

    public function test_la_riga_piu_specifica_vince(): void
    {
        $this->assertSame('Privato SPA', QuoteEmploymentMap::resolve(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'oltre15'])->tipo_rapporto);
        $this->assertSame('Privato Altra forma', QuoteEmploymentMap::resolve(['lavoro' => 'dip_priv'])->tipo_rapporto);
        $this->assertSame('Pensionato INPDAP', QuoteEmploymentMap::resolve(['lavoro' => 'pensionato', 'ente_pensione' => 'exinpdap'])->tipo_rapporto);
        $this->assertNull(QuoteEmploymentMap::resolve(['lavoro' => 'sconosciuto']));
    }
```

`tests/Feature/LeadColumnsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_passkey_del_crm_si_salva_cifrata(): void
    {
        $company = Company::create(['name' => 'H', 'istruttoria_passkey' => 'SEGRETA']);

        $this->assertSame('SEGRETA', $company->fresh()->istruttoria_passkey);
        $this->assertNotSame('SEGRETA', DB::table('companies')->where('id', $company->id)->value('istruttoria_passkey'));
    }

    public function test_la_pratica_ricorda_il_lead_e_l_archiviazione(): void
    {
        $loan = LoanRequest::create(['code' => 'FIN-2026-0001', 'agent_wa_number' => '39333', 'product' => 'personale', 'status' => 'richiesta', 'answers' => []]);
        $loan->update(['crm_lead_id' => 'ABC-1', 'documents_archived_at' => now()]);

        $this->assertSame('ABC-1', $loan->fresh()->crm_lead_id);
        $this->assertNotNull($loan->fresh()->documents_archived_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $loan->fresh()->documents_archived_at);
    }
}
```

- [ ] **Step 2: Verificare che falliscano**

Run: `php artisan test --compact --filter='QuoteCatalogTest|LeadColumnsTest'`
Expected: FAIL (colonne e `resolve` assenti).

- [ ] **Step 3: Migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tipo_rapporto del preventivatore → `tipologia` del servizio di caricamento lead. */
    private const LEAD = [
        'Pubblico' => 'Pubblico', 'Privato Altra forma' => 'Privato altra forma', 'Privato SPA' => 'Privato',
        'Privato Small Business' => 'Privato small business', 'Pensionato INPS' => 'Pensionato INPS',
        'Pensionato INPDAP' => 'Pensionato altri enti', 'Pensionato altri enti' => 'Pensionato altri enti',
    ];

    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->text('istruttoria_passkey')->nullable()->after('url_istruttoria'));

        Schema::table('loan_requests', function (Blueprint $t) {
            $t->string('crm_lead_id')->nullable();
            $t->timestamp('documents_archived_at')->nullable();
        });

        Schema::table('quote_employment_map', fn (Blueprint $t) => $t->string('lead_tipologia')->nullable()->after('tipo_rapporto'));
        foreach (self::LEAD as $rapporto => $lead) {
            DB::table('quote_employment_map')->where('tipo_rapporto', $rapporto)->update(['lead_tipologia' => $lead]);
        }
    }

    public function down(): void
    {
        Schema::table('quote_employment_map', fn (Blueprint $t) => $t->dropColumn('lead_tipologia'));
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn(['crm_lead_id', 'documents_archived_at']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('istruttoria_passkey'));
    }
};
```

- [ ] **Step 4: Seeder, modelli e form**

In `QuoteCatalogSeeder`: aggiungere la costante e, nel ciclo che crea le righe di `QuoteEmploymentMap`, il valore solo se la colonna esiste (il seeder gira anche dalla migrazione `…000001`, prima che la colonna ci sia):

```php
    private const LEAD = [
        'Pubblico' => 'Pubblico', 'Privato Altra forma' => 'Privato altra forma', 'Privato SPA' => 'Privato',
        'Privato Small Business' => 'Privato small business', 'Pensionato INPS' => 'Pensionato INPS',
        'Pensionato INPDAP' => 'Pensionato altri enti', 'Pensionato altri enti' => 'Pensionato altri enti',
    ];
```

e sostituire la riga `QuoteEmploymentMap::create([...]);` con:

```php
            $row = ['lavoro' => $lavoro, 'ente_pensione' => $ente, 'dimensione_azienda' => $dimensione, 'tipo_rapporto' => $rapporto, 'priority' => $priority];
            if (Schema::hasColumn('quote_employment_map', 'lead_tipologia')) {
                $row['lead_tipologia'] = self::LEAD[$rapporto];
            }
            QuoteEmploymentMap::create($row);
```

con `use Illuminate\Support\Facades\Schema;`.

`QuoteEmploymentMap` (aggiungere il metodo):

```php
    /** La riga più specifica per le risposte del produttore (lavoro, ente pensione, dimensione azienda), o null. */
    public static function resolve(array $answers): ?self
    {
        return static::where('lavoro', $answers['lavoro'] ?? '')
            ->where(fn ($q) => $q->whereNull('ente_pensione')->orWhere('ente_pensione', $answers['ente_pensione'] ?? ''))
            ->where(fn ($q) => $q->whereNull('dimensione_azienda')->orWhere('dimensione_azienda', $answers['dimensione_azienda'] ?? ''))
            ->orderByDesc('priority')->first();
    }
```

`Company::casts()`: aggiungere `'istruttoria_passkey' => 'encrypted',`. `LoanRequest::casts()`: aggiungere `'documents_archived_at' => 'datetime',`.

`CompanyForm`, subito dopo il campo `url_istruttoria`:

```php
                    TextInput::make('istruttoria_passkey')->label('Passkey dell\'istruttoria (CRM)')->password()->revealable()->maxLength(255)
                        ->helperText('Fornita con il servizio di caricamento lead; serve solo se il CRM è quello Mediafacile.'),
```

- [ ] **Step 5: Verificare che passino**

Run: `php artisan test --compact --filter='QuoteCatalogTest|LeadColumnsTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: colonne per il lead al CRM e mappatura della tipologia

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Domanda sulla provincia di residenza

**Files:**
- Modify: `config/finanziamento.php` (nodo `residenza`, nuovo nodo `residenza_provincia`)
- Create: `database/migrations/2026_10_08_000005_add_residenza_provincia_to_perfezionamento.php`
- Modify (test esistenti): `tests/Feature/ContattiClienteTest.php`, `Finanziamento/PerfezionamentoFlowTest.php`, `Finanziamento/InvioCrmTest.php`, `Finanziamento/DocumentiPrimaTest.php`
- Test: `tests/Feature/Finanziamento/ResidenzaProvinciaTest.php`, `tests/Feature/Flows/ResidenzaProvinciaMigrationTest.php`

**Interfaces:**
- Produces: chiave `residenza_provincia` nei dati del perfezionamento (sigla maiuscola di 2 lettere), letta dal Task 3.

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/Finanziamento/ResidenzaProvinciaTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;

class ResidenzaProvinciaTest extends ConversationTestCase
{
    private function afterResidenza(): array
    {
        Company::create(['name' => 'H']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);

        return $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 20100, Milano');
    }

    public function test_dopo_la_residenza_si_chiede_la_provincia(): void
    {
        $replies = $this->afterResidenza();

        $this->assertStringContainsString('Provincia di residenza', $this->bodies($replies));
    }

    public function test_una_provincia_per_esteso_si_rifiuta_e_si_richiede(): void
    {
        $this->afterResidenza();

        $replies = $this->say('Milano');

        $this->assertStringContainsString('sigla della provincia', $this->bodies($replies));
        $this->assertArrayNotHasKey('residenza_provincia', Conversation::first()->data);
    }

    public function test_la_sigla_minuscola_diventa_maiuscola_e_si_passa_allo_stato_civile(): void
    {
        $this->afterResidenza();

        $replies = $this->say('mi');

        $this->assertSame('MI', Conversation::first()->data['residenza_provincia']);
        $this->assertArrayHasKey('celibe', end($replies)->options);
    }
}
```

`tests/Feature/Flows/ResidenzaProvinciaMigrationTest.php` (stesso schema di `ContattoDirettoMigrationTest`):

```php
<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Models\FlowNode;
use App\Services\Flows\FlowCloner;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidenzaProvinciaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_08_000005_add_residenza_provincia_to_perfezionamento.php'))->up();
        app(FlowRepository::class)->forget();
    }

    /** Riporta l'albero com'era: senza il passo, con la residenza che va dritta allo stato civile. */
    private function age(Flow $flow): void
    {
        $flow->nodes()->where('code', 'residenza_provincia')->firstOrFail()->delete();
        $flow->nodes()->where('code', 'residenza')->firstOrFail()->jumps()->where('when_value', '*')->update(['go_to' => 'stato_civile']);
        $flow->nodes()->orderBy('sort_order')->pluck('code')->each(fn ($code, $i) => $flow->nodes()->where('code', $code)->update(['sort_order' => $i + 1]));
    }

    private function production(): Flow
    {
        return Flow::where('code', 'perfezionamento')->where('is_test', false)->firstOrFail();
    }

    public function test_un_albero_vecchio_diventa_uguale_alla_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowRepository::class)->forget();
        $this->assertNotEquals(config('finanziamento.flows.perfezionamento'), app(FlowRepository::class)->flow('perfezionamento'));

        $this->migrate();

        $this->assertEquals(config('finanziamento.flows.perfezionamento'), app(FlowRepository::class)->flow('perfezionamento'));
        $this->assertSame(array_keys(config('finanziamento.flows.perfezionamento.nodes')), array_keys(app(FlowRepository::class)->flow('perfezionamento')['nodes']));
    }

    public function test_vale_per_la_copia_di_prova_ed_e_ripetibile(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowCloner::class)->createTestCopy($this->production());

        $this->migrate();
        $this->migrate();

        $this->assertSame(2, FlowNode::where('code', 'residenza_provincia')->count(), 'uno per versione, nessun doppione');
    }

    public function test_un_salto_cambiato_a_mano_non_si_tocca(): void
    {
        $this->seed(FlowSeeder::class);
        $flow = $this->production();
        $this->age($flow);
        $flow->nodes()->where('code', 'residenza')->first()->jumps()->where('when_value', '*')->update(['go_to' => 'telefono']);

        $this->migrate();

        $this->assertSame('telefono', $flow->nodes()->where('code', 'residenza')->first()->jumps()->where('when_value', '*')->value('go_to'));
    }
}
```

Run: `php artisan test --compact --filter='ResidenzaProvincia'`
Expected: FAIL.

- [ ] **Step 2: Configurazione** — in `config/finanziamento.php`, flusso `perfezionamento`: il nodo `residenza` ora va a `residenza_provincia` e subito dopo si aggiunge il nuovo nodo:

```php
                'residenza' => $text('Residenza', 'Indirizzo di residenza (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'residenza_provincia'),
                'residenza_provincia' => $text('Provincia di residenza', 'Provincia di residenza (sigla, es. MI):', ['required', 'regex:/^[A-Za-z]{2}$/'], 'stato_civile', ['upper' => true, 'strip_spaces' => true, 'error' => 'Scrivi la sigla della provincia (due lettere, es. MI).']),
```

- [ ] **Step 3: Migrazione `2026_10_08_000005_add_residenza_provincia_to_perfezionamento.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const COLUMNS = ['type', 'label', 'prompt', 'options', 'next', 'next_by', 'save', 'skippable', 'can_modify', 'checks'];

    /**
     * Dopo l'indirizzo di residenza il bot chiede la provincia (sigla). Aggiunge il passo agli alberi già importati
     * (produzione e prove) senza toccare altre modifiche. Ripetibile.
     */
    public function up(): void
    {
        $node = config('finanziamento.flows.perfezionamento.nodes.residenza_provincia');
        if (! $node) {
            return;
        }

        foreach (DB::table('flows')->where('code', 'perfezionamento')->get() as $flow) {
            $residenza = DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'residenza')->first();
            if (! $residenza) {
                continue;
            }

            if (! DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'residenza_provincia')->exists()) {
                DB::table('flow_nodes')->where('flow_id', $flow->id)->where('sort_order', '>', $residenza->sort_order)->increment('sort_order');

                $params = array_diff_key($node, array_flip(self::COLUMNS));
                $id = DB::table('flow_nodes')->insertGetId([
                    'flow_id' => $flow->id, 'code' => 'residenza_provincia', 'type' => 'text', 'label' => $node['label'], 'prompt' => $node['prompt'],
                    'sort_order' => $residenza->sort_order + 1, 'skippable' => false, 'save' => true, 'jump_by' => 'answer',
                    'params' => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('flow_node_jumps')->insert(['flow_node_id' => $id, 'when_value' => '*', 'go_to' => $node['next'], 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }

            // La residenza porta al nuovo passo solo se andava ancora allo stato civile (se è stata cambiata a mano non si tocca).
            DB::table('flow_node_jumps')->where('flow_node_id', $residenza->id)->where('when_value', '*')->where('go_to', 'stato_civile')
                ->update(['go_to' => 'residenza_provincia', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Non si torna indietro: l'albero si ripristina dalla configurazione.
    }
};
```

Se `FlowNode` non salva `params` come JSON in questo modo (verificare con `grep -n "params" app/Models/FlowNode.php`), usare lo stesso formato del cast del modello: l'uguaglianza con la configurazione nel primo test lo dimostra.

- [ ] **Step 4: Aggiornare i test esistenti** — tra la residenza e lo stato civile ora c'è la provincia:

```bash
sed -i "s/'#celibe'/'MI', '#celibe'/" tests/Feature/ContattiClienteTest.php tests/Feature/Finanziamento/PerfezionamentoFlowTest.php tests/Feature/Finanziamento/InvioCrmTest.php tests/Feature/Finanziamento/DocumentiPrimaTest.php
```

Eseguire `php artisan test --compact --filter='ResidenzaProvincia|ContattiClienteTest|PerfezionamentoFlowTest|InvioCrmTest|DocumentiPrimaTest|FlowRepositoryTest|FlowValidatorTest|FlowConfigExporterTest'`. Se un test usava `'#celibe'` senza passare dalla residenza (per esempio come unica risposta di un passo già avanzato) ora fallisce: correggerlo togliendo il `'MI'` aggiunto in quella riga.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: domanda sulla provincia di residenza nel perfezionamento

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Parametri del lead e gateway Mediafacile

**Files:**
- Create: `app/Services/Crm/LeadParameters.php`, `app/Services/Crm/MediafacileLeadGateway.php`
- Modify: `app/Services/Loans/Mediafacile/ScenarioBuilder.php` (usa `QuoteEmploymentMap::resolve`), `config/finanziamento.php` (blocchi `crm.driver`, `lead`), `app/Providers/AppServiceProvider.php`, `app/Services/Conversation/ConversationEngine.php` (`completePerfezionamento`), `.env.example`
- Test: `tests/Feature/Crm/LeadParametersTest.php`, `tests/Feature/Crm/MediafacileLeadGatewayTest.php`, `tests/Feature/Finanziamento/InvioLeadMediafacileTest.php`

**Interfaces:**
- Consumes: `QuoteEmploymentMap::resolve` e `lead_tipologia` (Task 1), `residenza_provincia` (Task 2), `QuoteBandBound` (esistente).
- Produces: `LeadParameters::build(LoanRequest $loan, array $personal): array<string,string>` (senza `Passkey`, senza valori vuoti); `MediafacileLeadGateway implements CrmGateway` (`submit(): int`, 200 solo con `Stato` `OK…`, salva `crm_lead_id`).

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/Crm/LeadParametersTest.php`:

```php
<?php

namespace Tests\Feature\Crm;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Services\Crm\LeadParameters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class LeadParametersTest extends TestCase
{
    use RefreshDatabase;

    private function loan(array $answers = []): LoanRequest
    {
        return new LoanRequest([
            'code' => 'FIN-2026-0007', 'agent_wa_number' => '393331112222', 'product' => 'personale',
            'answers' => $answers + ['prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'lavoro' => 'dip_pub'],
        ]);
    }

    private function personal(array $override = []): array
    {
        return $override + [
            'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'residenza' => 'Via Roma 1, 20100, Milano',
            'residenza_provincia' => 'MI', 'telefono' => '+393331234567', 'email' => 'mario@example.com',
            'codice_fiscale' => 'RSSMRA80A01H501U', 'iban' => 'IT60X0542811101000000123456', 'documento_numero' => 'AB123456',
        ];
    }

    public function test_i_parametri_del_servizio(): void
    {
        Fornitore::create(['name' => 'Mario', 'tel' => '393331112222', 'sigla' => 'PM', 'is_active' => true]);

        $p = LeadParameters::build($this->loan(), $this->personal());

        $this->assertSame([
            'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01-01-1980', 'tipologia' => 'Pubblico',
            'importo_richiesto' => '10000,00', 'residenza_citta' => 'Milano', 'residenza_provincia' => 'MI',
            'cellulare' => '+393331234567', 'email' => 'mario@example.com', 'fonte' => 'unicoagent',
        ], Arr::except($p, 'annotazioni'));
        $this->assertArrayNotHasKey('file', $p);
        $this->assertArrayNotHasKey('Passkey', $p);
    }

    public function test_le_annotazioni_riassumono_la_pratica_senza_dati_sensibili(): void
    {
        Fornitore::create(['name' => 'Mario', 'tel' => '393331112222', 'sigla' => 'PM', 'is_active' => true]);

        $note = LeadParameters::build($this->loan(), $this->personal())['annotazioni'];

        foreach (['FIN-2026-0007', 'Prestito personale', '36 mesi', '5.000 - 10.000 €', 'PM'] as $part) {
            $this->assertStringContainsString($part, $note);
        }
        foreach (['IT60X0542811101000000123456', 'RSSMRA80A01H501U', 'AB123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $note);
        }
    }

    public function test_la_tipologia_segue_le_risposte(): void
    {
        $this->assertSame('Privato', LeadParameters::build($this->loan(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'oltre15']), $this->personal())['tipologia']);
        $this->assertSame('Pensionato INPS', LeadParameters::build($this->loan(['lavoro' => 'pensionato', 'ente_pensione' => 'inps']), $this->personal())['tipologia']);
        $this->assertSame('Pensionato altri enti', LeadParameters::build($this->loan(['lavoro' => 'pensionato', 'ente_pensione' => 'exinpdap']), $this->personal())['tipologia']);
    }

    public function test_la_citta_e_l_ultimo_segmento_oppure_tutto_il_testo(): void
    {
        $this->assertSame('Roma', LeadParameters::build($this->loan(), $this->personal(['residenza' => 'Via A 1, 00100 , Roma ']))['residenza_citta']);
        $this->assertSame('Via Roma 1', LeadParameters::build($this->loan(), $this->personal(['residenza' => 'Via Roma 1']))['residenza_citta']);
    }

    public function test_una_data_non_valida_o_assente_si_omette(): void
    {
        $this->assertArrayNotHasKey('data_nascita', LeadParameters::build($this->loan(), $this->personal(['data_nascita' => '31/02/1980'])));
        $this->assertArrayNotHasKey('data_nascita', LeadParameters::build($this->loan(), $this->personal(['data_nascita' => null])));
    }

    public function test_senza_fascia_di_importo_o_tipologia_i_parametri_si_omettono(): void
    {
        $p = LeadParameters::build($this->loan(['importo' => 'imp_vecchia', 'lavoro' => 'sconosciuto']), $this->personal());

        $this->assertArrayNotHasKey('importo_richiesto', $p);
        $this->assertArrayNotHasKey('tipologia', $p);
    }
}
```

`tests/Feature/Crm/MediafacileLeadGatewayTest.php`:

```php
<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Crm\CrmGateway;
use App\Services\Crm\MediafacileLeadGateway;
use App\Services\Crm\SimulatedCrmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileLeadGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0007', 'agent_wa_number' => '393331112222', 'product' => 'personale', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'lavoro' => 'dip_pub'],
        ]);
    }

    private function personal(): array
    {
        return ['cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'residenza' => 'Via Roma 1, Milano', 'residenza_provincia' => 'MI', 'telefono' => '+393331234567', 'email' => 'mario@example.com'];
    }

    private function company(array $override = []): Company
    {
        return Company::create($override + ['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead', 'istruttoria_passkey' => 'KEY']);
    }

    private function submit(?LoanRequest $loan = null): int
    {
        return (new MediafacileLeadGateway)->submit($loan ?? $this->loan(), $this->personal());
    }

    public function test_il_driver_si_sceglie_dalla_configurazione(): void
    {
        $this->assertInstanceOf(SimulatedCrmGateway::class, app(CrmGateway::class));

        config(['finanziamento.crm.driver' => 'mediafacile']);
        $this->assertInstanceOf(MediafacileLeadGateway::class, app(CrmGateway::class));
    }

    public function test_ok_da_200_e_salva_l_id_del_lead(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::response('<Risposta><Stato>OK</Stato><IDUU>LEAD-77</IDUU></Risposta>')]);
        $loan = $this->loan();

        $this->assertSame(200, $this->submit($loan));
        $this->assertSame('LEAD-77', $loan->fresh()->crm_lead_id);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $r->method() === 'GET'
                && str_starts_with($r->url(), 'https://crm.example.com/ws/lead?')
                && $q['Passkey'] === 'KEY' && $q['cognome'] === 'Rossi' && $q['fonte'] === 'unicoagent'
                && ! array_key_exists('file', $q);
        });
    }

    public function test_ko_non_e_un_successo_e_non_salva_l_id(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::response('<Risposta><Stato>KO - email non valida</Stato><IDUU>LEAD-78</IDUU></Risposta>')]);
        $loan = $this->loan();

        $this->assertNotSame(200, $this->submit($loan));
        $this->assertNull($loan->fresh()->crm_lead_id);
    }

    public function test_xml_non_valido_http_500_e_timeout_non_sono_successi(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()->push('non è xml')->push('boom', 500)]);
        $this->assertNotSame(200, $this->submit());
        $this->assertNotSame(200, $this->submit());

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->assertNotSame(200, $this->submit());
    }

    public function test_senza_url_o_passkey_non_si_chiama_il_servizio(): void
    {
        Http::fake();
        $this->company(['istruttoria_passkey' => null]);

        $this->assertNotSame(200, $this->submit());
        Http::assertNothingSent();
    }
}
```

`tests/Feature/Finanziamento/InvioLeadMediafacileTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Http;

class InvioLeadMediafacileTest extends ConversationTestCase
{
    private function atSummary(): LoanRequest
    {
        config(['finanziamento.crm.driver' => 'mediafacile']);
        Company::create(['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead', 'istruttoria_passkey' => 'KEY']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_5k', 'durata' => 'm24', 'lavoro' => 'dip_priv']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, Milano', 'MI', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', '#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        return $loan;
    }

    public function test_il_perfezionamento_carica_il_lead_e_ricorda_l_id(): void
    {
        $loan = $this->atSummary();
        Http::fake(['crm.example.com/*' => Http::response('<R><Stato>OK</Stato><IDUU>LEAD-1</IDUU></R>')]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame('perfezionata', $loan->fresh()->status);
        $this->assertSame('LEAD-1', $loan->fresh()->crm_lead_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'residenza_provincia=MI') && str_contains($r->url(), 'cognome=Rossi'));
    }

    public function test_se_il_crm_risponde_ko_la_pratica_resta_com_e(): void
    {
        $loan = $this->atSummary();
        Http::fake(['crm.example.com/*' => Http::response('<R><Stato>KO - errore</Stato></R>')]);

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('Invio pratica fallito', $body);
        $this->assertSame('informativa_ricevuta', $loan->fresh()->status);
    }
}
```

Run: `php artisan test --compact --filter='LeadParametersTest|MediafacileLeadGatewayTest|InvioLeadMediafacileTest'`
Expected: FAIL (classi assenti).

- [ ] **Step 2: Config e `.env.example`**

In `config/finanziamento.php`, nel blocco `'crm'` aggiungere `'driver' => env('CRM_DRIVER', 'simulated'),` e, accanto, il nuovo blocco:

```php
    // Lead sul CRM: valore del parametro `fonte`.
    'lead' => ['fonte' => 'unicoagent'],
```

In `.env.example`: `CRM_DRIVER=simulated` con un commento (`mediafacile` = servizio di caricamento lead).

- [ ] **Step 3: `LeadParameters`**

```php
<?php

namespace App\Services\Crm;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Models\QuoteBandBound;
use App\Models\QuoteEmploymentMap;
use Carbon\Carbon;

/**
 * Parametri del servizio di caricamento lead (specifica 1.6) dai dati della pratica perfezionata.
 * Non include la Passkey né il file: nessun documento va al CRM. I valori non ricavabili si omettono.
 */
final class LeadParameters
{
    /** @return array<string,string> */
    public static function build(LoanRequest $loan, array $personal): array
    {
        $answers = $loan->answers ?? [];
        $band = QuoteBandBound::where('dimension', 'importo')->where('code', $answers['importo'] ?? '')->first();

        return array_filter([
            'cognome' => $personal['cognome'] ?? null,
            'nome' => $personal['nome'] ?? null,
            'data_nascita' => self::birthDate($personal['data_nascita'] ?? null),
            'tipologia' => QuoteEmploymentMap::resolve($answers)?->lead_tipologia,
            'importo_richiesto' => $band ? number_format($band->high, 2, ',', '') : null,
            'residenza_citta' => self::city((string) ($personal['residenza'] ?? '')),
            'residenza_provincia' => isset($personal['residenza_provincia']) ? strtoupper($personal['residenza_provincia']) : null,
            'cellulare' => $personal['telefono'] ?? null,
            'email' => $personal['email'] ?? null,
            'fonte' => (string) config('finanziamento.lead.fonte', 'unicoagent'),
            'annotazioni' => self::notes($loan, $answers, $band),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** «gg/mm/aaaa» → «mm-gg-aaaa»; null se non è una data valida. */
    private static function birthDate(?string $date): ?string
    {
        if (! $date || ! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m) || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->format('m-d-Y');
    }

    /** Ultimo segmento dell'indirizzo (separato da virgole); senza virgole, tutto il testo. */
    private static function city(string $address): string
    {
        $parts = array_map('trim', explode(',', $address));

        return (string) end($parts);
    }

    private static function notes(LoanRequest $loan, array $answers, ?QuoteBandBound $band): string
    {
        $producer = Fornitore::findByWhatsApp((string) $loan->agent_wa_number)?->sigla;

        return implode(' · ', array_filter([
            "Pratica {$loan->code}",
            LoanRequest::productLabels()[$loan->product] ?? $loan->product,
            preg_match('/^m(\d+)$/', (string) ($answers['durata'] ?? ''), $m) ? "{$m[1]} mesi" : null,
            $band?->label ? "importo {$band->label}" : null,
            $producer ? "produttore {$producer}" : null,
        ]));
    }
}
```

- [ ] **Step 4: `MediafacileLeadGateway`**

```php
<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Carica il lead sul CRM con il servizio Mediafacile (GET con i parametri nell'URL, risposta XML).
 * Restituisce 200 solo se `Stato` comincia con «OK»; ogni altro esito è un errore (l'agente può riprovare).
 * Tracciato della risposta (elementi `Stato` e `IDUU`): dalla specifica 1.6, da verificare. Nei log solo il tipo di errore.
 */
class MediafacileLeadGateway implements CrmGateway
{
    public function submit(LoanRequest $loan, array $personal): int
    {
        $company = Company::forWhatsApp($loan->agent_wa_number);
        if (! $company?->hasSubmissionCrm() || blank($company->istruttoria_passkey)) {
            Log::warning('Lead CRM: URL o passkey mancanti', ['loan' => $loan->code]);

            return 0;
        }

        try {
            $response = Http::timeout((int) config('finanziamento.quote.timeout', 15))
                ->withQueryParameters(['Passkey' => $company->istruttoria_passkey] + LeadParameters::build($loan, $personal))
                ->get($company->url_istruttoria);
        } catch (ConnectionException) {
            Log::error('Lead CRM: servizio non raggiungibile', ['loan' => $loan->code]);

            return 0;
        }

        if ($response->failed()) {
            Log::error('Lead CRM: risposta HTTP', ['loan' => $loan->code, 'status' => $response->status()]);

            return $response->status();
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body());
        libxml_use_internal_errors($previous);

        $stato = $xml ? trim((string) ($xml->xpath('//Stato')[0] ?? '')) : '';
        if (! str_starts_with(strtoupper($stato), 'OK')) {
            Log::warning('Lead CRM: caricamento non riuscito', ['loan' => $loan->code, 'ok' => false]);

            return 422;
        }

        $loan->update(['crm_lead_id' => trim((string) ($xml->xpath('//IDUU')[0] ?? '')) ?: null]);

        return 200;
    }
}
```

- [ ] **Step 5: Binding, refactor di `ScenarioBuilder` e uso nel motore**

`AppServiceProvider`, al posto di `$this->app->bind(CrmGateway::class, SimulatedCrmGateway::class);`:

```php
        $this->app->bind(CrmGateway::class, fn ($app) => config('finanziamento.crm.driver') === 'mediafacile'
            ? $app->make(MediafacileLeadGateway::class)
            : new SimulatedCrmGateway);
```

con `use App\Services\Crm\MediafacileLeadGateway;`.

`ScenarioBuilder::employmentType`: sostituire la query con

```php
        $type = QuoteEmploymentMap::resolve($answers)?->tipo_rapporto
            ?? throw new QuoteUnavailable('Situazione lavorativa non riconosciuta.');
```

(il resto del metodo resta invariato).

`ConversationEngine::completePerfezionamento`: sostituire `$viaCrm = Company::current()?->hasSubmissionCrm() ?? false;` con `$viaCrm = Company::forWhatsApp($loan->agent_wa_number)?->hasSubmissionCrm() ?? false;`.

- [ ] **Step 6: Verificare**

Run: `php artisan test --compact --filter='LeadParametersTest|MediafacileLeadGatewayTest|InvioLeadMediafacileTest|ScenarioBuilderTest|InvioCrmTest'`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: caricamento del lead sul CRM con il servizio Mediafacile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Archiviazione dei documenti su SharePoint

**Files:**
- Create: `app/Services/Documents/SharePointUploader.php`, `app/Services/Documents/LoggingSharePointUploader.php`, `app/Jobs/ArchiveLoanDocuments.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Services/Conversation/ConversationEngine.php` (`completePerfezionamento`)
- Test: `tests/Feature/Documents/ArchiveLoanDocumentsTest.php`, `tests/Feature/Finanziamento/ArchiviazioneDopoPerfezionamentoTest.php`

**Interfaces:**
- Consumes: `loan_requests.documents_archived_at` (Task 1); `Attachment` (`kind`, `path` sul disco `local`, relazione `praticaDocument`); `PraticaDocument::$status` (`rejected` = rifiutato).
- Produces: `SharePointUploader::upload(string $folder, string $filename, string $contents): void` (interfaccia, da implementare con le routine reali); `ArchiveLoanDocuments::dispatch(int $loanId)`.

- [ ] **Step 1: Scrivere i test che falliscono**

`tests/Feature/Documents/ArchiveLoanDocumentsTest.php`:

```php
<?php

namespace Tests\Feature\Documents;

use App\Jobs\ArchiveLoanDocuments;
use App\Models\Attachment;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Documents\LoggingSharePointUploader;
use App\Services\Documents\SharePointUploader;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveLoanDocumentsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{0:string,1:string,2:string}> */
    public array $uploaded = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DocumentCatalogSeeder::class);
    }

    private function loan(array $override = []): LoanRequest
    {
        return LoanRequest::create($override + ['code' => 'FIN-2026-0007', 'agent_wa_number' => '39333', 'product' => 'personale', 'status' => 'perfezionata', 'answers' => []]);
    }

    private function attach(LoanRequest $loan, string $kind, string $name, string $body = 'PDF', bool $onDisk = true, ?string $slotStatus = null): Attachment
    {
        $path = "allegati/{$name}";
        $onDisk && Storage::disk('local')->put($path, $body);
        $slot = $slotStatus ? PraticaDocument::populate($loan)->first() : null;
        $slot?->update(['status' => $slotStatus]);

        return Attachment::create(['loan_request_id' => $loan->id, 'kind' => $kind, 'path' => $path, 'mime' => 'application/pdf', 'received_at' => now(), 'pratica_document_id' => $slot?->id]);
    }

    private function uploader(?\Throwable $fail = null): void
    {
        $this->app->instance(SharePointUploader::class, new class($this, $fail) implements SharePointUploader
        {
            public function __construct(private ArchiveLoanDocumentsTest $test, private ?\Throwable $fail) {}

            public function upload(string $folder, string $filename, string $contents): void
            {
                if ($this->fail) {
                    throw $this->fail;
                }
                $this->test->uploaded[] = [$folder, $filename, $contents];
            }
        });
    }

    private function run(LoanRequest $loan): void
    {
        (new ArchiveLoanDocuments($loan->id))->handle(app(SharePointUploader::class));
    }

    public function test_carica_ogni_allegato_nella_cartella_della_pratica_e_registra_l_archiviazione(): void
    {
        $this->uploader();
        $loan = $this->loan();
        $a = $this->attach($loan, 'informativa', 'a.pdf', 'UNO');
        $b = $this->attach($loan, 'documento_identita', 'b.jpg', 'DUE');

        $this->run($loan);

        $this->assertSame([['FIN-2026-0007', "informativa-{$a->id}.pdf", 'UNO'], ['FIN-2026-0007', "documento_identita-{$b->id}.jpg", 'DUE']], $this->uploaded);
        $this->assertNotNull($loan->fresh()->documents_archived_at);
    }

    public function test_salta_i_rifiutati_e_i_file_mancanti(): void
    {
        $this->uploader();
        $loan = $this->loan();
        $this->attach($loan, 'reddito', 'rifiutato.pdf', slotStatus: 'rejected');
        $this->attach($loan, 'codice_fiscale', 'manca.pdf', onDisk: false);
        $ok = $this->attach($loan, 'informativa', 'ok.pdf');

        $this->run($loan);

        $this->assertCount(1, $this->uploaded);
        $this->assertSame("informativa-{$ok->id}.pdf", $this->uploaded[0][1]);
    }

    public function test_non_archivia_le_pratiche_di_prova(): void
    {
        $this->uploader();
        $loan = $this->loan(['is_test' => true]);
        $this->attach($loan, 'informativa', 'a.pdf');

        $this->run($loan);

        $this->assertSame([], $this->uploaded);
        $this->assertNull($loan->fresh()->documents_archived_at);
    }

    public function test_se_il_caricamento_fallisce_l_eccezione_passa_e_non_si_registra_l_archiviazione(): void
    {
        $this->uploader(new \RuntimeException('sharepoint giù'));
        $loan = $this->loan();
        $this->attach($loan, 'informativa', 'a.pdf');

        try {
            $this->run($loan);
            $this->fail('atteso RuntimeException');
        } catch (\RuntimeException) {
            $this->assertNull($loan->fresh()->documents_archived_at);
        }
    }

    public function test_il_caricatore_finto_scrive_nel_log_senza_contenuto(): void
    {
        Log::spy();

        (new LoggingSharePointUploader)->upload('FIN-2026-0007', 'informativa-1.pdf', 'DATI SEGRETI');

        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $context === ['folder' => 'FIN-2026-0007', 'file' => 'informativa-1.pdf', 'bytes' => 12]
            && ! str_contains(json_encode($context), 'SEGRETI'))->once();
        $this->assertInstanceOf(LoggingSharePointUploader::class, app(SharePointUploader::class));
    }
}
```

`tests/Feature/Finanziamento/ArchiviazioneDopoPerfezionamentoTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Jobs\ArchiveLoanDocuments;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\PraticaDocument;
use App\Services\Crm\CrmGateway;
use Database\Seeders\DocumentCatalogSeeder;
use Illuminate\Support\Facades\Queue;

class ArchiviazioneDopoPerfezionamentoTest extends ConversationTestCase
{
    private function atSummary(): LoanRequest
    {
        Company::create(['name' => 'H', 'url_istruttoria' => 'https://crm.example.com/ws/lead']);
        $this->seed(DocumentCatalogSeeder::class);
        $loan = LoanRequest::create(['code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now(), 'answers' => ['prodotto' => 'personale']]);
        PraticaDocument::populate($loan)->each->update(['status' => 'ricevuto']);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1', 'MI', '#celibe', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', '#si', 'IT60X0542811101000000123456', 'ACME Srl', '01/03/2015');

        return $loan;
    }

    private function gateway(int $result): void
    {
        $this->app->instance(CrmGateway::class, new class($result) implements CrmGateway
        {
            public function __construct(private int $result) {}

            public function submit(LoanRequest $loan, array $personal): int
            {
                return $this->result;
            }
        });
    }

    public function test_a_perfezionamento_riuscito_parte_l_archiviazione(): void
    {
        $loan = $this->atSummary();
        $this->gateway(200);
        Queue::fake();

        $this->say('#conferma');

        Queue::assertPushed(ArchiveLoanDocuments::class, fn ($job) => $job->loanId === $loan->id);
    }

    public function test_se_l_invio_fallisce_non_si_archivia(): void
    {
        $this->atSummary();
        $this->gateway(500);
        Queue::fake();

        $this->say('#conferma');

        Queue::assertNothingPushed();
    }

    public function test_un_errore_nell_avvio_dell_archiviazione_non_blocca_il_perfezionamento(): void
    {
        $loan = $this->atSummary();
        $this->gateway(200);
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class implements \Illuminate\Contracts\Bus\Dispatcher
        {
            public function dispatch($command) { throw new \RuntimeException('coda giù'); }
            public function dispatchSync($command, $handler = null) { throw new \RuntimeException('coda giù'); }
            public function dispatchNow($command, $handler = null) { throw new \RuntimeException('coda giù'); }
            public function hasCommandHandler($command) { return false; }
            public function getCommandHandler($command) { return false; }
            public function pipeThrough(array $pipes) { return $this; }
            public function map(array $map) { return $this; }
        });

        $body = $this->bodies($this->say('#conferma'));

        $this->assertStringContainsString('inviata in istruttoria', $body);
        $this->assertSame('perfezionata', $loan->fresh()->status);
    }
}
```

Run: `php artisan test --compact --filter='ArchiveLoanDocumentsTest|ArchiviazioneDopoPerfezionamentoTest'`
Expected: FAIL (classi assenti).

- [ ] **Step 2: Interfaccia e caricatore finto**

```php
<?php

namespace App\Services\Documents;

/** Scrive un file su SharePoint. L'implementazione reale arriva dopo: oggi c'è il caricatore finto. */
interface SharePointUploader
{
    /** @param  string  $folder  cartella (il codice pratica) */
    public function upload(string $folder, string $filename, string $contents): void;
}
```

```php
<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;

/** Simulazione in attesa delle routine SharePoint: scrive nel log cartella, nome e dimensione, mai il contenuto. */
class LoggingSharePointUploader implements SharePointUploader
{
    public function upload(string $folder, string $filename, string $contents): void
    {
        Log::info('SharePoint (simulato): file archiviato', ['folder' => $folder, 'file' => $filename, 'bytes' => strlen($contents)]);
    }
}
```

In `AppServiceProvider::register`: `$this->app->bind(SharePointUploader::class, LoggingSharePointUploader::class);` con i relativi `use`.

- [ ] **Step 3: Il job**

```php
<?php

namespace App\Jobs;

use App\Models\LoanRequest;
use App\Services\Documents\SharePointUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Archivia su SharePoint gli allegati di una pratica perfezionata, in una cartella col codice pratica.
 * Salta le pratiche di prova, gli allegati rifiutati e i file non più sul disco. Un errore lascia la pratica
 * senza `documents_archived_at` e fa ripartire il job (3 tentativi): serve un worker di coda in esecuzione.
 */
class ArchiveLoanDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [60, 300];

    public function __construct(public int $loanId) {}

    public function handle(SharePointUploader $uploader): void
    {
        $loan = LoanRequest::find($this->loanId);
        if (! $loan || $loan->is_test) {
            return;
        }

        $disk = Storage::disk('local');
        foreach ($loan->attachments()->with('praticaDocument')->orderBy('id')->get() as $attachment) {
            if ($attachment->praticaDocument?->status === 'rejected' || ! $disk->exists($attachment->path)) {
                continue;
            }

            $uploader->upload($loan->code, "{$attachment->kind}-{$attachment->id}.".pathinfo($attachment->path, PATHINFO_EXTENSION), $disk->get($attachment->path));
        }

        $loan->update(['documents_archived_at' => now()]);
    }
}
```

- [ ] **Step 4: Avvio dopo il perfezionamento** — in `ConversationEngine::completePerfezionamento`, dopo `$this->mailer->notifyProducer($loan);` e prima di `$this->close(...)`:

```php
        $this->archiveDocuments($loan);
```

e aggiungere il metodo accanto a `submitToCrm`:

```php
    /** Avvia l'archiviazione su SharePoint; un errore qui non annulla il perfezionamento. */
    private function archiveDocuments(LoanRequest $loan): void
    {
        try {
            ArchiveLoanDocuments::dispatch($loan->id);
        } catch (\Throwable $e) {
            // Nel log solo il tipo di errore.
            Log::error('Archiviazione documenti non avviata', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }
```

con `use App\Jobs\ArchiveLoanDocuments;` tra gli import.

- [ ] **Step 5: Verificare e suite intera**

Run: `php artisan test --compact --filter='ArchiveLoanDocumentsTest|ArchiviazioneDopoPerfezionamentoTest'` → PASS; poi `php artisan test --compact` (suite intera; redirigere l'output su file e leggerne la coda). Expected: PASS salvo i 3 fallimenti preesistenti di `FilamentAdminTest`.

- [ ] **Step 6: Commit e push**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: archiviazione dei documenti su SharePoint dopo il perfezionamento

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
git -c core.fileMode=false push origin main
```

---

## Self-Review

- **Copertura della specifica:** lead GET con i parametri e senza `file` (Task 3); `fonte` fissa (Task 3, config); passkey cifrata e `crm_lead_id` (Task 1 e 3); domanda sulla provincia con migrazione (Task 2); `tipologia` con colonna `lead_tipologia` (Task 1 e 3); `importo_richiesto` dall'estremo alto e fascia nelle annotazioni (Task 3); archiviazione con job, caricatore finto, saltati rifiutati/prova (Task 4); driver `CRM_DRIVER` (Task 3).
- **Scostamento dalla specifica:** nessuno. Il job usa la coda vera (3 tentativi), quindi in produzione serve un worker: è nel Review Focus (punto 7) e nel report finale.
- **Placeholder:** nessuno.
- **Coerenza dei tipi:** `LeadParameters::build(LoanRequest, array): array`, `QuoteEmploymentMap::resolve(array): ?self`, `MediafacileLeadGateway::submit(): int`, `ArchiveLoanDocuments::$loanId` (pubblico, usato nel test), `SharePointUploader::upload(string, string, string): void`.
