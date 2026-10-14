# Percorsi per company e GBD Energy: implementation plan (parte 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ogni company ha percorsi, menu e prodotti propri; GBD Energy (Edison) ha un percorso di simulazione con consiglio dell'offerta migliore e un percorso di perfezionamento che chiude con la mail all'istruttoria; il pannello ha uno switch company che preimposta il filtro `company_id` delle tabelle.

**Architecture:** `FlowRepository` carica i percorsi della company di chi scrive (company ricavata dal numero con `Company::forWhatsApp()`); senza company vale la company più vecchia, quindi il comportamento attuale non cambia. Il menu ha un predefinito in config e un override JSON in `companies.menu`. I prodotti (`products` + `company_products`) diventano generici con `params` JSON; `EnergyQuoteEstimator` sceglie il più conveniente. Lo switch company è una chiave di sessione letta da `CurrentCompany`, usata come default dei filtri; nessuno scope globale, nessun ruolo.

**Tech Stack:** Laravel 13, Filament 5.10, PHPUnit (`php artisan test`), MySQL in produzione, sqlite in memoria nei test.

**Spec:** `docs/superpowers/specs/2026-10-14-company-percorsi-energy-design.md`

**Fuori da questo piano (piano 2):** la firma OTP con `SignatureRequestService` di unico-core. Le tabelle `documents`, `document_types`, `pdf_modules`, `signature_requests` non esistono nel DB `unicoagent` (le migrazioni di unico-core girano solo con `unico-core:migrate`). Serve una decisione su dove vivono quelle tabelle prima di pianificarla. In questo piano il perfezionamento GBD chiude con la mail all'istruttoria, come il finanziamento senza CRM.

## Global Constraints

- Tutto il testo per l'utente (bot, pannello, messaggi) è in italiano, come il resto dell'app.
- Limiti WhatsApp: titolo opzione max 24 caratteri, max 10 opzioni per nodo, prompt max 900 caratteri (`FlowValidator`).
- Nessuno scope globale e nessun ruolo: il filtro per company è solo un default dei filtri delle tabelle.
- Il comportamento della company esistente (id 1, FINANCE) non cambia; i test esistenti restano verdi.
- I prezzi dei prodotti Edison nel seeder sono dimostrativi e vanno dichiarati tali.
- Test: `RefreshDatabase`; i test del dialogo estendono `Tests\Feature\Finanziamento\ConversationTestCase` (agente `393331112222`, `say()`, `bodies()`).
- Commit solo se l'utente lo chiede o dopo il suo ok; i messaggi di commit finiscono con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- Una company senza percorsi (nuova, non ancora configurata): il bot deve rispondere con un messaggio chiaro, non con un'eccezione `LogicException` (Task 1).
- Due company con lo stesso codice di percorso (`richiesta`): le copie di prova e la produzione di una non devono finire all'altra (Task 1).
- Un numero sconosciuto (segnalatore occasionale) scrive al bot: company più vecchia, nessun errore (Task 1).
- Il record creato da un agente di GBD (conversazione, pratica) porta `company_id` di GBD, altrimenti il filtro dello switch non lo mostra (Task 1).
- Consumi non numerici o con la virgola decimale («3.500,5», «3500,5»): niente errore, valore interpretato correttamente o domanda ripetuta (Task 4).
- Nessun prodotto ENERGY attivo per la fornitura scelta: messaggio che rimanda al customer care, non un crash (Task 4).
- Il superadmin sceglie «Tutte»: nessun filtro, e nessuna company inesistente in sessione deve rompere le tabelle (Task 6).

---

### Task 1: Percorsi e record separati per company

**Files:**
- Create: `database/migrations/2026_10_14_000001_scope_flows_by_company.php`
- Modify: `app/Services/Flows/FlowRepository.php`
- Modify: `app/Services/Flows/FlowImporter.php`
- Modify: `app/Services/Flows/FlowCloner.php:26,67`
- Modify: `app/Filament/Resources/Flows/Tables/FlowsTable.php:54`
- Modify: `app/Services/Conversation/ConversationEngine.php` (`handle`, `resumeAfterAnalysis`, `start`, `completeRichiesta`, `startPerfectionFromCode`, `startDocuments` area, righe ~75-110, 249-258, 800-815, 880-915)
- Modify: `app/Services/Crm/LoanEmailSender.php:16-19,50-56`
- Test: `tests/Feature/Flows/FlowCompanyScopeTest.php`

**Interfaces:**
- Produces: `FlowRepository::setCompany(?int $companyId): void`; il repository usa `$this->company ?? Company::current()?->id` e, se anche questo è null, non filtra.
- Produces: `FlowImporter::import(bool $force = false, ?Company $company = null, ?array $flows = null): int` (con `$flows` null usa `config('finanziamento.flows')`; con `$company` null il comportamento attuale).
- Produces: `LoanEmailSender::recipient(?Company $company = null): ?string`.

- [ ] **Step 1: Scrivere i test che falliscono**

