# Produzione dei documenti con unico-core: implementation plan (fase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Al perfezionamento di una company con `produce_documents`, unicoagent compila i moduli PDF dei task del core, li registra come `Document` del core e li fa firmare con OTP; la pratica passa da «trasmessa in istruttoria» a «completa di moduli firmati» quando le firme sono complete.

**Architecture:** Il core fornisce `tasks`/`document_requirements`/`pdf_modules`, `ModulePrinter::render()` e `SignatureRequestService` (provider `fake` o Yousign). unicoagent aggiunge un risolutore dei task, un resolver dei dati della pratica, un produttore di documenti, due listener sugli eventi di firma e l'integrazione nel motore. I `Document` sono agganciati alla `LoanRequest` locale con un alias morph; nessun dato del cliente entra nel registro `clients` del core.

**Tech Stack:** Laravel 13, Filament 5.10, `meo-icar/unico-core` (collegato in `../unico-core`), spatie/laravel-medialibrary, pdftk, PHPUnit; MySQL per i test del core (`unico_core_phpunit`), sqlite in memoria per il resto.

**Spec:** `docs/superpowers/specs/2026-10-15-produzione-documenti-core-design.md`

## Global Constraints

- Tutto il testo per l'utente in italiano; commit con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Si lavora su `main`, senza branch; push solo su richiesta dell'utente. Non si mettono nel commit le modifiche dell'utente già presenti nel working tree.
- Opt-in: senza `companies.produce_documents` il comportamento resta quello di oggi (il finanziamento non cambia; stato `perfezionata`).
- Il DB condiviso `unico_core_test` (connessione `core`) si modifica solo con l'ok esplicito dell'utente, dopo backup; i test usano `unico_core_phpunit`.
- Nessun dato personale nei log: solo codice pratica e tipo di errore.
- Stati di GBD: `invio_istruttoria` («INVIO IN ISTRUTTORIA») al «Conferma»; `perfezionare` («PERFEZIONARE») a firme complete; `ALLA FIRMA` è solo il valore di stato di firma (`signature_status`: `in_attesa`, `firmata`, `scaduta`, `rifiutata`, `errore`) e il nome del task dei documenti da firmare.
- Evento `pratica.perfezionata` a UnicoBPM, per le company con `produce_documents`, solo alla firma completata.
- Provider di firma: `SIGNATURE_DRIVER=fake` finché non ci sono le chiavi Yousign.
- Ruling (spec: «seeder del core»): i dati di GBD nel core (stati, task, requisiti) li scrive `GbdEnergySeeder` di unicoagent sulla connessione `core`, non una migrazione del core, perché dipendono dall'id della company; nel core va solo lo schema (`tasks.company_id`).

## Review Focus

- Pratica con firmatario senza email o cellulare validi: nessuna richiesta parte, errore con il campo mancante, perfezionamento intatto (Task 6).
- Reinvio dopo un errore o una firma già in corso: nessun documento duplicato, nessuna seconda richiesta aperta (Task 6).
- Webhook di completamento ricevuto due volte: una sola mail con i PDF firmati e un solo evento BPM (Task 8).
- Company senza task per quel tipo/stato, o con task ma senza moduli PDF: nessun errore, `signature_status` resta vuoto e la pratica si comporta come oggi (Tasks 4, 7).
- Agente che scrive dopo oltre 24 ore dall'ultimo messaggio dell'installatore: l'avviso WhatsApp non blocca il listener (Task 8).
- Cliente azienda: firma il legale rappresentante, e partita IVA e ragione sociale arrivano nei dati del modulo (Task 5).

---

### Task 1: `tasks.company_id` nel core

**Files:**
- Create: `/var/www/html/unico-core/database/migrations/2026_10_15_090000_add_company_to_tasks.php`
- Modify: `/var/www/html/unico-core/src/Models/Task.php`
- Test: `tests/Feature/Core/TaskCompanyColumnTest.php` (in unicoagent, usa il DB di test del core, Task 2)

**Interfaces:**
- Produces: colonna `tasks.company_id` nullable (FK `companies`, `nullOnDelete`); `Task::company(): BelongsTo`; `Task::scopeForCompany(Builder $q, ?int $companyId): Builder` (company nulla o uguale all'id indicato).

Questo task dipende dal harness del Task 2 per il test: eseguire il Task 2 prima del passo di test, oppure scrivere qui il test dopo il Task 2. Ordine consigliato: Task 2, poi Task 1.

- [ ] **Step 1: Migrazione del core**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (Schema::hasColumn('tasks', 'company_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('parent_id')
                ->comment('Company a cui è riservato il task; nullo = valido per tutte le company')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'company_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
    }
};
```

- [ ] **Step 2: Modello `Task`**

In `Task.php` aggiungere `use Illuminate\Database\Eloquent\Builder;` e:

```php
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** I task validi per una company: quelli senza company e quelli riservati a lei. */
    public function scopeForCompany(Builder $query, ?int $companyId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('company_id')->when($companyId, fn (Builder $q) => $q->orWhere('company_id', $companyId)));
    }
```

- [ ] **Step 3: Applicare al DB di test del core**

Run: `CORE_DB_DATABASE=unico_core_phpunit php artisan unico-core:migrate --force` (da `/var/www/html/unicoagent`)
Expected: le migrazioni pendenti del core, compresa `2026_10_15_090000_add_company_to_tasks`, risultano `DONE`. Il DB reale NON si tocca qui.

- [ ] **Step 4: Test**

```php
<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Schema;
use Unico\Core\Models\Task;

class TaskCompanyColumnTest extends CoreTestCase
{
    public function test_i_task_hanno_la_company_facoltativa(): void
    {
        $this->assertTrue(Schema::connection('core')->hasColumn('tasks', 'company_id'));
    }

    public function test_lo_scope_prende_i_task_della_company_e_quelli_comuni(): void
    {
        $common = Task::create(['name' => 'Comune', 'taskable' => 'pratica']);
        $mine = Task::create(['name' => 'Mio', 'taskable' => 'pratica', 'company_id' => 2]);
        $other = Task::create(['name' => 'Altrui', 'taskable' => 'pratica', 'company_id' => 1]);

        $ids = Task::forCompany(2)->pluck('id')->all();

        $this->assertContains($common->id, $ids);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
```

Run: `php artisan test tests/Feature/Core/TaskCompanyColumnTest.php` → PASS (dopo il Task 2).

- [ ] **Step 5: Commit nel repository del core**

```bash
cd /var/www/html/unico-core && git add database/migrations/2026_10_15_090000_add_company_to_tasks.php src/Models/Task.php && git commit -m "feat: company_id sui task (nullo = valido per tutte)"
```

(Il test sta in unicoagent e si committa con il Task 2.)

---

### Task 2: Harness dei test sul DB del core, media library e alias morph

**Files:**
- Create: `tests/Feature/Core/CoreTestCase.php`
- Create: `tests/Fixtures/pdf/modulo-prova.pdf` (copia da `/var/www/html/unico-core/tests/Fixtures/pdf/modulo-prova.pdf`)
- Create: `config/media-library.php`
- Modify: `app/Providers/AppServiceProvider.php` (alias morph)
- Test: `tests/Feature/Core/CoreHarnessTest.php`

**Interfaces:**
- Produces: `Tests\Feature\Core\CoreTestCase` (estende `Tests\TestCase`, usa `RefreshDatabase` per sqlite e `DatabaseTransactions` per la connessione `core`, `$connectionsToTransact = ['core']`); metodi `fixturePdf(): string` (bytes), `coreModule(array $slots = null): PdfModule` (tipo documento + modulo con il PDF di prova copiato sul disco dei moduli), `makeLoan(array $overrides = []): LoanRequest`. Se `unico_core_phpunit` non è raggiungibile i test si saltano (`markTestSkipped`).
- Produces: alias morph `loan_request` → `App\Models\LoanRequest`; `config('media-library.media_model')` = `Unico\Core\Models\Media`.

- [ ] **Step 1: Copiare la fixture e leggere come i moduli sono salvati**

```bash
mkdir -p tests/Fixtures/pdf && cp /var/www/html/unico-core/tests/Fixtures/pdf/modulo-prova.pdf tests/Fixtures/pdf/
grep -n "modules_disk\|pdftk_binary" /var/www/html/unico-core/config/unico-core.php
grep -n "function fill" -B3 -A25 /var/www/html/unico-core/src/Pdf/PdfFormFiller.php | head -50
```

Expected: il disco dei moduli è `unico-core.pdf.modules_disk` (default `public`) e `$module->file_path` è relativo a quel disco; il test userà `Storage::fake()` su quel disco e vi copierà la fixture.

- [ ] **Step 2: `config/media-library.php`**

```php
<?php

return [
    // I file dei documenti stanno su Spatie Media Library con il modello Media del core (disco privato `documents`).
    'media_model' => \Unico\Core\Models\Media::class,
    'disk_name' => env('MEDIA_DISK', 'local'),
    'max_file_size' => 1024 * 1024 * 20,
    'queue_connection_name' => env('QUEUE_CONNECTION', 'sync'),
    'queue_name' => '',
    'queue_conversions_by_default' => false,
];
```

Verificare con `php artisan about | grep -i media` e `php artisan tinker --execute='echo config("media-library.media_model");'`. Se il pacchetto richiede altre chiavi, copiarle da `vendor/spatie/laravel-medialibrary/config/media-library.php` mantenendo `media_model` del core.

- [ ] **Step 3: Alias morph**

In `AppServiceProvider::boot()`:

```php
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(['loan_request' => \App\Models\LoanRequest::class], merge: true);
```

- [ ] **Step 4: `CoreTestCase`**

```php
<?php

namespace Tests\Feature\Core;

use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\PdfModule;

/** Test che usano le tabelle del core: DB `unico_core_phpunit` con rollback; il resto sta su sqlite in memoria. */
abstract class CoreTestCase extends TestCase
{
    use DatabaseTransactions, RefreshDatabase {
        RefreshDatabase::beginDatabaseTransaction insteadof DatabaseTransactions;
    }

    protected array $connectionsToTransact = ['core'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.core' => array_merge(config('database.connections.mysql'), ['database' => env('CORE_TEST_DB', 'unico_core_phpunit')]),
            'unico-core.connection' => 'core',
            'unico-core.signature.driver' => 'fake',
            'unico-core.pdf.pdftk_binary' => 'pdftk',
            'unico-core.documents_disk' => 'documents',
        ]);
        DB::purge('core');

        try {
            DB::connection('core')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('DB del core per i test non raggiungibile.');
        }

        Storage::fake('documents');
        Storage::fake(config('unico-core.pdf.modules_disk', 'public'));
        $this->beginCoreTransaction();
    }