```php
<?php

namespace Tests\Feature\Flows;

use App\Models\Company;
use App\Models\Flow;
use App\Models\Fornitore;
use App\Services\Flows\FlowImporter;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finanziamento\ConversationTestCase;
use Tests\TestCase;

class FlowCompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function flowFor(Company $company, string $start): void
    {
        Flow::create(['company_id' => $company->id, 'code' => 'richiesta', 'name' => 'R', 'start' => $start, 'restart' => $start, 'is_active' => true])
            ->nodes()->create(['code' => $start, 'type' => 'message', 'prompt' => "Inizio {$company->name}", 'sort_order' => 1, 'jump_by' => 'answer']);
    }

    public function test_lo_stesso_codice_in_due_company_non_si_mescola(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->flowFor($a, 'inizio_a');
        $this->flowFor($b, 'inizio_b');
        $repo = app(FlowRepository::class);

        $repo->setCompany($b->id);
        $this->assertSame('inizio_b', $repo->flow('richiesta')['start']);
        $repo->setCompany($a->id);
        $this->assertSame('inizio_a', $repo->flow('richiesta')['start']);
        $repo->setCompany(null);
        $this->assertSame('inizio_a', $repo->flow('richiesta')['start'], 'senza company vale la più vecchia');
    }

    public function test_una_company_senza_percorsi_non_ne_eredita_altri(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->flowFor($a, 'inizio_a');
        $repo = app(FlowRepository::class);

        $repo->setCompany($b->id);
        $this->assertNull($repo->flow('richiesta'));
    }

    public function test_l_importazione_per_company_non_tocca_i_percorsi_delle_altre(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        $this->seed(FlowSeeder::class);
        Flow::query()->update(['company_id' => $a->id]);

        app(FlowImporter::class)->import(company: $b);

        $this->assertSame(3, Flow::where('company_id', $a->id)->count());
        $this->assertSame(3, Flow::where('company_id', $b->id)->count());
    }

    public function test_il_destinatario_della_mail_e_quello_della_company_della_pratica(): void
    {
        $a = Company::create(['name' => 'A', 'istruttoria_email' => 'a@example.com']);
        $b = Company::create(['name' => 'B', 'istruttoria_email' => 'b@example.com']);

        $this->assertSame('b@example.com', app(\App\Services\Crm\LoanEmailSender::class)->recipient($b));
        $this->assertSame('a@example.com', app(\App\Services\Crm\LoanEmailSender::class)->recipient(null));
    }
}

class ConversationCompanyTest extends ConversationTestCase
{
    public function test_un_numero_sconosciuto_con_due_company_usa_la_piu_vecchia_senza_errori(): void
    {
        Company::create(['name' => 'Prima']);
        Company::create(['name' => 'Seconda']);
        Flow::query()->update(['company_id' => Company::oldest('id')->value('id')]);

        $this->assertNotEmpty($this->say('ciao'));
    }

    public function test_una_company_senza_percorsi_riceve_un_messaggio_chiaro(): void
    {
        $vuota = Company::create(['name' => 'Vuota']);
        Company::create(['name' => 'Altra']);
        Fornitore::create(['name' => 'Agente', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $vuota->id]);

        $replies = $this->say('ciao', '#menu_richiedi');

        $this->assertStringContainsString('non è ancora attivo', $this->bodies($replies));
    }

    public function test_la_conversazione_e_la_pratica_portano_la_company_dell_agente(): void
    {
        $company = Company::create(['name' => 'Titolare']);
        Flow::query()->update(['company_id' => $company->id]);
        Fornitore::create(['name' => 'Agente', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);

        $this->say('ciao', '#menu_richiedi');

        $this->assertSame($company->id, \App\Models\Conversation::latest('id')->first()->company_id);
    }
}
```

(Se due classi nello stesso file danno problemi di autoload, spostare `ConversationCompanyTest` in `tests/Feature/Finanziamento/ConversationCompanyTest.php`.)

- [ ] **Step 2: Eseguirli e verificare che falliscano**

Run: `php artisan test tests/Feature/Flows/FlowCompanyScopeTest.php`
Expected: FAIL (`setCompany` e `recipient($company)` non esistono; `import(company:)` non esiste).

- [ ] **Step 3: Migrazione dell'indice unico**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            // Il codice del percorso è unico per company e variante (produzione/prova).
            $table->dropUnique('flows_code_is_test_unique');
            $table->unique(['company_id', 'code', 'is_test'], 'flows_company_code_is_test_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropUnique('flows_company_code_is_test_unique');
            $table->unique(['code', 'is_test'], 'flows_code_is_test_unique');
        });
    }
};
```

Nota: su MySQL `dropUnique` può fallire se la chiave esterna `flows_company_id_foreign` usa l'indice; l'indice sulla sola `company_id` esiste già (`flows_company_id_foreign`), quindi è sicuro. Verificarlo con `php artisan migrate` su un DB di prova prima di toccare quello reale.

- [ ] **Step 4: `FlowRepository` per company**

Sostituire `$memo`, `variant()`, `load()` e `forget()`; aggiungere `setCompany()`:

```php
    /** @var array<int,array<int,array<string,array<string,mixed>>>> memoria per company e variante: 0 = produzione, 1 = prova */
    private array $memo = [];

    private bool $test = false;

    private ?int $company = null;

    public function setCompany(?int $companyId): void
    {
        $this->company = $companyId;
    }

    /** La company di cui si leggono i percorsi: quella impostata, altrimenti la più vecchia (installazione a una sola azienda). */
    private function effectiveCompany(): ?int
    {
        return $this->company ?? \App\Models\Company::current()?->id;
    }
```

```php
    /** @return array<string,array<string,mixed>> */
    private function variant(bool $test): array
    {
        $company = $this->effectiveCompany() ?? 0;

        return $this->memo[$company][(int) $test] ??= $this->load($test);
    }
```

In `load()`, la query diventa:

```php
        $company = $this->effectiveCompany();
        $flows = Flow::where('is_test', $test)->where('is_active', true)
            ->when($company, fn ($q) => $q->where('company_id', $company))
            ->with(['nodes.options', 'nodes.jumps'])->orderBy('id')->get();
```

Il ripiego sulla configurazione (`Flow::where('is_test', false)->doesntExist()`) resta com'è: vale solo se non c'è nessun percorso nel database. `forget()` resta `$this->memo = [];`.

- [ ] **Step 5: `FlowImporter` per company**

Cambiare firma e query:

```php
    public function import(bool $force = false, ?\App\Models\Company $company = null, ?array $flows = null): int
    {
        $imported = 0;

        foreach ($flows ?? config('finanziamento.flows') as $code => $def) {
            $scope = fn ($q) => $q->when($company, fn ($q) => $q->where('company_id', $company->id));
            if (! $force && $scope(Flow::where('code', $code)->where('is_test', false))->exists()) {
                continue;
            }

            DB::transaction(function () use ($code, $def, $company) {
                $flow = Flow::updateOrCreate(['code' => $code, 'is_test' => false, 'company_id' => $company?->id], [
```

Il resto resta invariato. Attenzione: con `$company` null `updateOrCreate` cerca `company_id IS NULL`; in produzione i percorsi hanno `company_id = 1`, quindi per la company esistente si chiama sempre `import(company: Company::current())`. Aggiornare `FlowSeeder` in modo che passi `Company::current()` quando esiste.

- [ ] **Step 6: `FlowCloner` e `FlowsTable` filtrano per company**

In `FlowCloner.php:26` e `:67` e in `FlowsTable::hasCopy` aggiungere `->where('company_id', $production->company_id)` (rispettivamente `$test->company_id`, `$flow->company_id`) a ogni `Flow::where('code', …)`.

- [ ] **Step 7: `LoanEmailSender` per company**

```php
    public function recipient(?Company $company = null): ?string
    {
        return ($company ?? Company::current())?->istruttoria_email ?: config('finanziamento.mail.to');
    }
```

In `send()` usare `$this->recipient(Company::forWhatsApp($loan->agent_wa_number))`; in `notifyProducer()` lo stesso per il `cc`.

- [ ] **Step 8: Motore: company e `company_id` sui record**

In `ConversationEngine`:

```php
    /** La company di chi scrive, ricavata dal numero. */
    private function companyId(?string $waNumber): ?int
    {
        return filled($waNumber) ? Company::forWhatsApp($waNumber)?->id : null;
    }
```

In `handle()` prima di `dispatch`: `$this->flows->setCompany($this->companyId($m->from));` e nel `finally` aggiungere `$this->flows->setCompany(null);`. In `resumeAfterAnalysis()` stessa cosa con `$loan->agent_wa_number` (e reset nel `finally`). In `start()`, nella `Conversation::create`, aggiungere `'company_id' => $this->companyId($from)`; lo stesso nelle due `Conversation::create` di `startPerfectionFromCode` (righe ~893 e ~907) e in `completeRichiesta` nella `LoanRequest::create` con `$this->companyId($conv->wa_number)`.

In `start()` sostituire il `throw` con un messaggio chiaro:

```php
        $def = $this->flows->flow($flow);
        if ($def === null) {
            return [Reply::text('Questo servizio non è ancora attivo per la tua azienda. Contatta il customer care.'), $this->menu($from)];
        }
```

Controllare con `grep -rn "inesistente o disattivato" tests` che nessun test si aspetti l'eccezione (finora nessuno: il grep non ha trovato corrispondenze). Fare lo stesso per `startPerfectionFromCode`.

- [ ] **Step 9: Eseguire i test del task e tutta la suite**

Run: `php artisan test tests/Feature/Flows tests/Feature/Finanziamento tests/Feature/MultitenantPreparationTest.php`
Expected: PASS. Poi `php artisan test` per l'intera suite: nessuna regressione.

- [ ] **Step 10: Commit** (solo dopo l'ok dell'utente)

```bash
git add database/migrations/2026_10_14_000001_scope_flows_by_company.php app tests
git commit -m "feat: percorsi e record separati per company"
```

---

### Task 2: Menu per company

**Files:**
- Create: `database/migrations/2026_10_14_000002_add_menu_to_companies_table.php`
- Modify: `app/Models/Company.php` (cast `menu` => `array`, metodo `menuConfig()`)
- Modify: `app/Services/Conversation/ConversationEngine.php` (`menu()` e `fromMenu()`)
- Modify: `app/Filament/Resources/Companies/Schemas/CompanyForm.php` (campo JSON del menu)
- Test: `tests/Feature/Finanziamento/MenuPerCompanyTest.php`

**Interfaces:**
- Consumes: `Company::forWhatsApp(?string)` (esistente).
- Produces: `Company::menuConfig(): array{body: string, body_named: string, options: array<string,string>}` che fonde `companies.menu` sopra `config('finanziamento.menu')`; le opzioni dell'override sostituiscono del tutto quelle predefinite se presenti.

- [ ] **Step 1: Test che falliscono**

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Company;
use App\Models\Fornitore;

class MenuPerCompanyTest extends ConversationTestCase
{
    public function test_la_company_senza_override_usa_il_menu_predefinito(): void
    {
        $this->assertSame(config('finanziamento.menu.options'), Company::create(['name' => 'X'])->menuConfig()['options']);
    }

    public function test_il_menu_dell_installatore_e_quello_della_sua_company(): void
    {
        $company = Company::create(['name' => 'GBD', 'menu' => ['options' => ['menu_richiedi' => 'Nuova offerta', 'menu_perfeziona' => 'Sottoscrivi contratto']]]);
        Fornitore::create(['name' => 'Inst', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);

        $body = $this->bodies($this->say('ciao'));
        $options = json_encode(collect($this->say('ciao'))->map(fn ($r) => $r->options ?? null)->all());

        $this->assertStringContainsString('Nuova offerta', $options);
        $this->assertStringNotContainsString('Modifica Preventivo', $options);
    }

    public function test_si_sceglie_dal_menu_anche_scrivendo_il_titolo_della_company(): void
    {
        $company = Company::create(['name' => 'GBD', 'menu' => ['options' => ['menu_richiedi' => 'Nuova offerta']]]);
        Fornitore::create(['name' => 'Inst', 'tel' => $this->agent, 'is_active' => true, 'company_id' => $company->id]);

        $replies = $this->say('ciao', 'nuova offerta');

        $this->assertNotEmpty(\App\Models\Conversation::where('flow', 'richiesta')->get());
    }
}
```

(Verificare il nome reale della proprietà con le opzioni in `App\Services\Conversation\Reply` e adattare `$r->options`.)

- [ ] **Step 2: Eseguire e verificare che falliscano** — `php artisan test tests/Feature/Finanziamento/MenuPerCompanyTest.php` → FAIL (`menu` non è una colonna).

- [ ] **Step 3: Migrazione**

```php
Schema::table('companies', fn (Blueprint $t) => $t->json('menu')->nullable()->after('logo'));
// down: $t->dropColumn('menu')
```

- [ ] **Step 4: `Company`**

Aggiungere `'menu' => 'array'` ai cast e:

```php
    /** @return array{body: string, body_named: string, options: array<string,string>} */
    public function menuConfig(): array
    {
        $default = config('finanziamento.menu');
        $own = $this->menu ?? [];

        return [
            'body' => $own['body'] ?? $default['body'],
            'body_named' => $own['body_named'] ?? $default['body_named'],
            'options' => $own['options'] ?? $default['options'],
        ];
    }
```

- [ ] **Step 5: Motore**

In `menu()` usare `$config = (Company::forWhatsApp($waNumber)?->menuConfig()) ?? config('finanziamento.menu');` al posto di `config('finanziamento.menu.options')`, `body` e `body_named`.

In `fromMenu()`, prima del `match` sui testi, aggiungere il riconoscimento dei titoli della company:

```php
        $own = Company::forWhatsApp($m->from)?->menuConfig()['options'] ?? [];
        $byTitle = collect($own)->mapWithKeys(fn ($title, $id) => [$this->normalize($title) => $id])->all();
        $choice = $m->replyId ?? $byTitle[$this->normalize((string) $m->text)] ?? match (...) { ... };
```

Il `match` esistente resta come ripiego. Le voci non presenti nell'override non compaiono nel menu, ma gli id restano gli stessi.

- [ ] **Step 6: Form company** — aggiungere in `CompanyForm` un `KeyValue::make('menu.options')->label('Voci del menu (id → titolo)')->helperText('Vuoto = menu predefinito. Gli id validi: menu_richiedi, menu_modifica, menu_perfeziona, menu_stato. Titolo max 24 caratteri.')` con `->rules(['array'])`.

- [ ] **Step 7: Eseguire i test** — `php artisan test tests/Feature/Finanziamento` → PASS.

- [ ] **Step 8: Commit** (dopo l'ok).

---

### Task 3: Prodotti generici e tipo ENERGY

**Files:**
- Create: `database/migrations/2026_10_14_000003_extend_products_for_offers.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Models/Company.php:15` (costante `TYPES`)
- Modify: `app/Filament/Resources/Products/ProductResource.php`
- Test: `tests/Feature/ProductParamsTest.php`

**Interfaces:**
- Produces: `Product` con `code`, `description`, `is_active` (bool), `params` (array); scope `Product::active()`; `Company::TYPES['ENERGY'] = 'Energia'`.
- Produces: `Company::products()` filtrabile con `->where('products.type', 'ENERGY')`.

- [ ] **Step 1: Test che falliscono**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductParamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_tipo_energia_esiste(): void
    {
        $this->assertArrayHasKey('ENERGY', Company::TYPES);
    }

    public function test_i_params_sono_un_array_e_gli_inattivi_sono_esclusi(): void
    {
        $company = Company::create(['name' => 'GBD', 'type' => 'ENERGY']);
        $on = Product::create(['type' => 'ENERGY', 'name' => 'Luce Flex', 'code' => 'luce_flex', 'params' => ['fornitura' => 'luce', 'price' => 0.12]]);
        $off = Product::create(['type' => 'ENERGY', 'name' => 'Vecchia', 'code' => 'vecchia', 'is_active' => false]);
        $company->products()->attach([$on->id, $off->id]);

        $this->assertSame(0.12, $on->fresh()->params['price']);
        $this->assertSame(['luce_flex'], $company->products()->active()->pluck('code')->all());
    }
}
```

- [ ] **Step 2: Eseguire e verificare che falliscano** — FAIL (colonne e scope mancanti).

- [ ] **Step 3: Migrazione**

```php
Schema::table('products', function (Blueprint $table) {
    $table->string('code', 40)->nullable()->after('type');
    $table->text('description')->nullable();
    $table->boolean('is_active')->default(true);
    $table->json('params')->nullable();
});
// down: dropColumn(['code', 'description', 'is_active', 'params'])
```

- [ ] **Step 4: Modello**

```php
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'params' => 'array'];
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('products.is_active', true);
    }
```

In `Company::TYPES` aggiungere `'ENERGY' => 'Energia'`. Lo scope `active` è chiamato su `$company->products()` (relazione `belongsToMany`), quindi la colonna è qualificata con `products.`.

- [ ] **Step 5: Risorsa Filament** — in `ProductResource::form` aggiungere `TextInput::make('code')->label('Codice')->maxLength(40)`, `Textarea::make('description')->label('Descrizione')`, `Toggle::make('is_active')->label('Attivo')->default(true)`, `KeyValue::make('params')->label('Parametri (prezzo, quota fissa, durata, bonus, fornitura…)')`. In tabella aggiungere `IconColumn::make('is_active')->boolean()` e una colonna `code`.

- [ ] **Step 6: Test e suite** — `php artisan test` → PASS.

- [ ] **Step 7: Commit** (dopo l'ok).

---

### Task 4: `EnergyQuoteEstimator` e risposta del bot

**Files:**
- Create: `app/Services/Loans/EnergyQuoteEstimator.php`
- Modify: `app/Services/Conversation/ConversationEngine.php` (`outcomeText`, ~riga 983)
- Test: `tests/Feature/Loans/EnergyQuoteEstimatorTest.php`

**Interfaces:**
- Consumes: `Product` (Task 3) con `params`: `fornitura` ('luce'|'gas'), `price` (€/kWh o €/Smc), `fixed_monthly` (€/mese), `bonus` (€ una tantum, facoltativo), `duration_months`, `provider` (facoltativo).
- Consumes: `LoanRequest::answers` con `consumo` (consumo annuo in kWh o Smc, testo) e `spesa_attuale` (spesa annua in €, facoltativa); `LoanRequest::product` = 'luce'|'gas'.
- Produces: `EnergyQuoteEstimator::quote(LoanRequest $loan, Company $company): ?array{product: Product, annual_cost: float, saving: ?float}` (null se nessun prodotto adatto).
- Produces: `EnergyQuoteEstimator::parseNumber(mixed $value): ?float` (accetta «3500», «3.500,5», «3500,5», «3500.5»).

- [ ] **Step 1: Test che falliscono**

```php
<?php

namespace Tests\Feature\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\Product;
use App\Services\Loans\EnergyQuoteEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnergyQuoteEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function company(array ...$products): Company
    {
        $company = Company::create(['name' => 'GBD', 'type' => 'ENERGY']);
        foreach ($products as $i => $params) {
            $company->products()->attach(Product::create(['type' => 'ENERGY', 'name' => "P$i", 'code' => "p$i", 'params' => $params])->id);
        }

        return $company;
    }

    private function loan(string $product, array $answers): LoanRequest
    {
        return new LoanRequest(['product' => $product, 'answers' => $answers]);
    }

    public function test_sceglie_il_prodotto_piu_conveniente_e_calcola_il_risparmio(): void
    {
        $company = $this->company(
            ['fornitura' => 'luce', 'price' => 0.10, 'fixed_monthly' => 10],
            ['fornitura' => 'luce', 'price' => 0.12, 'fixed_monthly' => 0],
            ['fornitura' => 'gas', 'price' => 0.01, 'fixed_monthly' => 0],
        );

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => '3000', 'spesa_attuale' => '600']), $company);

        // P0: 3000*0.10 + 120 = 420; P1: 360. Vince P1 (e il gas è escluso).
        $this->assertSame('p1', $quote['product']->code);
        $this->assertEqualsWithDelta(360.0, $quote['annual_cost'], 0.01);
        $this->assertEqualsWithDelta(240.0, $quote['saving'], 0.01);
    }

    public function test_il_bonus_riduce_il_costo(): void
    {
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.80, 'fixed_monthly' => 5, 'bonus' => 50]);

        $quote = app(EnergyQuoteEstimator::class)->quote($this->loan('gas', ['consumo' => '1000']), $company);

        $this->assertEqualsWithDelta(810.0, $quote['annual_cost'], 0.01); // 800 + 60 - 50
        $this->assertNull($quote['saving']);
    }

    public function test_senza_prodotti_adatti_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'gas', 'price' => 0.8]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => '3000']), $company));
    }

    public function test_i_numeri_italiani_si_leggono_correttamente(): void
    {
        $e = app(EnergyQuoteEstimator::class);

        $this->assertSame(3500.5, $e->parseNumber('3.500,5'));
        $this->assertSame(3500.5, $e->parseNumber('3500,5'));
        $this->assertSame(3500.5, $e->parseNumber('3500.5'));
        $this->assertSame(3500.0, $e->parseNumber('3500'));
        $this->assertNull($e->parseNumber('molto'));
        $this->assertNull($e->parseNumber(''));
    }

    public function test_consumo_non_valido_restituisce_null(): void
    {
        $company = $this->company(['fornitura' => 'luce', 'price' => 0.1]);

        $this->assertNull(app(EnergyQuoteEstimator::class)->quote($this->loan('luce', ['consumo' => 'boh']), $company));
    }
}
```

- [ ] **Step 2: Eseguire e verificare che falliscano** — FAIL (classe inesistente).

- [ ] **Step 3: Implementare**

```php
<?php