    private function beginCoreTransaction(): void
    {
        DB::connection('core')->beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::connection('core')->rollBack());
    }

    protected function fixturePdf(): string
    {
        return file_get_contents(base_path('tests/Fixtures/pdf/modulo-prova.pdf'));
    }

    /** Tipo documento + modulo PDF con la fixture; i riquadri di firma di default sono `cliente`. */
    protected function coreModule(?array $slots = null, string $name = 'Modulo prova'): PdfModule
    {
        $type = DocumentType::create(['name' => $name, 'code' => 'T'.uniqid(), 'is_monitored' => false]);
        $path = 'module/'.uniqid().'.pdf';
        Storage::disk(config('unico-core.pdf.modules_disk', 'public'))->put($path, $this->fixturePdf());

        return PdfModule::create([
            'name' => $name, 'file_path' => $path, 'document_type_id' => $type->id, 'is_active' => true,
            'signature_slots' => $slots ?? [['slot' => 'cliente', 'role' => 'client', 'page' => 1, 'x' => 50.0, 'y' => 700.0, 'width' => 150.0, 'height' => 40.0]],
        ]);
    }

    protected function makeLoan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'I1-'.uniqid(), 'agent_wa_number' => '393927968199', 'product' => 'luce', 'status' => 'richiesta',
            'answers' => ['consumo' => '3000'], 'personal' => [
                'codice_fiscale' => 'RSSMRA80A01H501U', 'cognome' => 'Rossi', 'nome' => 'Mario', 'residenza' => 'Via Roma 1, 00100 Roma',
                'telefono' => '3331234567', 'email' => 'mario@example.com', 'tipo_cliente' => 'privato',
            ],
        ]);
    }
}
```

Nota: il trait `DatabaseTransactions` da solo gestisce solo `$connectionsToTransact`; combinarlo con `RefreshDatabase` può dare conflitti di metodo. Se il file non carica, tenere solo `RefreshDatabase` (sqlite) e lasciare la transazione sul core a `beginCoreTransaction()` (già presente) togliendo `DatabaseTransactions`.

- [ ] **Step 5: Test del harness (fallisce, poi passa)**

```php
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
```

Run: `php artisan test tests/Feature/Core/CoreHarnessTest.php`
Expected: FAIL prima di Step 2-3 (alias o media_model mancanti), poi PASS. Se i test sono SKIPPED, il DB `unico_core_phpunit` non è raggiungibile o non è migrato: eseguire `CORE_DB_DATABASE=unico_core_phpunit php artisan unico-core:migrate --force`.

- [ ] **Step 6: Suite e commit**

Run: `php artisan test` → tutta la suite, solo i 2 errori preesistenti (`BpmEventsTest::test_nothing_is_sent_without_configuration_or_for_test_practices`, `ArchiviazioneDopoPerfezionamentoTest::test_un_errore_nell_avvio_dell_archiviazione_non_blocca_il_perfezionamento`).

```bash
git add config/media-library.php app/Providers/AppServiceProvider.php tests/Feature/Core tests/Fixtures/pdf
git commit -m "feat: harness di test sul DB del core, media library e alias morph loan_request"
```

---

### Task 3: Impostazioni per company e stato di firma sulla pratica

**Files:**
- Create: `database/migrations/2026_10_15_000001_add_document_production_columns.php`
- Modify: `app/Models/Company.php` (cast, metodo `documentSettings()`)
- Modify: `app/Models/LoanRequest.php` (`STATUSES`, cast, costanti `SIGNATURE_STATUSES`)
- Test: `tests/Feature/Core/DocumentProductionColumnsTest.php`

**Interfaces:**
- Produces: `companies.produce_documents` (bool, default false), `companies.core_company_id` (unsigned int nullable), `companies.core_tipo_prodotto` (string nullable, es. `Utenza`); `loan_requests.signature_status` (string nullable), `loan_requests.signed_at` (timestamp nullable), `loan_requests.documents_sent_at` (timestamp nullable).
- Produces: `Company::producesDocuments(): bool` (flag attivo e `core_company_id` e `core_tipo_prodotto` valorizzati).
- Produces: `LoanRequest::STATUSES` con `invio_istruttoria => 'Trasmessa in istruttoria'` e `perfezionare => 'Da perfezionare'`; `LoanRequest::SIGNATURE_STATUSES = ['in_attesa' => 'In attesa firma', 'firmata' => 'Firmata', 'scaduta' => 'Scaduta', 'rifiutata' => 'Rifiutata', 'errore' => 'Errore']`.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentProductionColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_company_produce_documenti_solo_con_flag_e_riferimenti_al_core(): void
    {
        $this->assertFalse(Company::create(['name' => 'A'])->producesDocuments());
        $this->assertFalse(Company::create(['name' => 'B', 'produce_documents' => true])->producesDocuments());
        $this->assertTrue(Company::create(['name' => 'C', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza'])->producesDocuments());
    }

    public function test_gli_stati_nuovi_hanno_un_nome_leggibile(): void
    {
        $this->assertSame('Trasmessa in istruttoria', LoanRequest::STATUSES['invio_istruttoria']);
        $this->assertSame('Da perfezionare', LoanRequest::STATUSES['perfezionare']);
        $this->assertArrayHasKey('in_attesa', LoanRequest::SIGNATURE_STATUSES);
    }

    public function test_la_pratica_ricorda_firma_e_invio(): void
    {
        $loan = LoanRequest::create(['code' => 'X-1', 'agent_wa_number' => '39333', 'product' => 'luce', 'status' => 'richiesta', 'answers' => [], 'signature_status' => 'in_attesa', 'signed_at' => now(), 'documents_sent_at' => now()]);

        $this->assertSame('in_attesa', $loan->fresh()->signature_status);
        $this->assertNotNull($loan->fresh()->signed_at);
    }
}
```

Run: `php artisan test tests/Feature/Core/DocumentProductionColumnsTest.php` → FAIL.