namespace App\Services\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\Product;

/** Preventivatore energia: tra i prodotti ENERGY attivi della company consiglia il più conveniente per i consumi indicati. */
class EnergyQuoteEstimator
{
    /** @return array{product: Product, annual_cost: float, saving: ?float}|null */
    public function quote(LoanRequest $loan, Company $company): ?array
    {
        $answers = $loan->answers ?? [];
        $consumption = $this->parseNumber($answers['consumo'] ?? null);
        if ($consumption === null || $consumption <= 0) {
            return null;
        }

        $best = $company->products()->active()->where('products.type', 'ENERGY')->get()
            ->filter(fn (Product $p) => ($p->params['fornitura'] ?? null) === $loan->product && isset($p->params['price']))
            ->map(fn (Product $p) => ['product' => $p, 'annual_cost' => $this->annualCost($p, $consumption)])
            ->sortBy('annual_cost')->first();

        if ($best === null) {
            return null;
        }

        $current = $this->parseNumber($answers['spesa_attuale'] ?? null);

        return $best + ['saving' => $current !== null ? round($current - $best['annual_cost'], 2) : null];
    }

    private function annualCost(Product $product, float $consumption): float
    {
        $p = $product->params;

        return round($consumption * (float) $p['price'] + 12 * (float) ($p['fixed_monthly'] ?? 0) - (float) ($p['bonus'] ?? 0), 2);
    }