- [ ] **Step 2: Migrazione**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('produce_documents')->default(false)->comment('Produce e fa firmare i moduli con unico-core al perfezionamento');
            $table->unsignedBigInteger('core_company_id')->nullable()->comment('Id della company nel DB core');
            $table->string('core_tipo_prodotto', 60)->nullable()->comment('Tipo di pratica del core per scegliere i task (es. Utenza)');
        });

        Schema::table('loan_requests', function (Blueprint $table) {
            $table->string('signature_status', 20)->nullable()->index();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('documents_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('loan_requests', fn (Blueprint $t) => $t->dropColumn(['signature_status', 'signed_at', 'documents_sent_at']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['produce_documents', 'core_company_id', 'core_tipo_prodotto']));
    }
};
```

- [ ] **Step 3: Modelli**

`Company`: aggiungere ai cast `'produce_documents' => 'boolean'` e

```php
    /** Produce i documenti con il core solo se il flag è attivo e il core è identificato (company e tipo di pratica). */
    public function producesDocuments(): bool
    {
        return $this->produce_documents && $this->core_company_id !== null && filled($this->core_tipo_prodotto);
    }
```

`LoanRequest`: aggiungere a `STATUSES` le due voci e la costante `SIGNATURE_STATUSES`; aggiungere ai cast `'signed_at' => 'datetime', 'documents_sent_at' => 'datetime'` (se `casts()` esiste già, estenderlo).

- [ ] **Step 4: Eseguire i test e la suite**

Run: `php artisan test tests/Feature/Core/DocumentProductionColumnsTest.php` → PASS; `php artisan test` → solo i 2 errori preesistenti.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_15_000001_add_document_production_columns.php app/Models/Company.php app/Models/LoanRequest.php tests/Feature/Core/DocumentProductionColumnsTest.php
git commit -m "feat: impostazioni di produzione documenti per company e stato di firma sulla pratica"
```

---

### Task 4: `LoanTaskResolver`

**Files:**
- Create: `app/Services/Documents/LoanTaskResolver.php`
- Test: `tests/Feature/Core/LoanTaskResolverTest.php`

**Interfaces:**
- Consumes: `Company::producesDocuments()`, `core_company_id`, `core_tipo_prodotto` (Task 3); `Task::forCompany(?int)` (Task 1).
- Produces: `LoanTaskResolver::modules(Company $company, string $stato): Collection<int,PdfModule>` — i `PdfModule` attivi dei tipi documento richiesti dai task attivi della company (o senza company) con `trigger_field = tipo_prodotto`, `trigger_value = $company->core_tipo_prodotto`, `trigger_subfield = stato_pratica`, `trigger_subvalue = $stato`, in ordine `document_requirements.sort_order`, senza duplicati.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Services\Documents\LoanTaskResolver;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\Task;

class LoanTaskResolverTest extends CoreTestCase
{
    private function company(array $extra = []): Company
    {
        return Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza'] + $extra);
    }

    private function task(string $stato, ?int $companyId, string $tipo = 'Utenza', bool $active = true): Task
    {
        return Task::create([
            'name' => "$tipo · $stato", 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => $tipo,
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => $stato, 'company_id' => $companyId, 'is_active' => $active,
        ]);
    }

    private function require(Task $task, \Unico\Core\Models\PdfModule $module, int $order = 1): void
    {
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $module->document_type_id, 'is_required' => true, 'sort_order' => $order]);
    }

    public function test_restituisce_i_moduli_dei_task_giusti_in_ordine(): void
    {
        $a = $this->coreModule(null, 'Informativa');
        $b = $this->coreModule(null, 'Contratto');
        $task = $this->task('ALLA FIRMA', 2);
        $this->require($task, $b, 2);
        $this->require($task, $a, 1);

        $names = app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->pluck('name')->all();

        $this->assertSame(['Informativa', 'Contratto'], $names);
    }

    public function test_esclude_altre_company_altri_tipi_altri_stati_e_task_disattivi(): void
    {
        $m = $this->coreModule(null, 'Escluso');
        foreach ([$this->task('ALLA FIRMA', 1), $this->task('ALLA FIRMA', 2, 'Cessione'), $this->task('Inserita', 2), $this->task('ALLA FIRMA', 2, 'Utenza', false)] as $t) {
            $this->require($t, $m);
        }

        $this->assertTrue(app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->isEmpty());
    }

    public function test_un_task_senza_company_vale_per_tutte(): void
    {
        $m = $this->coreModule(null, 'Comune');
        $this->require($this->task('ALLA FIRMA', null), $m);

        $this->assertSame(['Comune'], app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->pluck('name')->all());
    }

    public function test_un_tipo_documento_senza_modulo_o_con_modulo_disattivo_e_ignorato(): void
    {
        $type = \Unico\Core\Models\DocumentType::create(['name' => 'Solo caricamento', 'code' => 'T'.uniqid()]);
        $off = $this->coreModule(null, 'Spento');
        $off->update(['is_active' => false]);
        $task = $this->task('ALLA FIRMA', 2);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $type->id, 'is_required' => true, 'sort_order' => 1]);
        $this->require($task, $off, 2);

        $this->assertTrue(app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA')->isEmpty());
    }

    public function test_una_company_che_non_produce_documenti_non_ha_moduli(): void
    {
        $this->require($this->task('ALLA FIRMA', null), $this->coreModule());

        $this->assertTrue(app(LoanTaskResolver::class)->modules(Company::create(['name' => 'X']), 'ALLA FIRMA')->isEmpty());
    }

    public function test_un_modulo_ripetuto_in_piu_task_compare_una_volta(): void
    {
        $m = $this->coreModule(null, 'Doppio');
        $this->require($this->task('ALLA FIRMA', null), $m);
        $this->require($this->task('ALLA FIRMA', 2), $m);

        $this->assertCount(1, app(LoanTaskResolver::class)->modules($this->company(), 'ALLA FIRMA'));
    }
}
```

Run: `php artisan test tests/Feature/Core/LoanTaskResolverTest.php` → FAIL (classe inesistente). Se `DocumentRequirement`/`Task` rifiutano alcune chiavi (colonne NOT NULL), aggiungere il minimo richiesto nel test (guardare `php artisan tinker --execute='echo json_encode(DB::connection("core")->select("show create table document_requirements"));'` sul DB di test).

- [ ] **Step 2: Implementazione**

```php
<?php

namespace App\Services\Documents;

use App\Models\Company;
use Illuminate\Support\Collection;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\Task;

/** Dice quali moduli PDF produrre per una pratica: quelli dei documenti richiesti dai task del core per tipo di pratica e stato. */
class LoanTaskResolver
{
    /** @return Collection<int,PdfModule> */
    public function modules(Company $company, string $stato): Collection
    {
        if (! $company->producesDocuments()) {
            return collect();
        }

        $taskIds = Task::forCompany($company->core_company_id)
            ->where('is_active', true)
            ->where('trigger_field', 'tipo_prodotto')->where('trigger_value', $company->core_tipo_prodotto)
            ->where('trigger_subfield', 'stato_pratica')->where('trigger_subvalue', $stato)
            ->pluck('id');

        $typeIds = DocumentRequirement::whereIn('task_id', $taskIds)->orderBy('sort_order')->pluck('document_type_id')->unique()->values();
        if ($typeIds->isEmpty()) {
            return collect();
        }

        $modules = PdfModule::whereIn('document_type_id', $typeIds)->where('is_active', true)->get()->keyBy('document_type_id');

        return $typeIds->map(fn ($id) => $modules->get($id))->filter()->values();
    }
}
```

Nota: `unique()` sui `document_type_id` mantiene il primo ordine incontrato; il caso dei requisiti con `sort_order` diverso su più task segue l'ordine di lettura (ordinato globalmente per `sort_order`).

- [ ] **Step 3: Eseguire i test**

Run: `php artisan test tests/Feature/Core/LoanTaskResolverTest.php` → PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Documents/LoanTaskResolver.php tests/Feature/Core/LoanTaskResolverTest.php
git commit -m "feat: LoanTaskResolver sceglie i moduli dai task del core"
```

---

### Task 5: `LoanModuleData`

**Files:**
- Create: `app/Services/Documents/LoanModuleData.php`
- Test: `tests/Unit/LoanModuleDataTest.php`

**Interfaces:**
- Consumes: `LoanRequest::personal`, `answers`, `product`, `agent_wa_number`.
- Produces: `LoanModuleData::for(LoanRequest $loan): Unico\Core\Pdf\ResolvedModuleData` con le chiavi: `client.name` (cognome), `client.first_name`, `client.nominativo` («Cognome Nome»), `client.tax_code`, `client.birth_date` (`d/m/Y`), `client.birth_place`, `client.address.full`, `client.email`, `client.phone`, `client.is_person` (bool), `client.company_name` e `client.vat_number` (solo azienda), `pratica.oggi` (`d/m/Y`), `pratica.codice`, `pratica.prodotto`, `fornitura.tipo` (luce/gas), `fornitura.pod`, `fornitura.pdr`, `fornitura.indirizzo`, `fornitura.fornitore_attuale`, `fornitura.fornitore_attuale_piva`, `agent.name`. Valori assenti = `null` (mai stringa vuota).
- Produces: `LoanModuleData::signer(LoanRequest $loan): array{first_name:string,last_name:string,email:?string,phone:?string,tax_code:?string}` — il cliente, o il legale rappresentante per un'azienda (nome e codice fiscale che il bot ha raccolto).

- [ ] **Step 1: Test che fallisce** (Unit, senza DB: `new LoanRequest([...])`)

```php
<?php

namespace Tests\Unit;

use App\Models\LoanRequest;
use App\Services\Documents\LoanModuleData;
use PHPUnit\Framework\TestCase;

class LoanModuleDataTest extends TestCase
{
    private function loan(array $personal, string $product = 'luce'): LoanRequest
    {
        return new LoanRequest(['code' => 'I1-0001', 'product' => $product, 'agent_wa_number' => '393927968199', 'answers' => ['consumo' => '3000'], 'personal' => $personal]);
    }

    private function privato(): array
    {
        return ['tipo_cliente' => 'privato', 'codice_fiscale' => 'RSSMRA80A01H501U', 'cognome' => 'Rossi', 'nome' => 'Mario', 'data_nascita' => '01/01/1980', 'luogo_nascita' => 'Roma',
            'residenza' => 'Via Roma 1, 00100 Roma', 'telefono' => '3331234567', 'email' => 'mario@example.com', 'indirizzo_fornitura' => 'Via Verdi 2, 00100 Roma',
            'pod' => 'IT001E12345678', 'fornitore_attuale_nome' => 'ENEL ENERGIA S.P.A.', 'fornitore_attuale_piva' => '06655971007'];
    }

    public function test_un_privato_ha_le_chiavi_del_cliente_e_della_fornitura(): void
    {
        $d = (new LoanModuleData)->for($this->loan($this->privato()));

        $this->assertSame('Rossi', $d->get('client.name'));
        $this->assertSame('Rossi Mario', $d->get('client.nominativo'));
        $this->assertSame('RSSMRA80A01H501U', $d->get('client.tax_code'));
        $this->assertSame('Via Roma 1, 00100 Roma', $d->get('client.address.full'));
        $this->assertTrue($d->get('client.is_person'));
        $this->assertSame('IT001E12345678', $d->get('fornitura.pod'));
        $this->assertNull($d->get('fornitura.pdr'));
        $this->assertSame('06655971007', $d->get('fornitura.fornitore_attuale_piva'));
        $this->assertSame('I1-0001', $d->get('pratica.codice'));
        $this->assertSame(now()->format('d/m/Y'), $d->get('pratica.oggi'));
        $this->assertNull($d->get('client.vat_number'));
    }

    public function test_un_azienda_ha_ragione_sociale_e_partita_iva_e_firma_il_legale_rappresentante(): void
    {
        $loan = $this->loan(['tipo_cliente' => 'azienda', 'ragione_sociale' => 'ACME Srl', 'partita_iva' => 'IT12345678903'] + $this->privato());
        $m = new LoanModuleData;

        $d = $m->for($loan);
        $signer = $m->signer($loan);

        $this->assertFalse($d->get('client.is_person'));
        $this->assertSame('ACME Srl', $d->get('client.company_name'));
        $this->assertSame('12345678903', $d->get('client.vat_number'));
        $this->assertSame('Mario', $signer['first_name']);
        $this->assertSame('RSSMRA80A01H501U', $signer['tax_code']);
    }

    public function test_i_dati_mancanti_restano_assenti_non_stringhe_vuote(): void
    {
        $d = (new LoanModuleData)->for($this->loan(['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => '']));

        $this->assertNull($d->get('client.first_name'));
        $this->assertNull($d->get('client.email'));
        $this->assertFalse($d->has('client.tax_code'));
    }

    public function test_per_il_gas_c_e_il_pdr_e_non_il_pod(): void
    {
        $d = (new LoanModuleData)->for($this->loan(['tipo_cliente' => 'privato', 'pdr' => '12345678901234'], 'gas'));

        $this->assertSame('12345678901234', $d->get('fornitura.pdr'));
        $this->assertNull($d->get('fornitura.pod'));
        $this->assertSame('gas', $d->get('fornitura.tipo'));
    }
}
```