    /** «3.500,5», «3500,5», «3500.5» e «3500» → float; testo non numerico → null. */
    public function parseNumber(mixed $value): ?float
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',')) {
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }
}
```

- [ ] **Step 4: Eseguire i test** — `php artisan test tests/Feature/Loans/EnergyQuoteEstimatorTest.php` → PASS.

- [ ] **Step 5: Risposta del bot**

In `ConversationEngine` aggiungere la dipendenza `private EnergyQuoteEstimator $energy` al costruttore (dopo `$estimator`) e, in `outcomeText`, dentro `if (Fornitore::isProducer($waNumber)) {` come prima istruzione:

```php
            if ($company?->type === 'ENERGY') {
                return $this->energyText($loan, $company);
            }
```

e il metodo:

```php
    /** Offerta consigliata per le società di energia; senza prodotti adatti rimanda al customer care. */
    private function energyText(LoanRequest $loan, Company $company): string
    {
        $quote = $this->energy->quote($loan, $company);
        if ($quote === null) {
            $phone = $company->customer_care_phone ? "\n📞 {$company->customer_care_phone}" : '';

            return "Non riesco a calcolare un'offerta con questi dati: contatta il customer care di {$company->name}.{$phone}";
        }

        $p = $quote['product'];
        $text = "💡 Offerta consigliata: *{$p->name}*".(($p->params['provider'] ?? null) ? " ({$p->params['provider']})" : '')
            ."\nCosto stimato: *".number_format($quote['annual_cost'], 2, ',', '.').' €/anno*.';
        if ($quote['saving'] !== null && $quote['saving'] > 0) {
            $text .= "\nRisparmio stimato: *".number_format($quote['saving'], 2, ',', '.').' €/anno*.';
        }

        return $text."\n_Stima indicativa sui consumi dichiarati._";
    }
```

- [ ] **Step 6: Suite** — `php artisan test` → PASS.

- [ ] **Step 7: Commit** (dopo l'ok).

---

### Task 5: Percorsi GBD e dati iniziali

**Files:**
- Create: `config/energy.php` (alberi `richiesta` e `perfezionamento` di GBD)
- Create: `database/seeders/GbdEnergySeeder.php`
- Test: `tests/Feature/Finanziamento/GbdEnergyFlowTest.php`

**Interfaces:**
- Consumes: `FlowImporter::import(false, $company, config('energy.flows'))` (Task 1), `Product` (Task 3), `Company::menu` (Task 2).
- Produces: company «GBD Energy» (type ENERGY), percorsi `richiesta` e `perfezionamento` con i nodi `prodotto`, `consumo`, `spesa_attuale` (richiesta) e `codice`, `conferma_pratica` (perfezionamento), `Fornitore` «Installatore 1» tel 3927968199.
- Nomi obbligatori dei nodi (il motore li usa): `richiesta.prodotto`, `perfezionamento.codice`, `perfezionamento.conferma_pratica`.

- [ ] **Step 1: Test end-to-end che fallisce**

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\LoanRequest;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\Mail;

class GbdEnergyFlowTest extends ConversationTestCase
{
    protected string $agent = '393927968199';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GbdEnergySeeder::class);
    }

    public function test_installatore_1_fa_la_simulazione_e_riceve_l_offerta_migliore(): void
    {
        $replies = $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $text = $this->bodies($replies);

        $this->assertStringContainsString('Offerta consigliata', $text);
        $loan = LoanRequest::latest('id')->first();
        $this->assertSame('luce', $loan->product);
        $this->assertSame(\App\Models\Company::where('name', 'GBD Energy')->value('id'), $loan->company_id);
    }

    public function test_il_menu_e_quello_di_gbd(): void
    {
        $options = json_encode($this->say('ciao'));

        $this->assertStringContainsString('Nuova offerta', $options);
        $this->assertStringNotContainsString('Modifica Preventivo', $options);
    }

    public function test_il_perfezionamento_chiude_con_la_mail_all_istruttoria(): void
    {
        $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $code = LoanRequest::latest('id')->value('code');

        $this->say($code, '#si');
        // Dati del cliente, fino al riepilogo (le risposte seguono l'ordine dei nodi di config/energy.php).
        $this->say('RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', 'Via Verdi 2, 00100 Roma', 'IT001E12345678', 'Enel', '3331234567', 'mario@example.com', '#si', '#conferma');

        $this->assertSame('perfezionata', LoanRequest::where('code', $code)->value('status'));
        Mail::assertSent(\App\Mail\LoanSubmissionMail::class);
    }
}
```

(Gli id dei pulsanti e l'ordine esatto delle risposte vanno allineati ai nodi scritti allo Step 3: eseguire il test, leggere dove il dialogo si ferma con `$this->bodies(...)` e correggere il test o i nodi finché coincidono.)

- [ ] **Step 2: Eseguire e verificare che fallisca** — FAIL (`GbdEnergySeeder` non esiste).

- [ ] **Step 3: `config/energy.php`**

Stesse convenzioni di `config/finanziamento.php` (helper `$choice`, `$text`, `$summary` copiati in cima al file, non condivisi, per non toccare il file esistente):

```php
<?php

// Alberi di GBD Energy (Edison): simulazione e perfezionamento. Solo dati, nessuna closure.
$yn = ['si' => 'Sì', 'no' => 'No'];
$choice = fn (string $label, string $prompt, array $options, string|array $next, array $extra = []) => array_merge(
    ['type' => 'choice', 'label' => $label, 'prompt' => $prompt, 'options' => $options, 'next' => $next], $extra
);
$text = fn (string $label, string $prompt, array $rules, string|array $next, array $extra = []) => array_merge(
    ['type' => 'text', 'label' => $label, 'prompt' => $prompt, 'rules' => $rules, 'next' => $next], $extra
);
$summary = fn (array $extra = []) => array_merge([
    'type' => 'summary', 'prompt' => 'Confermi i dati inseriti?', 'save' => false,
    'options' => ['conferma' => 'Conferma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla'],
], $extra);

return [
    'flows' => [
        'richiesta' => [
            'start' => 'prodotto',
            'restart' => 'prodotto',
            'nodes' => [
                'prodotto' => $choice('Fornitura', 'Che fornitura vuoi proporre al cliente?', ['luce' => 'Luce', 'gas' => 'Gas'], 'consumo'),
                'consumo' => $text('Consumo annuo', 'Consumo annuo del cliente (kWh per la luce, Smc per il gas), dall\'ultima bolletta:', ['required', 'regex:/^\d{1,3}(\.\d{3})*(,\d+)?$|^\d+([.,]\d+)?$/'], 'spesa_attuale', ['error' => 'Scrivi un numero, per esempio 2700 o 2.700,5.']),
                'spesa_attuale' => $text('Spesa annua attuale', 'Quanto spende oggi il cliente in un anno, in euro? (se non lo sai scrivi 0)', ['required', 'regex:/^\d{1,3}(\.\d{3})*(,\d+)?$|^\d+([.,]\d+)?$/'], 'riepilogo', ['error' => 'Scrivi un numero, per esempio 650.']),
                'riepilogo' => $summary(),
            ],
        ],
        'perfezionamento' => [
            'start' => 'codice',
            'restart' => 'codice_fiscale',
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica:', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'codice_fiscale', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale del cliente:', ['required', 'regex:/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/'], 'cognome', ['upper' => true, 'strip_spaces' => true, 'error' => 'Codice fiscale non valido (16 caratteri), riprova.', 'checks' => ['codice_fiscale']]),
                'cognome' => $text('Cognome', 'Cognome del cliente:', ['required', 'string', 'max:60'], 'nome'),
                'nome' => $text('Nome', 'Nome del cliente:', ['required', 'string', 'max:60'], 'residenza'),
                'residenza' => $text('Residenza', 'Indirizzo di residenza (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'indirizzo_fornitura'),
                'indirizzo_fornitura' => $text('Indirizzo di fornitura', 'Indirizzo di fornitura, se diverso dalla residenza (altrimenti ripetilo):', ['required', 'string', 'max:160'], 'pod_pdr'),
                'pod_pdr' => $text('POD / PDR', 'Codice POD (luce, inizia con IT) o PDR (gas, 14 cifre) dalla bolletta:', ['required', 'string', 'max:20'], 'fornitore_attuale', ['upper' => true, 'strip_spaces' => true]),
                'fornitore_attuale' => $text('Fornitore attuale', 'Chi è il fornitore attuale?', ['required', 'string', 'max:60'], 'telefono'),
                'telefono' => $text('Telefono', 'Cellulare del cliente:', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.']),
                'email' => $text('Email', 'Email del cliente:', ['required', 'email'], 'contatto_diretto', ['error' => 'Email non valida, riprova.']),
                'contatto_diretto' => $choice('Contatto diretto', 'Il cliente acconsente a essere ricontattato da GBD Energy per il contratto?', $yn, 'riepilogo'),
                'riepilogo' => $summary(),
            ],
        ],
    ],
];
```

Ricordarsi che il regex dell'espressione di `consumo` accetta `2700`, `2.700,5`, `2700,5`, `2700.5` (come `parseNumber`). Verificare con il validatore di flussi (Step 5).

- [ ] **Step 4: Seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Fornitore;
use App\Models\Product;
use App\Services\Checks\CheckRegistry;
use App\Services\Flows\FlowImporter;
use Illuminate\Database\Seeder;

/** GBD Energy (contratti Edison via installatori) per la fase di prova. I prezzi dei prodotti sono DIMOSTRATIVI. */
class GbdEnergySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(['name' => 'GBD Energy'], [
            'type' => 'ENERGY',
            'email' => 'privacy@gbdenergy.example',
            'istruttoria_email' => 'contratti@gbdenergy.example',
            'menu' => ['options' => [
                'menu_richiedi' => 'Nuova offerta',
                'menu_perfeziona' => 'Sottoscrivi contratto',
                'menu_stato' => 'Stato pratiche',
            ]],
        ]);

        app(CheckRegistry::class)->sync();
        app(FlowImporter::class)->import(company: $company, flows: config('energy.flows'));

        $offers = [
            ['code' => 'edison_luce_flex', 'name' => 'Edison Luce Flex (esempio)', 'params' => ['fornitura' => 'luce', 'price' => 0.13, 'fixed_monthly' => 8, 'bonus' => 0, 'duration_months' => 12, 'provider' => 'Edison']],
            ['code' => 'edison_luce_fisso', 'name' => 'Edison Luce Prezzo Fisso (esempio)', 'params' => ['fornitura' => 'luce', 'price' => 0.15, 'fixed_monthly' => 0, 'bonus' => 40, 'duration_months' => 24, 'provider' => 'Edison']],
            ['code' => 'edison_gas_flex', 'name' => 'Edison Gas Flex (esempio)', 'params' => ['fornitura' => 'gas', 'price' => 0.55, 'fixed_monthly' => 7, 'bonus' => 0, 'duration_months' => 12, 'provider' => 'Edison']],
            ['code' => 'edison_gas_fisso', 'name' => 'Edison Gas Prezzo Fisso (esempio)', 'params' => ['fornitura' => 'gas', 'price' => 0.62, 'fixed_monthly' => 0, 'bonus' => 30, 'duration_months' => 24, 'provider' => 'Edison']],
        ];
        foreach ($offers as $offer) {
            $product = Product::updateOrCreate(['type' => 'ENERGY', 'code' => $offer['code']], ['name' => $offer['name'], 'params' => $offer['params'], 'is_active' => true]);
            $company->products()->syncWithoutDetaching([$product->id]);
        }

        Fornitore::updateOrCreate(['tel' => '3927968199'], [
            'name' => 'Installatore 1', 'type' => 'Installatore fotovoltaico', 'is_active' => true, 'company_id' => $company->id,
        ]);
    }
}
```

Il seeder non va in `DatabaseSeeder` (resta un dato di prova): si lancia con `php artisan db:seed --class=GbdEnergySeeder`.

- [ ] **Step 5: Eseguire il test e allinearlo**

Run: `php artisan test tests/Feature/Finanziamento/GbdEnergyFlowTest.php`
Expected: PASS. Se si ferma a un nodo, stampare `$this->bodies($replies)` per leggere dove e correggere la sequenza del test o il nodo. Poi validare ogni albero: `app(\App\Services\Flows\FlowValidator::class)` non deve segnalare errori sui nodi importati (aggiungere un'asserzione nel test se c'è un metodo per i percorsi interi; in caso contrario controllare dal pannello Percorsi di GBD).

- [ ] **Step 6: Suite** — `php artisan test` → PASS.

- [ ] **Step 7: Commit** (dopo l'ok).

---

### Task 6: Switch company e filtri preimpostati

**Files:**
- Create: `app/Support/CurrentCompany.php`
- Create: `app/Http/Controllers/CompanySwitchController.php`
- Create: `resources/views/filament/company-switcher.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (render hook)
- Modify: le tabelle di Percorsi, Fornitori, Pratiche, Conversazioni: `app/Filament/Resources/{Flows,Fornitori,LoanRequests,Conversations}/Tables/*Table.php` (aggiungere `CurrentCompany::filter()` ai `filters`)
- Modify: i form di Percorsi e Fornitori (default di `company_id`)
- Test: `tests/Feature/CompanySwitchTest.php`

**Interfaces:**
- Produces: `CurrentCompany::id(): ?int` (null = «Tutte»; un id inesistente in sessione vale null); `CurrentCompany::set(?int $id): void`; `CurrentCompany::filter(): SelectFilter` (`company_id`, opzioni = nome delle company, default = `CurrentCompany::id()`).
- Produces: `POST /admin/company-switch` (middleware `web`, `auth`) che accetta `company_id` (id oppure vuoto per «Tutte»).

- [ ] **Step 1: Test che falliscono**

```php
<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_scelta_resta_in_sessione(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);

        $this->actingAs(User::factory()->create())
            ->post('/admin/company-switch', ['company_id' => $b->id])->assertRedirect();

        $this->assertSame($b->id, session('current_company_id'));
    }

    public function test_tutte_toglie_il_filtro(): void
    {
        Company::create(['name' => 'A']);

        $this->actingAs(User::factory()->create())
            ->withSession(['current_company_id' => 1])
            ->post('/admin/company-switch', ['company_id' => ''])->assertRedirect();

        $this->assertNull(CurrentCompany::id());
    }

    public function test_un_id_inesistente_in_sessione_vale_tutte(): void
    {
        session(['current_company_id' => 9999]);

        $this->assertNull(CurrentCompany::id());
    }

    public function test_senza_login_non_si_cambia_company(): void
    {
        $this->post('/admin/company-switch', ['company_id' => 1])->assertRedirect();
        $this->assertNull(session('current_company_id'));
    }
}
```