Nota: il percorso perfezionamento di GBD salva il POD e il PDR con le chiavi dei nodi `pod` e `pdr`; la data e il luogo di nascita con `data_nascita` e `luogo_nascita` (derivati dal codice fiscale).

Run: `php artisan test tests/Unit/LoanModuleDataTest.php` → FAIL.

- [ ] **Step 2: Implementazione**

```php
<?php

namespace App\Services\Documents;

use App\Models\Fornitore;
use App\Models\LoanRequest;
use Unico\Core\Pdf\ResolvedModuleData;

/** Traduce i dati raccolti dal bot nelle chiavi con cui i moduli PDF del core si compilano. */
class LoanModuleData
{
    public function for(LoanRequest $loan): ResolvedModuleData
    {
        $p = $loan->personal ?? [];
        $isPerson = ($p['tipo_cliente'] ?? 'privato') !== 'azienda';
        $agent = $loan->agent_wa_number ? Fornitore::findByWhatsApp($loan->agent_wa_number) : null;

        return new ResolvedModuleData(array_map(fn ($v) => $v === '' ? null : $v, [
            'client.name' => $p['cognome'] ?? null,
            'client.first_name' => $p['nome'] ?? null,
            'client.nominativo' => trim(($p['cognome'] ?? '').' '.($p['nome'] ?? '')) ?: null,
            'client.tax_code' => $p['codice_fiscale'] ?? null,
            'client.birth_date' => $p['data_nascita'] ?? null,
            'client.birth_place' => $p['luogo_nascita'] ?? null,
            'client.address.full' => $p['residenza'] ?? null,
            'client.email' => $p['email'] ?? null,
            'client.phone' => $p['telefono'] ?? null,
            'client.is_person' => $isPerson,
            'client.company_name' => $isPerson ? null : ($p['ragione_sociale'] ?? null),
            'client.vat_number' => $isPerson ? null : preg_replace('/^IT/i', '', (string) ($p['partita_iva'] ?? '')),
            'pratica.oggi' => now()->format('d/m/Y'),
            'pratica.codice' => $loan->code,
            'pratica.prodotto' => $loan->product,
            'fornitura.tipo' => $loan->product,
            'fornitura.pod' => $p['pod'] ?? null,
            'fornitura.pdr' => $p['pdr'] ?? null,
            'fornitura.indirizzo' => ($p['indirizzo_fornitura'] ?? null) ?: ($p['residenza'] ?? null),
            'fornitura.fornitore_attuale' => $p['fornitore_attuale_nome'] ?? ($p['fornitore_attuale'] ?? null),
            'fornitura.fornitore_attuale_piva' => $p['fornitore_attuale_piva'] ?? null,
            'agent.name' => $agent?->display_name,
        ]));
    }

    /** Chi firma: il cliente, o il legale rappresentante per un'azienda (i dati personali raccolti sono i suoi). */
    public function signer(LoanRequest $loan): array
    {
        $p = $loan->personal ?? [];

        return [
            'first_name' => (string) ($p['nome'] ?? ''),
            'last_name' => (string) ($p['cognome'] ?? ''),
            'email' => ($p['email'] ?? '') ?: null,
            'phone' => ($p['telefono'] ?? '') ?: null,
            'tax_code' => ($p['codice_fiscale'] ?? '') ?: null,
        ];
    }
}
```

Nota: `client.vat_number` per un privato dà `null`: `preg_replace` si esegue solo per l'azienda; con `array_map` una stringa vuota diventa `null`.

- [ ] **Step 3: Test → PASS; commit**

Run: `php artisan test tests/Unit/LoanModuleDataTest.php` → PASS.

```bash
git add app/Services/Documents/LoanModuleData.php tests/Unit/LoanModuleDataTest.php
git commit -m "feat: LoanModuleData compila le chiavi dei moduli dai dati del bot"
```

---

### Task 6: `LoanDocumentProducer`

**Files:**
- Create: `app/Services/Documents/LoanDocumentProducer.php`
- Create: `app/Services/Documents/DocumentProductionException.php`
- Test: `tests/Feature/Core/LoanDocumentProducerTest.php`

**Interfaces:**
- Consumes: `LoanTaskResolver::modules(Company, string): Collection<PdfModule>` (Task 4); `LoanModuleData::for/signer` (Task 5); `SignatureRequestService::send(Document, array<SignerInput>, ?Authenticatable): SignatureRequest`; `Company::core_company_id`.
- Produces: `LoanDocumentProducer::produce(LoanRequest $loan, Company $company, string $stato): array{sent:int, skipped:int}` — per ogni modulo: crea (o riusa) il `Document` agganciato alla pratica, compila il PDF, lo salva nella collection `documents` e avvia la firma; aggiorna `loan.signature_status = 'in_attesa'` e `documents_sent_at`. Lancia `DocumentProductionException` (messaggio in italiano, senza dati personali) per firmatario non valido o produzione fallita, dopo aver impostato `signature_status = 'errore'`. Idempotente: un documento con firma aperta o già firmata non si rifà.
- Produces: costanti `LoanDocumentProducer::SIGNER_SLOT = 'cliente'`.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use App\Services\Documents\DocumentProductionException;
use App\Services\Documents\LoanDocumentProducer;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;

class LoanDocumentProducerTest extends CoreTestCase
{
    private function company(): Company
    {
        return Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza']);
    }

    private function withModules(int $count = 1): void
    {
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => 2, 'is_active' => true]);
        foreach (range(1, $count) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }
    }

    public function test_produce_i_documenti_e_li_manda_in_firma(): void
    {
        $this->withModules(2);
        $loan = $this->makeLoan();

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 2, 'skipped' => 0], $result);
        $this->assertSame(2, Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->count());
        $this->assertSame(2, SignatureRequest::count());
        $loan->refresh();
        $this->assertSame('in_attesa', $loan->signature_status);
        $this->assertNotNull($loan->documents_sent_at);
        $doc = Document::where('documentable_id', $loan->id)->first();
        $this->assertSame('application/pdf', $doc->getFirstMedia(Document::COLLECTION)->mime_type);
        $this->assertSame('generato', $doc->channel);
    }

    public function test_il_reinvio_non_duplica_documenti_ne_richieste(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan();
        $producer = app(LoanDocumentProducer::class);

        $producer->produce($loan, $this->company(), 'ALLA FIRMA');
        $second = $producer->produce($loan->fresh(), $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 0, 'skipped' => 1], $second);
        $this->assertSame(1, Document::where('documentable_id', $loan->id)->count());
        $this->assertSame(1, SignatureRequest::count());
    }

    public function test_senza_email_o_cellulare_non_parte_nulla_e_lo_stato_e_errore(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan(['personal' => ['tipo_cliente' => 'privato', 'cognome' => 'Rossi', 'nome' => 'Mario', 'codice_fiscale' => 'RSSMRA80A01H501U', 'telefono' => '', 'email' => '']]);

        try {
            app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');
            $this->fail('Doveva fallire');
        } catch (DocumentProductionException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
        }

        $this->assertSame('errore', $loan->fresh()->signature_status);
        $this->assertSame(0, SignatureRequest::count());
    }

    public function test_senza_moduli_per_quello_stato_non_fa_nulla_e_non_tocca_lo_stato_di_firma(): void
    {
        $loan = $this->makeLoan();

        $result = app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(['sent' => 0, 'skipped' => 0], $result);
        $this->assertNull($loan->fresh()->signature_status);
    }

    public function test_il_documento_porta_la_company_del_core(): void
    {
        $this->withModules(1);
        $loan = $this->makeLoan();

        app(LoanDocumentProducer::class)->produce($loan, $this->company(), 'ALLA FIRMA');

        $this->assertSame(2, Document::where('documentable_id', $loan->id)->value('company_id'));
    }
}
```

Run: `php artisan test tests/Feature/Core/LoanDocumentProducerTest.php` → FAIL.

- [ ] **Step 2: Implementazione**

```php
<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\Log;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Models\Document;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Pdf\ModulePrinter;
use Unico\Core\Signature\SignatureRequestService;
use Unico\Core\Signature\SignerInput;