- [ ] **Step 2: Eseguire e verificare che falliscano** — FAIL (rotta e classe inesistenti).

- [ ] **Step 3: `CurrentCompany`**

```php
<?php

namespace App\Support;

use App\Models\Company;
use Filament\Tables\Filters\SelectFilter;

/** La company scelta nello switch del pannello (sessione); null = «Tutte». Serve solo da default dei filtri. */
class CurrentCompany
{
    private const KEY = 'current_company_id';

    public static function id(): ?int
    {
        $id = session(self::KEY);

        return $id !== null && Company::whereKey($id)->exists() ? (int) $id : null;
    }

    public static function set(?int $id): void
    {
        session([self::KEY => $id]);
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('company_id')->label('Azienda')
            ->options(fn () => Company::orderBy('name')->pluck('name', 'id')->all())
            ->default(fn () => self::id());
    }
}
```

Se `default()` non accetta una closure nella versione installata (Filament 5.10), usare `->default(self::id())`: i filtri si costruiscono a ogni richiesta, quindi il valore è comunque aggiornato. Verificarlo nello Step 6.

- [ ] **Step 4: Controller, rotta, vista, hook**

Controller:

```php
<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanySwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()) {
            CurrentCompany::set($request->filled('company_id') ? $request->integer('company_id') : null);
        }

        return back();
    }
}
```

Rotta in `routes/web.php`: `Route::post('/admin/company-switch', \App\Http\Controllers\CompanySwitchController::class)->middleware('web')->name('company.switch');` (il test «senza login» richiede che l'utente non loggato venga solo rimandato indietro senza scrivere in sessione).

Vista `resources/views/filament/company-switcher.blade.php`:

```blade
<form method="POST" action="{{ route('company.switch') }}" class="flex items-center gap-2 px-2">
    @csrf
    <select name="company_id" onchange="this.form.submit()" class="fi-input rounded-lg border-gray-300 text-sm" aria-label="Azienda">
        <option value="">Tutte le aziende</option>
        @foreach (\App\Models\Company::orderBy('name')->get(['id', 'name']) as $company)
            <option value="{{ $company->id }}" @selected(\App\Support\CurrentCompany::id() === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
</form>
```

Hook in `AdminPanelProvider::panel()`:

```php
->renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.company-switcher'))
```

- [ ] **Step 5: Filtri e default dei form**

In ciascuna tabella (Percorsi, Fornitori, Pratiche, Conversazioni) aggiungere `\App\Support\CurrentCompany::filter()` all'array `filters([...])` (in `FlowsTable` accanto al `TernaryFilter` esistente). Nei form di Percorsi e Fornitori, dove c'è un campo `company_id` aggiungere `->default(fn () => \App\Support\CurrentCompany::id())`; dove manca, aggiungere `Select::make('company_id')->label('Azienda')->relationship('company', 'name')->default(fn () => \App\Support\CurrentCompany::id())`.

- [ ] **Step 6: Test, anche della tabella**

Aggiungere a `CompanySwitchTest` un test Livewire che apre la lista Percorsi con due company e verifica che con «A» in sessione compaiano solo i percorsi di A:

```php
    public function test_la_lista_percorsi_parte_filtrata_sulla_company_scelta(): void
    {
        $a = Company::create(['name' => 'A']);
        $b = Company::create(['name' => 'B']);
        \App\Models\Flow::create(['company_id' => $a->id, 'code' => 'richiesta', 'name' => 'Flusso A', 'start' => 'x', 'restart' => 'x']);
        \App\Models\Flow::create(['company_id' => $b->id, 'code' => 'richiesta', 'name' => 'Flusso B', 'start' => 'x', 'restart' => 'x']);

        $this->actingAs(User::factory()->create())->withSession(['current_company_id' => $a->id]);

        \Livewire\Livewire::test(\App\Filament\Resources\Flows\Pages\ListFlows::class)
            ->assertCanSeeTableRecords(\App\Models\Flow::where('company_id', $a->id)->get())
            ->assertCanNotSeeTableRecords(\App\Models\Flow::where('company_id', $b->id)->get());
    }
```

Run: `php artisan test tests/Feature/CompanySwitchTest.php tests/Feature/FilamentAdminTest.php tests/Feature/FlowAdminTest.php` → PASS (se `FilamentAdminTest` fallisce per il filtro preimpostato, adattare quei test al nuovo default, senza togliere asserzioni).

- [ ] **Step 7: Suite completa** — `php artisan test` → PASS.

- [ ] **Step 8: Commit** (dopo l'ok).

---

## Verifica finale

- [ ] `php artisan test` → tutta la suite verde.
- [ ] Su un DB di prova (non quello reale): `php artisan migrate` poi `php artisan db:seed --class=GbdEnergySeeder`; nel pannello lo switch mostra «GBD Energy» e filtra Percorsi, Fornitori, Pratiche, Conversazioni.
- [ ] Chiedere all'utente il via libera prima di migrare il DB reale (la migrazione del Task 1 cambia un indice unico di una tabella con dati).

## Self-review del piano

- **Copertura della spec:** §1 → Task 1 e 2; §2 → Task 3; §3 → Task 4; §5 → Task 6; §6 → Task 5; §4 (firma) → rinviato al piano 2 per la mancanza delle tabelle core, dichiarato in testa.
- **Segnaposto:** nessuno; le sequenze di risposta nel test del Task 5 e il nome della proprietà delle opzioni in `Reply` (Task 2) vanno allineati al codice reale, e il piano lo dice nel punto esatto.
- **Coerenza dei tipi:** `FlowRepository::setCompany(?int)`, `FlowImporter::import(bool, ?Company, ?array)`, `LoanEmailSender::recipient(?Company)`, `Company::menuConfig()`, `EnergyQuoteEstimator::quote(LoanRequest, Company)`, `CurrentCompany::id()/set()/filter()` sono definiti nei task che li producono e usati con le stesse firme dopo.