/** Compila i moduli dei task, li registra come Document del core agganciati alla pratica e li manda in firma. */
class LoanDocumentProducer
{
    public const SIGNER_SLOT = 'cliente';

    public function __construct(
        private LoanTaskResolver $tasks,
        private LoanModuleData $data,
        private ModulePrinter $printer,
        private SignatureRequestService $signatures,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function produce(LoanRequest $loan, Company $company, string $stato): array
    {
        $modules = $this->tasks->modules($company, $stato);
        if ($modules->isEmpty()) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $signer = $this->signer($loan);
        $sent = $skipped = 0;

        foreach ($modules as $module) {
            $document = $this->document($loan, $company, $module);
            if ($this->alreadyInSignature($document)) {
                $skipped++;

                continue;
            }

            try {
                $this->attachPdf($document, $module, $loan);
                $this->signatures->send($document, [$signer]);
                $sent++;
            } catch (\Throwable $e) {
                // Solo il tipo di errore: il messaggio può contenere dati personali.
                Log::error('Produzione documenti non riuscita', ['loan' => $loan->code, 'module' => $module->id, 'exception' => $e::class]);
                $loan->update(['signature_status' => 'errore']);

                throw new DocumentProductionException('Non sono riuscito a preparare i documenti per la firma. Riprova dal pannello.', 0, $e);
            }
        }

        if ($sent > 0) {
            $loan->update(['signature_status' => 'in_attesa', 'documents_sent_at' => now()]);
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function signer(LoanRequest $loan): SignerInput
    {
        $s = $this->data->signer($loan);
        $missing = array_keys(array_filter(['nome' => $s['first_name'], 'cognome' => $s['last_name'], 'email' => $s['email'], 'cellulare' => $s['phone']], fn ($v) => blank($v)));
        if ($missing !== []) {
            $loan->update(['signature_status' => 'errore']);

            throw new DocumentProductionException('Mancano dati per la firma: '.implode(', ', $missing).'.');
        }

        return new SignerInput(self::SIGNER_SLOT, SignerRole::Client, $s['first_name'], $s['last_name'], $s['email'], $s['phone'], $s['tax_code'], 'client', (string) $loan->id);
    }

    private function document(LoanRequest $loan, Company $company, PdfModule $module): Document
    {
        return Document::firstOrCreate(
            ['documentable_type' => 'loan_request', 'documentable_id' => $loan->id, 'document_type_id' => $module->document_type_id],
            ['company_id' => $company->core_company_id, 'name' => $module->name.' '.$loan->code, 'channel' => 'generato'],
        );
    }

    /** Firma già aperta o completata: non si rifà né si duplica la richiesta. */
    private function alreadyInSignature(Document $document): bool
    {
        return $document->is_signed || $document->signatureRequests()->open()->exists();
    }

    private function attachPdf(Document $document, PdfModule $module, LoanRequest $loan): void
    {
        $pdf = $this->printer->render($module, $this->data->for($loan));

        $document->clearMediaCollection(Document::COLLECTION);
        $document->addMediaFromString($pdf)->usingFileName(str($module->name)->slug().'-'.$loan->code.'.pdf')->toMediaCollection(Document::COLLECTION);
    }
}
```

`DocumentProductionException`:

```php
<?php

namespace App\Services\Documents;

/** Errore di produzione o invio in firma, con un messaggio in italiano senza dati personali. */
class DocumentProductionException extends \RuntimeException {}
```

Nota: se `Document::firstOrCreate` non accetta `channel`/`company_id` come attributi massivi, il modello ha `$guarded = ['id']`, quindi vanno bene. Se `SignatureRequestService::send` richiede un utente autenticato, passare `null` (terzo parametro opzionale).

- [ ] **Step 3: Eseguire i test**

Run: `php artisan test tests/Feature/Core/LoanDocumentProducerTest.php` → PASS. Se `printer->render` non trova i campi con la fixture, il test `CoreHarnessTest` del Task 2 dice già se pdftk funziona.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Documents/LoanDocumentProducer.php app/Services/Documents/DocumentProductionException.php tests/Feature/Core/LoanDocumentProducerTest.php
git commit -m "feat: LoanDocumentProducer compila i moduli e li manda in firma con il core"
```

---

### Task 7: Integrazione nel perfezionamento

**Files:**
- Modify: `app/Services/Conversation/ConversationEngine.php` (`perfectedAttributes`, `completePerfezionamento`)
- Modify: `app/Services/Bpm/BpmEvents.php` (nessuna firma da cambiare; solo il punto di chiamata)
- Test: `tests/Feature/Core/PerfezionamentoConDocumentiTest.php`

**Interfaces:**
- Consumes: `Company::producesDocuments()`; `LoanDocumentProducer::produce(LoanRequest, Company, string): array`; `DocumentProductionException`.
- Produces: per una company con `producesDocuments()`: `perfectedAttributes` imposta `status = 'invio_istruttoria'` (non `perfezionata`); dopo la mail e l'archiviazione si chiama `produce($loan, $company, 'ALLA FIRMA')`; `BpmEvents::dispatchPerfected` NON parte; la risposta al bot dice che i documenti sono stati inviati al cliente per la firma (o che la preparazione non è riuscita, con la pratica già trasmessa). Per le altre company nulla cambia.
- Produces: costante `ConversationEngine::SIGN_STATE = 'ALLA FIRMA'`.

- [ ] **Step 1: Test che fallisce** (estende `CoreTestCase`, usa il percorso GBD del seeder)

```php
<?php

namespace Tests\Feature\Core;

use App\Mail\LoanSubmissionMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Bpm\BpmEvents;
use Database\Seeders\GbdEnergySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;
use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;

class PerfezionamentoConDocumentiTest extends CoreTestCase
{
    protected string $agent = '393927968199';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['finanziamento.mail.to' => 'istruttoria@example.com', 'services.whatsapp.token' => 'T', 'services.whatsapp.phone_number_id' => '1']);
        $this->seed(\Database\Seeders\FlowSeeder::class);
        $this->seed(GbdEnergySeeder::class);
        Company::where('name', 'GBD Energy')->update(['produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza']);
    }

    private function say(string ...$inputs): array
    {
        $engine = app(ConversationEngine::class);
        $replies = [];
        foreach ($inputs as $i) {
            $replies = $engine->handle(str_starts_with($i, '#') ? new IncomingMessage($this->agent, 'interactive', $i, substr($i, 1)) : new IncomingMessage($this->agent, 'text', $i));
        }

        return $replies;
    }

    private function toSummary(): string
    {
        $this->say('ciao', '#menu_richiedi', '#luce', '3000', '600', '#conferma');
        $code = LoanRequest::latest('id')->value('code');
        $this->say('ciao', '#menu_perfeziona');
        $this->say($code, '#si', '#privato', 'RSSMRA80A01H501U', 'Rossi', 'Mario', 'Via Roma 1, 00100 Roma', '#si', 'IT001E12345678', 'enel energia', '3331234567', 'mario@example.com', '#si');

        return $code;
    }

    private function signTask(): void
    {
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => 2, 'is_active' => true]);
        DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, 'Informativa')->document_type_id, 'is_required' => true, 'sort_order' => 1]);
    }

    public function test_al_conferma_la_pratica_e_trasmessa_e_i_documenti_partono_in_firma(): void
    {
        $this->signTask();
        $code = $this->toSummary();

        $replies = $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('in_attesa', $loan->signature_status);
        $this->assertSame(1, SignatureRequest::count());
        Mail::assertSent(LoanSubmissionMail::class, fn ($m) => $m->hasTo('contratti@gbdenergy.example'));
        $this->assertStringContainsString('firma', implode(' ', array_map(fn ($r) => $r->body, $replies)));
    }

    public function test_l_evento_bpm_non_parte_al_conferma(): void
    {
        $this->signTask();
        $this->toSummary();

        $this->say('#conferma');

        Queue::assertNotPushed(\App\Jobs\SendBpmEvent::class);
    }

    public function test_se_la_produzione_fallisce_la_pratica_resta_trasmessa_con_errore_di_firma(): void
    {
        $this->signTask();
        $code = $this->toSummary();
        LoanRequest::where('code', $code)->update(['personal' => null]);

        $replies = $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('errore', $loan->signature_status);
        Mail::assertSent(LoanSubmissionMail::class);
        $this->assertStringContainsString('pannello', implode(' ', array_map(fn ($r) => $r->body, $replies)));
    }

    public function test_senza_il_flag_il_comportamento_resta_quello_di_oggi(): void
    {
        Company::where('name', 'GBD Energy')->update(['produce_documents' => false]);
        $code = $this->toSummary();

        $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertNull($loan->signature_status);
        $this->assertSame(0, SignatureRequest::count());
    }

    public function test_senza_task_per_lo_stato_la_pratica_si_comporta_come_senza_documenti(): void
    {
        $code = $this->toSummary();

        $this->say('#conferma');

        $loan = LoanRequest::where('code', $code)->first();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertNull($loan->signature_status);
    }
}
```

Nota: il perfezionamento ricava `personal` dai dati del dialogo; `LoanRequest::where(...)->update(['personal' => null])` nel test di errore simula dati mancanti subito prima del «Conferma»: se il motore riscrive `personal` al Conferma, usare invece un cliente senza email modificando la risposta `email` con un valore che passa la validazione del bot ma non quella del core (per esempio `a@b.c`), oppure rendere il modulo non compilabile eliminando il file del modulo dallo `Storage` fake: il test deve ottenere `signature_status = 'errore'` con la mail già inviata.

Run: `php artisan test tests/Feature/Core/PerfezionamentoConDocumentiTest.php` → FAIL.

- [ ] **Step 2: Motore**

In `ConversationEngine`:

```php
    /** Stato del core a cui appartiene il task dei documenti da firmare. */
    public const SIGN_STATE = 'ALLA FIRMA';
```

Iniettare `LoanDocumentProducer $documents` nel costruttore (dopo `$energy`). In `perfectedAttributes(array $data, ?Company $company = null)` l'ultimo array diventa:

```php
            'status' => $company?->producesDocuments() ? 'invio_istruttoria' : 'perfezionata',
```

e i due punti di chiamata (`mailLoan` e `$loan->update($this->perfectedAttributes($data))` in `completePerfezionamento`) passano `$company` (già calcolato come `Company::forWhatsApp($loan->agent_wa_number)`). In `completePerfezionamento`, sostituire dopo `$this->sendDocumentsToCrm($loan);`:

```php
        $company = Company::forWhatsApp($loan->agent_wa_number);
        $note = '';
        if ($company?->producesDocuments()) {
            try {
                $result = $this->documents->produce($loan, $company, self::SIGN_STATE);
                $note = $result['sent'] > 0 ? "\n✍️ Ho inviato i documenti al cliente per la firma con OTP: riceverà un SMS al cellulare indicato." : '';
            } catch (DocumentProductionException $e) {
                $note = "\n⚠️ {$e->getMessage()}";
            }
        } else {
            BpmEvents::dispatchPerfected($loan);
        }
        $this->close($conv, 'completata');

        return [Reply::text("✅ Pratica *{$loan->code}* ".($company?->producesDocuments() ? 'trasmessa in istruttoria.' : 'perfezionata e inviata in istruttoria al mediatore creditizio.').$note)];
```

(rimuovendo la precedente chiamata incondizionata a `BpmEvents::dispatchPerfected($loan)` e il `Reply` finale). Il messaggio di errore deve contenere «pannello» (vedi `DocumentProductionException`).

- [ ] **Step 3: Eseguire i test e la suite**

Run: `php artisan test tests/Feature/Core/PerfezionamentoConDocumentiTest.php` → PASS. `php artisan test` → tutta la suite, solo i 2 preesistenti (i test del finanziamento non devono cambiare: stato `perfezionata` e `BpmEvents` come prima).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Conversation/ConversationEngine.php tests/Feature/Core/PerfezionamentoConDocumentiTest.php
git commit -m "feat: al perfezionamento le company con documenti trasmettono e mandano in firma"
```

---

### Task 8: Listener di firma, seconda mail, evento BPM e avviso

**Files:**
- Create: `app/Listeners/HandleSignatureEvents.php`
- Create: `app/Mail/SignedDocumentsMail.php`
- Create: `resources/views/mail/signed-documents.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (registrare i listener)
- Test: `tests/Feature/Core/SignatureListenerTest.php`

**Interfaces:**
- Consumes: eventi del core `Unico\Core\Events\SignatureSent|SignatureCompleted|SignatureCancelled` (payload `SignatureRequest $request`, `Document $document`); `Document::documentable` (la `LoanRequest`); `Company::forWhatsApp`; `LoanEmailSender::recipient(?Company)`; `BpmEvents::dispatchPerfected(LoanRequest)`; `WhatsAppClient::send(string $to, Reply $reply): bool`.
- Produces: `HandleSignatureEvents::onSent(SignatureSent)`, `onCompleted(SignatureCompleted)`, `onCancelled(SignatureCancelled)`. A firma completata di tutti i documenti della pratica (nessun `Document` senza `is_signed`): `signature_status = firmata`, `signed_at`, stato `perfezionare`, mail `SignedDocumentsMail` all'istruttoria con i PDF firmati, `BpmEvents::dispatchPerfected`, avviso WhatsApp (errori ignorati). Idempotente: se `signed_at` è già valorizzato non fa nulla.
- Produces: `SignedDocumentsMail(LoanRequest $loan, array $documents)`.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Tests\Feature\Core;

use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Documents\LoanDocumentProducer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\SignatureRequest;
use Unico\Core\Models\Task;

class SignatureListenerTest extends CoreTestCase
{
    private function sent(int $docs = 1): array
    {
        Mail::fake();
        Queue::fake();
        $company = Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza', 'istruttoria_email' => 'gbd@example.com']);
        $task = Task::create(['name' => 'Utenza · ALLA FIRMA', 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'trigger_value' => 'Utenza',
            'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => 'ALLA FIRMA', 'company_id' => 2, 'is_active' => true]);
        foreach (range(1, $docs) as $i) {
            DocumentRequirement::create(['task_id' => $task->id, 'document_type_id' => $this->coreModule(null, "Modulo $i")->document_type_id, 'is_required' => true, 'sort_order' => $i]);
        }
        $loan = $this->makeLoan(['status' => 'invio_istruttoria']);
        app(LoanDocumentProducer::class)->produce($loan, $company, 'ALLA FIRMA');

        return [$loan->fresh(), $company];
    }

    private function complete(Document $document): void
    {
        $request = SignatureRequest::where('document_id', $document->id)->first();
        $document->update(['is_signed' => true, 'signed_at' => now()]);
        event(new SignatureCompleted($request, $document->fresh()));
    }

    public function test_a_firme_complete_la_pratica_passa_a_perfezionare_con_mail_e_evento(): void
    {
        [$loan] = $this->sent(1);

        $this->complete(Document::where('documentable_id', $loan->id)->first());

        $loan->refresh();
        $this->assertSame('perfezionare', $loan->status);
        $this->assertSame('firmata', $loan->signature_status);
        $this->assertNotNull($loan->signed_at);
        Mail::assertSent(SignedDocumentsMail::class, fn ($m) => $m->hasTo('gbd@example.com'));
        Queue::assertPushed(\App\Jobs\SendBpmEvent::class, 1);
    }

    public function test_con_piu_documenti_si_aspetta_l_ultima_firma(): void
    {
        [$loan] = $this->sent(2);
        $docs = Document::where('documentable_id', $loan->id)->get();

        $this->complete($docs[0]);
        $this->assertSame('invio_istruttoria', $loan->fresh()->status);
        Mail::assertNothingSent();

        $this->complete($docs[1]);
        $this->assertSame('perfezionare', $loan->fresh()->status);
    }

    public function test_un_evento_ripetuto_non_manda_due_mail_ne_due_eventi(): void
    {
        [$loan] = $this->sent(1);
        $doc = Document::where('documentable_id', $loan->id)->first();

        $this->complete($doc);
        $this->complete($doc);

        Mail::assertSent(SignedDocumentsMail::class, 1);
        Queue::assertPushed(\App\Jobs\SendBpmEvent::class, 1);
    }

    public function test_una_firma_annullata_o_scaduta_lascia_la_pratica_trasmessa(): void
    {
        [$loan] = $this->sent(1);
        $doc = Document::where('documentable_id', $loan->id)->first();
        $request = SignatureRequest::where('document_id', $doc->id)->first();

        event(new SignatureCancelled($request, $doc));

        $loan->refresh();
        $this->assertSame('invio_istruttoria', $loan->status);
        $this->assertSame('scaduta', $loan->signature_status);
        Mail::assertNothingSent();
    }

    public function test_un_documento_non_legato_a_una_pratica_si_ignora(): void
    {
        $this->sent(1);
        $module = $this->coreModule();
        $doc = Document::create(['documentable_type' => 'company', 'documentable_id' => 1, 'document_type_id' => $module->document_type_id, 'name' => 'Altro']);
        $request = SignatureRequest::create(['document_id' => $doc->id, 'provider' => 'fake', 'status' => 'signed']);

        event(new SignatureCompleted($request, $doc));

        $this->assertTrue(true);
    }
}
```

Nota: se `SignatureRequest::create` richiede altri campi obbligatori, aggiungerli guardando `show create table signature_requests` sul DB di test. `SignatureCancelled` copre annullo e scadenza: il listener distingue il motivo da `$request->status` (`Expired` → `scaduta`, `Declined` → `rifiutata`, altrimenti `scaduta`/`errore`); il test verifica il caso di default.

Run: `php artisan test tests/Feature/Core/SignatureListenerTest.php` → FAIL.

- [ ] **Step 2: Listener**

```php
<?php

namespace App\Listeners;

use App\Mail\SignedDocumentsMail;
use App\Models\Company;
use App\Models\LoanRequest;
use App\Services\Bpm\BpmEvents;
use App\Services\Conversation\Reply;
use App\Services\Crm\LoanEmailSender;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Unico\Core\Enums\SignatureRequestStatus;
use Unico\Core\Events\SignatureCancelled;
use Unico\Core\Events\SignatureCompleted;
use Unico\Core\Events\SignatureSent;
use Unico\Core\Models\Document;

/** Collega gli eventi di firma del core alla pratica di unicoagent. */
class HandleSignatureEvents
{
    public function onSent(SignatureSent $event): void
    {
        $this->loan($event->document)?->update(['signature_status' => 'in_attesa']);
    }

    public function onCancelled(SignatureCancelled $event): void
    {
        $loan = $this->loan($event->document);
        if (! $loan || $loan->signed_at) {
            return;
        }

        $loan->update(['signature_status' => match ($event->request->status) {
            SignatureRequestStatus::Declined => 'rifiutata',
            SignatureRequestStatus::Failed => 'errore',
            default => 'scaduta',
        }]);
    }

    public function onCompleted(SignatureCompleted $event): void
    {
        $loan = $this->loan($event->document);
        if (! $loan || $loan->signed_at) {
            return;
        }

        $documents = Document::where('documentable_type', 'loan_request')->where('documentable_id', $loan->id)->get();
        if ($documents->contains(fn (Document $d) => ! $d->is_signed)) {
            return;
        }

        $company = Company::forWhatsApp($loan->agent_wa_number);
        $loan->update(['signature_status' => 'firmata', 'signed_at' => now(), 'status' => 'perfezionare']);

        $this->mailSigned($loan, $company, $documents->all());
        BpmEvents::dispatchPerfected($loan);
        $this->notifyAgent($loan);
    }

    private function loan(Document $document): ?LoanRequest
    {
        return $document->documentable_type === 'loan_request' ? $document->documentable : null;
    }

    private function mailSigned(LoanRequest $loan, ?Company $company, array $documents): void
    {
        $to = app(LoanEmailSender::class)->recipient($company);
        if (! $to) {
            Log::warning('Documenti firmati: manca la casella dell\'istruttoria', ['loan' => $loan->code]);

            return;
        }

        try {
            Mail::to($to)->send(new SignedDocumentsMail($loan, $documents));
        } catch (\Throwable $e) {
            Log::error('Invio documenti firmati non riuscito', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }

    /** L'avviso parte solo se WhatsApp lo accetta (finestra di 24 ore aperta); un errore non blocca nulla. */
    private function notifyAgent(LoanRequest $loan): void
    {
        try {
            app(WhatsAppClient::class)->send($loan->agent_wa_number, Reply::text("✅ Pratica *{$loan->code}*: il cliente ha firmato i documenti. È pronta per il perfezionamento."));
        } catch (\Throwable $e) {
            Log::warning('Avviso di firma non inviato', ['loan' => $loan->code, 'exception' => $e::class]);
        }
    }
}
```

- [ ] **Step 3: Mail e registrazione**

`SignedDocumentsMail`:

```php
<?php

namespace App\Mail;

use App\Models\LoanRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** All'istruttoria: i documenti della pratica firmati dal cliente. */
class SignedDocumentsMail extends Mailable
{
    /** @param array<int,\Unico\Core\Models\Document> $documents */
    public function __construct(public LoanRequest $loan, public array $documents) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Documenti firmati · pratica {$this->loan->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.signed-documents');
    }

    /** @return array<int,Attachment> */
    public function attachments(): array
    {
        return collect($this->documents)->map(function ($d) {
            $media = $d->currentMedia();

            return $media ? Attachment::fromData(fn () => (string) stream_get_contents($media->stream()), $media->file_name)->withMime($media->mime_type) : null;
        })->filter()->values()->all();
    }
}
```

`resources/views/mail/signed-documents.blade.php`:

```blade
<p>La pratica <strong>{{ $loan->code }}</strong> è completa: il cliente ha firmato {{ count($documents) }} document{{ count($documents) === 1 ? 'o' : 'i' }}.</p>
<p>In allegato i PDF firmati. La pratica è nello stato «Da perfezionare».</p>
```

In `AppServiceProvider::boot()`:

```php
        \Illuminate\Support\Facades\Event::listen(\Unico\Core\Events\SignatureSent::class, [\App\Listeners\HandleSignatureEvents::class, 'onSent']);
        \Illuminate\Support\Facades\Event::listen(\Unico\Core\Events\SignatureCompleted::class, [\App\Listeners\HandleSignatureEvents::class, 'onCompleted']);
        \Illuminate\Support\Facades\Event::listen(\Unico\Core\Events\SignatureCancelled::class, [\App\Listeners\HandleSignatureEvents::class, 'onCancelled']);
```

- [ ] **Step 4: Test e suite**

Run: `php artisan test tests/Feature/Core/SignatureListenerTest.php` → PASS; `php artisan test` → solo i 2 preesistenti.

- [ ] **Step 5: Commit**

```bash
git add app/Listeners app/Mail/SignedDocumentsMail.php resources/views/mail/signed-documents.blade.php app/Providers/AppServiceProvider.php tests/Feature/Core/SignatureListenerTest.php
git commit -m "feat: listener di firma: pratica da perfezionare, mail dei firmati, evento BPM e avviso"
```

---

### Task 9: Pannello e «Stato pratiche»

**Files:**
- Modify: `app/Filament/Resources/LoanRequests/Schemas/LoanRequestInfolist.php`
- Modify: `app/Filament/Resources/LoanRequests/Pages/ViewLoanRequest.php` (azione «Reinvia per la firma»)
- Modify: `app/Filament/Resources/LoanRequests/Tables/LoanRequestsTable.php` (colonna firma)
- Modify: `app/Services/Conversation/ConversationEngine.php` (riga di firma in «Stato pratiche»)
- Test: `tests/Feature/Core/LoanRequestSignaturePanelTest.php`

**Interfaces:**
- Consumes: `LoanRequest::signature_status`, `LoanRequest::SIGNATURE_STATUSES`; `LoanDocumentProducer::produce`; `Company::forWhatsApp`.
- Produces: nell'infolist una sezione «Firma» (stato di firma, data di firma, numero documenti); azione `reinviaFirma` visibile se `signature_status` in `errore|scaduta|rifiutata` e la company produce documenti; nella lista del bot («Stato pratiche») la riga della pratica riporta lo stato di firma.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Tests\Feature\Core;

use App\Filament\Resources\LoanRequests\Pages\ViewLoanRequest;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

class LoanRequestSignaturePanelTest extends CoreTestCase
{
    public function test_la_pratica_mostra_lo_stato_di_firma(): void
    {
        $loan = $this->makeLoan(['status' => 'invio_istruttoria', 'signature_status' => 'in_attesa']);

        $this->actingAs(User::factory()->create());

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertSee('In attesa firma')->assertSee('Trasmessa in istruttoria');
    }

    public function test_reinvia_e_visibile_solo_dopo_un_errore_di_firma(): void
    {
        Company::create(['name' => 'GBD', 'produce_documents' => true, 'core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza']);
        $loan = $this->makeLoan(['signature_status' => 'in_attesa']);
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertActionHidden('reinviaFirma');

        $loan->update(['signature_status' => 'errore']);

        Livewire::test(ViewLoanRequest::class, ['record' => $loan->getKey()])->assertActionVisible('reinviaFirma');
    }
}
```

Run: `php artisan test tests/Feature/Core/LoanRequestSignaturePanelTest.php` → FAIL.

- [ ] **Step 2: Infolist, azione, colonna, bot**

`LoanRequestInfolist`: aggiungere una `Section::make('Firma')` con `TextEntry::make('signature_status')->label('Stato di firma')->badge()->formatStateUsing(fn (?string $state) => LoanRequest::SIGNATURE_STATUSES[$state] ?? '-')` e `TextEntry::make('signed_at')->label('Firmata il')->dateTime()->placeholder('-')`, visibile solo se `signature_status` è valorizzato.

`ViewLoanRequest::getHeaderActions()` (o l'equivalente esistente): aggiungere

```php
            \Filament\Actions\Action::make('reinviaFirma')->label('Reinvia per la firma')->icon('heroicon-o-paper-airplane')->requiresConfirmation()
                ->visible(fn () => in_array($this->record->signature_status, ['errore', 'scaduta', 'rifiutata'], true)
                    && \App\Models\Company::forWhatsApp($this->record->agent_wa_number)?->producesDocuments())
                ->action(function () {
                    try {
                        app(\App\Services\Documents\LoanDocumentProducer::class)->produce($this->record, \App\Models\Company::forWhatsApp($this->record->agent_wa_number), \App\Services\Conversation\ConversationEngine::SIGN_STATE);
                        \Filament\Notifications\Notification::make()->success()->title('Documenti reinviati per la firma')->send();
                    } catch (\App\Services\Documents\DocumentProductionException $e) {
                        \Filament\Notifications\Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
```

`LoanRequestsTable`: colonna `TextColumn::make('signature_status')->label('Firma')->badge()->formatStateUsing(fn (?string $state) => LoanRequest::SIGNATURE_STATUSES[$state] ?? '-')->toggleable()`.

`ConversationEngine`: nella riga di elenco pratiche (`$this->loanLine(...)`, vicino a `ConversationEngine.php:1335`) aggiungere in coda `($loan->signature_status ? ' · '.LoanRequest::SIGNATURE_STATUSES[$loan->signature_status] : '')`.

- [ ] **Step 3: Test e suite; commit**

Run: `php artisan test tests/Feature/Core/LoanRequestSignaturePanelTest.php` → PASS; `php artisan test` → solo i 2 preesistenti.

```bash
git add app tests/Feature/Core/LoanRequestSignaturePanelTest.php
git commit -m "feat: stato di firma nel pannello, azione di reinvio e riga in Stato pratiche"
```

---

### Task 10: Dati di GBD nel core e attivazione (con ok dell'utente)

**Files:**
- Modify: `database/seeders/GbdEnergySeeder.php` (company: flag e riferimenti; dati sulla connessione `core`)
- Test: `tests/Feature/Core/GbdCoreDataSeederTest.php`

**Interfaces:**
- Consumes: `Task` e `DocumentRequirement` del core (Task 1), `PraticaStato` del core.
- Produces: il seeder imposta su GBD `produce_documents = false` (attivato a mano dopo i controlli), `core_company_id = 2`, `core_tipo_prodotto = 'Utenza'`; e, sulla connessione `core` e solo se la tabella `tasks` ha `company_id`, crea in modo ripetibile: gli stati di `Utenza` `INVIO IN ISTRUTTORIA` e `ALLA FIRMA` e `PERFEZIONARE` (se mancanti, tabella `pratica_stati`, colonne `codice`, `tipo_finanziamento`, `name`, `ordine`); il task «Utenza · Inserita (GBD)» (`stato_pratica = Inserita`) con i requisiti già presenti per GBD nessuno; il task «Utenza · ALLA FIRMA (GBD)» (`stato_pratica = ALLA FIRMA`, `company_id = 2`) con il requisito del tipo documento 111 (informativa privacy 2025), obbligatorio, ordine 1. Il tipo e il modulo del contratto Edison si aggiungono quando c'è il PDF.

- [ ] **Step 1: Test che fallisce (DB di test del core)**

```php
<?php

namespace Tests\Feature\Core;

use App\Models\Company;
use Database\Seeders\GbdEnergySeeder;
use Unico\Core\Models\DocumentRequirement;
use Unico\Core\Models\PraticaStato;
use Unico\Core\Models\Task;

class GbdCoreDataSeederTest extends CoreTestCase
{
    public function test_il_seeder_crea_stati_task_e_requisiti_di_gbd_senza_duplicare(): void
    {
        $this->seed(GbdEnergySeeder::class);
        $this->seed(GbdEnergySeeder::class);

        foreach (['INVIO IN ISTRUTTORIA', 'ALLA FIRMA', 'PERFEZIONARE'] as $name) {
            $this->assertSame(1, PraticaStato::where('tipo_finanziamento', 'Utenza')->where('name', $name)->count(), $name);
        }
        $task = Task::where('company_id', 2)->where('trigger_subvalue', 'ALLA FIRMA')->where('trigger_value', 'Utenza')->first();
        $this->assertNotNull($task);
        $this->assertSame(1, Task::where('company_id', 2)->where('trigger_subvalue', 'ALLA FIRMA')->count());
        $this->assertSame(1, DocumentRequirement::where('task_id', $task->id)->count());
    }

    public function test_il_seeder_identifica_il_core_su_gbd_ma_non_attiva_la_produzione(): void
    {
        $this->seed(GbdEnergySeeder::class);

        $gbd = Company::where('name', 'GBD Energy')->first();
        $this->assertSame(2, (int) $gbd->core_company_id);
        $this->assertSame('Utenza', $gbd->core_tipo_prodotto);
        $this->assertFalse($gbd->produce_documents);
    }
}
```

Nota: il test usa il tipo documento 111 solo se esiste nel DB di test (`document_types.id = 111`): in caso contrario il seeder lo cerca per nome «NUOVA Infomativa privacy 2025 - Editato» (vedi Step 2) e, se manca, salta il requisito senza errore; il test deve quindi creare il tipo nel setUp se il DB di test non lo contiene.

Run: `php artisan test tests/Feature/Core/GbdCoreDataSeederTest.php` → FAIL.

- [ ] **Step 2: Seeder**

In `GbdEnergySeeder::run()`, dopo la creazione della company:

```php
        $company->forceFill(['core_company_id' => 2, 'core_tipo_prodotto' => 'Utenza'])->save();
        $this->seedCore($company);
```

e il metodo:

```php
    /** Stati, task e requisiti di GBD nel core: solo se lo schema del core è aggiornato (tasks.company_id). */
    private function seedCore(Company $company): void
    {
        $core = \Illuminate\Support\Facades\DB::connection(config('unico-core.connection'));
        $schema = \Illuminate\Support\Facades\Schema::connection(config('unico-core.connection'));
        if (! $schema->hasTable('tasks') || ! $schema->hasColumn('tasks', 'company_id')) {
            return;
        }

        foreach (['INVIO IN ISTRUTTORIA', 'ALLA FIRMA', 'PERFEZIONARE'] as $order => $name) {
            $core->table('pratica_stati')->updateOrInsert(
                ['tipo_finanziamento' => 'Utenza', 'name' => $name],
                ['codice' => $name, 'ordine' => 10 + $order, 'is_rejected' => false, 'is_working' => true, 'is_estingued' => false, 'colore' => 'gray', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $tasks = [
            'Inserita' => 'Utenza · Inserita (GBD)',
            'ALLA FIRMA' => 'Utenza · ALLA FIRMA (GBD)',
        ];
        $ids = [];
        foreach ($tasks as $stato => $name) {
            $core->table('tasks')->updateOrInsert(
                ['company_id' => $company->core_company_id, 'trigger_value' => 'Utenza', 'trigger_subfield' => 'stato_pratica', 'trigger_subvalue' => $stato],
                ['name' => $name, 'taskable' => 'pratica', 'trigger_field' => 'tipo_prodotto', 'trigger_state' => 'equals', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
            $ids[$stato] = $core->table('tasks')->where('company_id', $company->core_company_id)->where('trigger_subvalue', $stato)->value('id');
        }

        $privacy = $core->table('document_types')->where('name', 'like', '%Infomativa privacy 2025%')->value('id');
        if ($privacy !== null) {
            $core->table('document_requirements')->updateOrInsert(
                ['task_id' => $ids['ALLA FIRMA'], 'document_type_id' => $privacy],
                ['is_required' => true, 'sort_order' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
```

Se `pratica_stati` ha colonne obbligatorie diverse (vedi `show create table pratica_stati` sul DB di test), aggiungerle ai valori di `updateOrInsert`.

- [ ] **Step 3: Test e suite; commit**

Run: `php artisan test tests/Feature/Core/GbdCoreDataSeederTest.php` → PASS; `php artisan test` → solo i 2 preesistenti.

```bash
git add database/seeders/GbdEnergySeeder.php tests/Feature/Core/GbdCoreDataSeederTest.php
git commit -m "feat: GbdEnergySeeder scrive stati, task e requisiti di GBD nel core"
```

- [ ] **Step 4: Attivazione sul DB reale (SOLO con l'ok esplicito dell'utente)**

Non eseguire senza che l'utente abbia detto di procedere. Prima: backup del DB `unico_core_test` (`mysqldump` con le credenziali del `.env` nella scratchpad). Poi, in quest'ordine:

```bash
cd /var/www/html/unicoagent
php artisan unico-core:migrate --pretend          # mostra solo tasks.company_id
php artisan unico-core:migrate --force
php artisan migrate --force                        # colonne companies/loan_requests
php artisan db:seed --class=GbdEnergySeeder --force
```

Verificare in sola lettura: `tasks.company_id` esiste; gli stati `INVIO IN ISTRUTTORIA`, `ALLA FIRMA`, `PERFEZIONARE` esistono per `Utenza`; i 2 task di GBD e il requisito dell'informativa 2025. Poi, con un altro ok: `UPDATE companies SET produce_documents = 1 WHERE name = 'GBD Energy'` e `SIGNATURE_DRIVER=fake` già di default; per gli SMS veri impostare `SIGNATURE_DRIVER=yousign`, `YOUSIGN_API_KEY`, `YOUSIGN_WEBHOOK_SECRET` e registrare su Yousign il webhook `https://unicoagent.hassisto.com/signature/webhook/yousign`.

---

## Verifica finale

- [ ] `php artisan test` → tutta la suite verde tranne i 2 errori preesistenti elencati nei Global Constraints.
- [ ] Prova guidata con `SIGNATURE_DRIVER=fake` sul DB di test: perfezionamento GBD → pratica `invio_istruttoria` + `signature_status = in_attesa`; simulare `SignatureCompleted` → `perfezionare`, mail con PDF firmati, un solo evento BPM.
- [ ] Il perfezionamento del finanziamento (company 1) è identico a prima: stato `perfezionata`, evento BPM al Conferma.

## Self-review del piano

- **Copertura della spec:** §1 core → Task 1 (schema) e Task 10 (dati, spostati nel seeder di unicoagent: ruling dichiarato nei Global Constraints); §2 unicoagent → Task 2 (media library, alias morph), 3 (opt-in, colonne), 4 (resolver), 5 (dati), 6 (produttore), 7 (motore), 8 (listener), 9 (pannello e bot); §3 stati → Task 3, 7, 8; §4 errori → Task 6, 7, 8; Test → in ogni task; Rischi (DB condiviso) → Task 10 step 4 con ok esplicito.
- **Segnaposto:** nessuno; dove il codice dipende da dettagli del DB di test (colonne obbligatorie di `tasks`, `pratica_stati`, `signature_requests`) il piano indica il comando che le mostra.
- **Coerenza dei tipi:** `LoanTaskResolver::modules(Company, string)`, `LoanModuleData::for/signer`, `LoanDocumentProducer::produce(LoanRequest, Company, string): array{sent,skipped}`, `DocumentProductionException`, `ConversationEngine::SIGN_STATE`, `Company::producesDocuments()`, `LoanRequest::SIGNATURE_STATUSES`, `HandleSignatureEvents::onSent/onCompleted/onCancelled` sono definiti nei task che li producono e usati con le stesse firme dopo.
- **Limiti noti:** il contratto Edison non c'è: il Task 10 produce solo l'informativa; aggiungere tipo e modulo del contratto quando arriva il PDF. L'avviso WhatsApp dopo oltre 24 ore può fallire e viene ignorato.
