# GBD Energy: perfezionamento con lettura AI dei documenti — Piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** L'installatore GBD perfeziona su WhatsApp caricando bolletta, identità, visura ed estratto conto (o saltandoli); l'AI legge, il bot chiede solo ciò che manca, il cliente firma il plico Edison (simulato) con OTP tramite unico-core.

**Architecture:** Si riusano pipeline documenti, nodi `file`/`review`/`skip_if` e `LoanDocumentProducer`. Nel core (`/var/www/html/unico-core`) si aggiungono tipi di documento, tre moduli PDF fac-simile e il servizio Catasto (Openapi.it, simulato). In unicoagent un nuovo albero `perfezionamento` per GBD, tre nuovi tipi di lettura AI e un controllo Registro Imprese.

**Tech Stack:** Laravel, PHPUnit (`php artisan test`), pdftk, Anthropic SDK (lettura documenti), Openapi.it, unico-core (`Unico\Core\…`).

**Spec:** `docs/superpowers/specs/2026-10-16-gbd-perfezionamento-ai-design.md` (anche `2026-10-15-produzione-documenti-core-design.md`).

## Global Constraints
- Due repository: `/var/www/html/unico-core` (core) e `/var/www/html/unicoagent`. Si committa in ciascuno; unicoagent legge il core da `../unico-core` (path repository), quindi le modifiche al core sono subito visibili.
- Il DB condiviso `unico_core_test` è di sviluppo: nessun backup richiesto (decisione dell'utente). I seeder devono essere ripetibili.
- Limiti WhatsApp: titolo opzione max 24 caratteri, max 10 opzioni per nodo.
- Config senza closure nei valori (compatibile con `config:cache`); i `$choice/$text/$file/$summary` sono closure *locali* al file, come già in `config/energy.php`.
- Nessun dato personale nei log (solo il tipo di errore).
- Commit message in italiano, terminano con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Il preventivo (flow `richiesta`, catalogo, stimatore) è già implementato e **non committato**: il Task 0 lo committa per primo.
- Decisione: i documenti si leggono con l'AI solo dopo l'informativa verificata (regola esistente in `DocumentPipeline::run`, `privacy_verified_at`). Il flow GBD quindi parte, come il finanziamento, dall'invio dell'informativa firmata dal cliente.

## Review Focus
- File di tipo sbagliato (es. una bolletta al posto del documento d'identità): il bot lo dice e permette di rimandarlo o saltare (`TipoDocumentoCheck`).
- Foto illeggibile / AI non disponibile: nessun blocco, si prosegue con le domande a mano.
- Tutti i documenti saltati: l'albero chiede ogni dato a mano e arriva comunque al riepilogo.
- Registro Imprese irraggiungibile o P.IVA sconosciuta: errore gestito, si chiede ragione sociale e sede a mano.
- Catasto spento/non raggiungibile: il perfezionamento non si blocca.
- Intestatario IBAN diverso dal richiedente: segnalato (terzo intestatario), non bloccante.
- Cliente azienda: firma il legale rappresentante; le domande B2C (destinazione d'uso) non compaiono.
- Pagamento «Bollettino/carta»: IBAN e modulo SDD non richiesti né prodotti.

---

### Task 0: Committare il preventivo già fatto

**Files:** Modify (già modificati): `config/energy.php`, `app/Services/Conversation/ConversationEngine.php`, `app/Services/Loans/EnergyQuoteEstimator.php`, `database/seeders/GbdEnergySeeder.php`, `app/Filament/Resources/Products/ProductResource.php`, `tests/Feature/Core/PerfezionamentoConDocumentiTest.php`, `tests/Feature/Finanziamento/GbdEnergyFlowTest.php`, `tests/Feature/Finanziamento/ReviewFixesTest.php`, `tests/Feature/Loans/EnergyQuoteEstimatorTest.php`

- [ ] **Step 1: Verifica**

Run: `cd /var/www/html/unicoagent && php artisan test --compact tests/Feature/Finanziamento/GbdEnergyFlowTest.php tests/Feature/Loans/EnergyQuoteEstimatorTest.php tests/Feature/Finanziamento/ReviewFixesTest.php tests/Feature/Core/PerfezionamentoConDocumentiTest.php`
Expected: PASS (43 test).

- [ ] **Step 2: Commit**

```bash
git add config/energy.php app/Services/Conversation/ConversationEngine.php app/Services/Loans/EnergyQuoteEstimator.php database/seeders/GbdEnergySeeder.php app/Filament/Resources/Products/ProductResource.php tests
git commit -m "feat: preventivo GBD sul modello dell'IVR con offerte Edison e spesa mensile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 1 (core): generatore di PDF compilabili fac-simile

**Files:**
- Create: `/var/www/html/unico-core/src/Pdf/SimulatedFormPdf.php`
- Test: `/var/www/html/unico-core/tests/SimulatedFormPdfTest.php`

**Interfaces:**
- Produces: `SimulatedFormPdf::make(string $title, array $fieldNames): string` → bytes di un PDF A4 con titolo, avviso «FAC-SIMILE» e un campo di testo AcroForm per nome, leggibile e compilabile da pdftk.

- [ ] **Step 1: Test che fallisce**

```php
<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Pdf\SimulatedFormPdf;
use Unico\Core\Tests\Support\ReadsPdfFields;

class SimulatedFormPdfTest extends TestCase
{
    use ReadsPdfFields;

    public function test_il_pdf_ha_i_campi_richiesti_ed_e_compilabile(): void
    {
        $this->skipUnlessPdftk();
        $pdf = SimulatedFormPdf::make('FAC-SIMILE Contratto Edison', ['cliente_cognome', 'fornitura_pod']);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame(['cliente_cognome', 'fornitura_pod'], array_keys($this->readPdfFields($pdf)));
    }
}
```

- [ ] **Step 2: Verifica che fallisca**

Run: `cd /var/www/html/unico-core && vendor/bin/phpunit tests/SimulatedFormPdfTest.php`
Expected: FAIL (classe inesistente).

- [ ] **Step 3: Implementazione**

```php
<?php

namespace Unico\Core\Pdf;

/** PDF A4 di prova con campi di testo AcroForm, scritto a mano (nessuna libreria): serve ai moduli fac-simile. */
class SimulatedFormPdf
{
    /** @param list<string> $fieldNames */
    public static function make(string $title, array $fieldNames): string
    {
        $esc = fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s) ?: $s);

        $content = "BT /F1 16 Tf 40 800 Td ({$esc($title)}) Tj ET\nBT /F1 9 Tf 40 782 Td (Documento di prova: nessun valore contrattuale.) Tj ET\n";
        $objects = [];
        $widgetIds = [];
        $firstWidget = 6;

        foreach (array_values($fieldNames) as $i => $name) {
            $y = 750 - $i * 28;
            $content .= "BT /F1 9 Tf 40 ".($y + 6)." Td ({$esc($name)}) Tj ET\n";
            $id = $firstWidget + $i;
            $widgetIds[] = $id;
            $objects[$id] = "<< /Type /Annot /Subtype /Widget /FT /Tx /T ({$esc($name)}) /Rect [200 {$y} 540 ".($y + 18)."] /F 4 /P 4 0 R /DA (/F1 10 Tf 0 g) /MK << /BC [0] >> >>";
        }

        $refs = implode(' ', array_map(fn ($id) => "{$id} 0 R", $widgetIds));
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields ['.$refs.'] /DA (/F1 10 Tf 0 g) /DR << /Font << /F1 5 0 R >> >> /NeedAppearances true >> >>';
        $objects[2] = '<< /Type /Pages /Kids [4 0 R] /Count 1 >>';
        $objects[3] = '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream";
        $objects[4] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 3 0 R /Resources << /Font << /F1 5 0 R >> >> /Annots ['.$refs.'] >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $pdf."trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
```


- [ ] **Step 4: Verifica**

Run: `vendor/bin/phpunit tests/SimulatedFormPdfTest.php`
Expected: PASS. Se pdftk rifiuta il file, correggere gli offset/`/P` e rilanciare.

- [ ] **Step 5: Commit** (nel repo core)

```bash
cd /var/www/html/unico-core && git add src/Pdf/SimulatedFormPdf.php tests/SimulatedFormPdfTest.php
git commit -m "feat: generatore di PDF compilabili fac-simile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2 (core): tipi di documento Edison e moduli simulati

**Files:**
- Create: `/var/www/html/unico-core/src/Seeders/EdisonSimulatedDocumentsSeeder.php`
- Test: `/var/www/html/unico-core/tests/EdisonSimulatedDocumentsSeederTest.php` (stesso schema di `tests/PdfFormFillerTest.php`: `CoreDatabase::boot()`, disco `moduli` temporaneo)

**Interfaces:**
- Produces: `EdisonSimulatedDocumentsSeeder::run()` — ripetibile; crea i `document_types` con questi nomi esatti (gli altri task li cercano per nome): `Contratto di fornitura Edison`, `Modulo SDD Edison`, `Dichiarazione titolo di possesso immobile`, `Bolletta luce/gas`; imposta `ai_kind` `visura` sul tipo di id 34 (`VISURA_CAMERALE`) e `estratto_conto` su `ESTRATTO_CONTO` se `ai_kind` è nullo; crea per i tre moduli un `PdfModule` (`file_path` `module/edison-contratto.pdf`, `module/edison-sdd.pdf`, `module/edison-immobile.pdf`, `signature_slots` con lo slot `cliente` alla pagina 1) e i `PdfModuleField` con `source_key` (vedi tabella sotto).

Campi (`pdf_field_name` → `source_key`):
- contratto: `cliente_cognome`→`client.name`, `cliente_nome`→`client.first_name`, `cliente_cf`→`client.tax_code`, `ragione_sociale`→`client.company_name`, `partita_iva`→`client.vat_number`, `indirizzo_fornitura`→`fornitura.indirizzo`, `pod`→`fornitura.pod`, `pdr`→`fornitura.pdr`, `potenza_kw`→`fornitura.potenza`, `fornitore_attuale`→`fornitura.fornitore_attuale`, `data`→`pratica.oggi`.
- SDD: `intestatario`→`pagamento.intestatario`, `iban`→`pagamento.iban`, `cliente_cf`→`client.tax_code`, `data`→`pratica.oggi`.
- immobile: `cliente_cognome`→`client.name`, `cliente_nome`→`client.first_name`, `cliente_cf`→`client.tax_code`, `indirizzo_fornitura`→`fornitura.indirizzo`, `titolo_possesso`→`fornitura.titolo_possesso`, `data`→`pratica.oggi`.

- [ ] **Step 1: Test che fallisce** — esegue il seeder due volte e verifica: 4 tipi nuovi (una riga ciascuno), 3 `pdf_modules` attivi con `document_type_id` coerente, i file esistono sul disco `moduli`, i `PdfModuleField` hanno i `source_key` della tabella, e che `ModulePrinter::render` compili il contratto con `ResolvedModuleData(['client.name' => 'Rossi'])` (`readPdfFields` → `cliente_cognome` = `Rossi`).

- [ ] **Step 2: Verifica che fallisca**

Run: `vendor/bin/phpunit tests/EdisonSimulatedDocumentsSeederTest.php` — Expected: FAIL.

- [ ] **Step 3: Implementazione** — classe `Unico\Core\Seeders\EdisonSimulatedDocumentsSeeder extends Seeder`. `run()`:
  1. per ogni tipo nuovo `DocumentType::firstOrCreate(['name' => …], ['is_practice' => true, 'is_client' => true, 'is_person' => true, 'is_company' => true, 'is_signed' => <true per i 3 moduli, false per la bolletta>, 'is_template' => <true per i moduli>, 'nature' => 'template_fillable'|'incoming', 'doctype' => 'modulo'|null, 'ai_kind' => 'bolletta' solo per la bolletta])` (stessa forma di `informativa()` in `PerfezionamentoDocumentiSeeder`);
  2. `DocumentType::where('code', 'VISURA_CAMERALE')->whereNull('ai_kind')->update(['ai_kind' => 'visura'])` e analogo per `ESTRATTO_CONTO` → `estratto_conto`;
  3. per ogni modulo: `Storage::disk(config('unico-core.pdf.modules_disk'))->put($path, SimulatedFormPdf::make('FAC-SIMILE …', array_keys($fields)))` e `PdfModule::updateOrCreate(['file_path' => $path], ['name' => …, 'document_type_id' => …, 'is_active' => true, 'client_scope' => 'entrambi', 'signature_slots' => [['x' => 355, 'y' => 700, 'page' => 1, 'role' => 'client', 'slot' => 'cliente', 'width' => 142, 'height' => 26]]])` (verificare in `src/Models/PdfModule.php` il cast di `signature_slots`; se è stringa JSON usare `json_encode`) e `PdfModuleField::updateOrCreate(['pdf_module_id' => …, 'pdf_field_name' => …], ['pdf_field_type' => 'text', 'source_key' => …, 'is_required' => false])`.

- [ ] **Step 4: Verifica**

Run: `vendor/bin/phpunit tests/EdisonSimulatedDocumentsSeederTest.php` — Expected: PASS.

- [ ] **Step 5: Applicare al DB di sviluppo e committare**

```bash
cd /var/www/html/unicoagent && php artisan db:seed --class='Unico\Core\Seeders\EdisonSimulatedDocumentsSeeder' --no-interaction
php artisan tinker --execute '$c=DB::connection(config("unico-core.connection")); echo $c->table("document_types")->whereIn("name",["Contratto di fornitura Edison","Modulo SDD Edison","Dichiarazione titolo di possesso immobile","Bolletta luce/gas"])->count(), " ", $c->table("pdf_modules")->where("file_path","like","module/edison-%")->count();'
```
Expected: `4 3`. Se il seeder non gira da unicoagent (connessione `core`), lanciarlo con `Artisan::call('db:seed', ['--class' => …])` nel `GbdEnergySeeder` (Task 6).
Poi `cd /var/www/html/unico-core && git add -A && git commit -m "feat: tipi di documento Edison e moduli PDF fac-simile" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"`.

---

### Task 3 (core): servizio Catasto di Openapi.it (simulato)

**Files:**
- Create: `/var/www/html/unico-core/src/Clients/OpenApiCadastreService.php`
- Modify: `/var/www/html/unico-core/config/unico-core.php` (blocco `openapi`)
- Test: `/var/www/html/unico-core/tests/OpenApiCadastreServiceTest.php`

**Interfaces:**
- Produces: `OpenApiCadastreService::lookup(string $taxCode, string $address): array{live: bool, parcels: list<array{foglio: ?string, particella: ?string, subalterno: ?string, categoria: ?string, rendita: ?float}>}`. Con `catasto_live` spento restituisce `live=false` e una particella di esempio senza chiamare la rete; acceso, lancia `RuntimeException('Catasto: chiamata reale non ancora implementata.')` — come `OpenApiCreditScoreService`.

- [ ] **Step 1:** Leggere `src/Clients/OpenApiCreditScoreService.php` e il suo test e ricalcarne lo schema. Prima di scrivere la chiamata reale: leggere la definizione OpenAPI del prodotto Catasto su `console.openapi.com`/`developers.openapi.com` (endpoint, parametri, risposta) e annotare l'URL nel commento di classe; salvare una risposta di esempio come `tests/Fixtures/openapi-catasto.json` **solo se** la definizione la riporta.
- [ ] **Step 2: Test che fallisce**

```php
public function test_spento_restituisce_una_risposta_simulata_senza_chiamate(): void
{
    config(['unico-core.openapi.catasto_live' => false]);
    Http::fake();

    $r = (new OpenApiCadastreService)->lookup('RSSMRA80A01H501U', 'Via Roma 1, 00100 Roma');

    $this->assertFalse($r['live']);
    $this->assertNotEmpty($r['parcels']);
    Http::assertNothingSent();
}

public function test_acceso_senza_implementazione_reale_lancia_eccezione(): void
{
    config(['unico-core.openapi.catasto_live' => true]);
    $this->expectException(\RuntimeException::class);
    (new OpenApiCadastreService)->lookup('RSSMRA80A01H501U', 'Via Roma 1');
}
```
(adattare bootstrap Http/config al test del credit score).

- [ ] **Step 3: Implementazione**

```php
<?php

namespace Unico\Core\Clients;

use RuntimeException;

/** Dati catastali da Openapi.it (codice fiscale + indirizzo). Per ora simulato, come il credit score: vedi `openapi.catasto_live`. */
class OpenApiCadastreService
{
    public function lookup(string $taxCode, string $address): array
    {
        if (config('unico-core.openapi.catasto_live')) {
            throw new RuntimeException('Catasto: chiamata reale non ancora implementata.');
        }

        return ['live' => false, 'parcels' => [['foglio' => '1', 'particella' => '1', 'subalterno' => '1', 'categoria' => 'A/2', 'rendita' => 500.0]]];
    }
}
```
In `config/unico-core.php`, dentro `openapi`, aggiungere `'catasto_url' => env('OPENAPI_CATASTO_URL', 'https://catasto.openapi.com'), 'catasto_live' => (bool) env('OPENAPI_CATASTO_LIVE', false),` (l'URL va allineato alla definizione letta allo Step 1).

- [ ] **Step 4:** `vendor/bin/phpunit tests/OpenApiCadastreServiceTest.php` — Expected: PASS. Poi suite del core: `vendor/bin/phpunit` — nessun nuovo fallimento.
- [ ] **Step 5: Commit** (repo core): `feat: servizio Catasto di Openapi.it (simulato)`.

---

### Task 4 (unicoagent): nuovi tipi di lettura AI (bolletta, visura, estratto conto)

**Files:**
- Modify: `app/Services/Documents/AnthropicDocumentReader.php` (SYSTEM, LABELS, `schema()`)
- Modify: `app/Services/Documents/DocumentPipeline.php` (`KIND_CODES`, `LABELS`)
- Modify: `app/Services/Documents/DocumentChecker.php` (`EXPECTED`, `DETECTED`)
- Modify: `app/Services/Checks/EstraiDatiCheck.php`
- Modify: `config/finanziamento.php` (`document_checks`)
- Test: `tests/Feature/Documents/NuoviTipiLetturaTest.php`

**Interfaces:**
- Produces: tipi di lettura (`ai_kind` = codice documento) `bolletta`, `visura`, `estratto_conto`; campi AI nuovi: `pod`, `pdr`, `supply_address`, `committed_power`, `previous_supplier`, `company_name`, `vat_number`, `ateco`, `registered_office`, `legal_representative`, `iban`, `account_holder`. `EstraiDatiCheck` propone le chiavi del dialogo: bolletta → `pod`, `pdr`, `indirizzo_fornitura`, `potenza`, `fornitore_attuale`; visura → `ragione_sociale`, `partita_iva`, `residenza` (la sede legale); estratto_conto → `iban`, `intestatario_iban`.

- [ ] **Step 1: Test che fallisce**

```php
public function test_estrai_dati_propone_le_chiavi_della_bolletta(): void
{
    $ctx = new \App\Services\Checks\DocumentContext(
        new \App\Models\Attachment, new \App\Models\LoanRequest, 'bolletta',
        fn () => ['pod' => 'it001e12345678', 'pdr' => null, 'supply_address' => 'Via Roma 1, 00100 Roma', 'committed_power' => '3', 'previous_supplier' => 'Enel Energia'],
    );

    $result = (new \App\Services\Checks\EstraiDatiCheck)->inspect($ctx);

    $this->assertSame(['pod' => 'IT001E12345678', 'indirizzo_fornitura' => 'Via Roma 1, 00100 Roma', 'potenza' => '3', 'fornitore_attuale' => 'Enel Energia'], $result->proposals);
}

public function test_estrai_dati_propone_visura_ed_estratto_conto(): void
{
    $visura = new \App\Services\Checks\DocumentContext(new \App\Models\Attachment, new \App\Models\LoanRequest, 'visura',
        fn () => ['company_name' => 'ACME Srl', 'vat_number' => '12345678903', 'registered_office' => 'Via Po 2, 10100 Torino']);
    $this->assertSame(['ragione_sociale' => 'ACME Srl', 'partita_iva' => '12345678903', 'residenza' => 'Via Po 2, 10100 Torino'], (new \App\Services\Checks\EstraiDatiCheck)->inspect($visura)->proposals);

    $conto = new \App\Services\Checks\DocumentContext(new \App\Models\Attachment, new \App\Models\LoanRequest, 'estratto_conto',
        fn () => ['iban' => 'it60 x054 2811 1010 0000 0123 456', 'account_holder' => 'Mario Rossi']);
    $this->assertSame(['iban' => 'IT60X0542811101000000123456', 'intestatario_iban' => 'Mario Rossi'], (new \App\Services\Checks\EstraiDatiCheck)->inspect($conto)->proposals);
}

public function test_il_checker_riconosce_i_nuovi_tipi(): void
{
    $checker = new \App\Services\Documents\DocumentChecker;
    $this->assertSame([], $checker->typeIssues('bolletta', ['kind_detected' => 'bolletta']));
    $this->assertNotSame([], $checker->typeIssues('bolletta', ['kind_detected' => 'identita']));
}
```
Verificare in `DocumentCheckResult` il nome reale della proprietà delle proposte (`$result->proposals`) e correggere i test di conseguenza.

- [ ] **Step 2:** `php artisan test --compact tests/Feature/Documents/NuoviTipiLetturaTest.php` — Expected: FAIL.

- [ ] **Step 3: Implementazione**
  - `DocumentChecker::EXPECTED` += `'bolletta' => ['bolletta', 'bolletta luce o gas'], 'visura' => ['visura', 'visura camerale'], 'estratto_conto' => ['estratto_conto', 'estratto conto bancario']`; `DETECTED` += le tre chiavi con le stesse etichette.
  - `DocumentPipeline::KIND_CODES` += `'bolletta' => 'bolletta', 'visura' => 'visura', 'estratto_conto' => 'estratto_conto'`; `LABELS` += `'bolletta' => 'Bolletta', 'visura' => 'Visura camerale', 'estratto_conto' => 'Estratto conto'`.
  - `AnthropicDocumentReader`: `kind_detected` enum += `bolletta`, `visura`, `estratto_conto`; `LABELS` += le tre descrizioni; `SYSTEM` += «For a utility bill (bolletta) set pod/pdr (supply point codes), supply_address, committed_power in kW (number only) and previous_supplier. For a company registry extract (visura) set company_name, vat_number (11 digits), ateco, registered_office and legal_representative. For a bank statement or IBAN screenshot set iban and account_holder. Use null when not printed.»; `schema()` += le proprietà `$text` elencate in *Interfaces* (tutte nullable string, e sono in `required` perché `required = array_keys($properties)`).
  - `EstraiDatiCheck::inspect`: dopo il blocco comune, `match ($ctx->readerKind)`: per `bolletta`/`visura`/`estratto_conto` costruire `$proposals` dalla mappa *Interfaces* (maiuscolo senza spazi per `pod`, `pdr`, `iban`; `trim` degli altri; saltare i vuoti). Per questi tre tipi **non** applicare `COMMON` (la bolletta non porta cognome/nome da proporre come identità).
  - `config/finanziamento.php`, `document_checks` += `'bolletta' => ['tipo_documento', 'estrai_dati'], 'visura' => ['tipo_documento', 'estrai_dati'], 'estratto_conto' => ['tipo_documento', 'estrai_dati']`.
- [ ] **Step 4:** stesso comando — Expected: PASS. Poi `php artisan test --compact tests/Feature/Documents` — nessun nuovo fallimento.
- [ ] **Step 5: Commit** — `feat: lettura AI di bolletta, visura ed estratto conto`.

---

### Task 5 (unicoagent): condizioni di salto per profilo, riepilogo 🟢/🟡 e controllo Registro Imprese

**Files:**
- Modify: `app/Services/Conversation/ConversationEngine.php` (`shouldSkip`, `summary`)
- Create: `app/Services/Checks/RegistroImpreseCheck.php`
- Modify: `config/finanziamento.php` (`checks`: `'registro_imprese' => RegistroImpreseCheck::class`)
- Test: `tests/Feature/Finanziamento/ProfiloEProvenienzaTest.php`

**Interfaces:**
- Produces:
  - `skip_if: 'segment_not:azienda'` / `'segment_not:privato'`: salta il nodo se `loanRequest->answers['profilo']` (default `privato`) è diverso dal valore indicato.
  - nodo `summary` con `'provenance' => true`: ogni riga del riepilogo è preceduta da 🟢 se la chiave è tra i `PraticaField` confermati della pratica, 🟡 altrimenti; in coda una legenda «🟢 letto dai documenti · 🟡 inserito a mano».
  - `RegistroImpreseCheck` (`NodeCheck`, nome `registro_imprese`): su una P.IVA valida chiama `Unico\Core\Clients\OpenApiCompanyService::fetchCompany()` e ricava `ragione_sociale` e `residenza` (sede legale); in caso di errore o azienda non trovata **passa comunque** (`derives` vuoto per quel caso) così il bot chiede i dati a mano.

- [ ] **Step 1: Test che fallisce** — tre test: (a) con `loanRequest->answers = ['profilo' => 'azienda']` un nodo con `skip_if segment_not:azienda` non si salta e con `privato` sì (usare un flow minimo come in `FlowHeaderSkipTest`); (b) il riepilogo con `provenance` mostra 🟢 per una chiave confermata (`PraticaField::create([... 'status' => 'confermato'])`) e 🟡 per le altre; (c) `RegistroImpreseCheck` con `Http::fake` che risponde con `tests`-fixture IT-advanced del core (copiare `/var/www/html/unico-core/tests/Fixtures/openapi-it-advanced.json` in `tests/Fixtures/`) imposta `ragione_sociale` e `residenza`, e con risposta 404 passa senza derivati.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Finanziamento/ProfiloEProvenienzaTest.php` — Expected: FAIL.
- [ ] **Step 3: Implementazione**
  - `shouldSkip`: aggiungere il ramo
    ```php
    is_string($condition) && str_starts_with($condition, 'segment_not:') => (($conv->loanRequest?->answers['profilo'] ?? 'privato') !== substr($condition, 12)),
    ```
  - `summary()`: se `$def['provenance'] ?? false`, costruire le righe con `LoanRequest::describe` come oggi ma anteponendo il pallino; per sapere la chiave di ogni riga usare `PraticaField::confirmedValues($conv->loanRequest)` e iterare su `$conv->data` in ordine, chiamando `LoanRequest::describe([$key => $value], $conv->flow)` per ogni chiave (restituisce `label => valore`). Aggiungere la legenda in fondo.
  - `RegistroImpreseCheck`:
    ```php
    public function passes(string $value, CheckContext $ctx): bool
    {
        $vat = strtoupper(preg_replace('/\s+/', '', $value));
        if (! partitaIvaValida($vat)) {
            return false;
        }
        try {
            $company = app(\Unico\Core\Clients\OpenApiCompanyService::class)->fetchCompany($vat)['company'];
        } catch (\Throwable $e) {
            Log::warning('Registro Imprese non disponibile', ['exception' => $e::class]);

            return true;
        }
        if (! empty($company['name'])) {
            $ctx->set('ragione_sociale', (string) $company['name']);
        }
        if (! empty($company['office']['address'])) {
            $ctx->set('residenza', (string) $company['office']['address']);
        }

        return true;
    }
    ```
    con `derives()` che restituisce `['ragione_sociale', 'residenza']`. Verificare le chiavi reali di `office` in `OpenApiCompanyService::fetchCompany` (campo `address`) e adeguare.
- [ ] **Step 4:** stesso comando — Expected: PASS; poi `php artisan test --compact tests/Feature/Finanziamento` — nessun nuovo fallimento.
- [ ] **Step 5: Commit** — `feat: salti per profilo, riepilogo con provenienza e controllo Registro Imprese`.

---

### Task 6 (unicoagent): albero `perfezionamento` di GBD con documenti

**Files:**
- Modify: `config/energy.php` (flow `perfezionamento`)
- Modify: `app/Services/Conversation/ConversationEngine.php` (`completePerfezionamento`: `tipo_cliente` dal profilo)
- Modify: `database/seeders/GbdEnergySeeder.php` (core: tipi e requisiti del task `ALLA FIRMA`)
- Modify: `tests/Feature/Finanziamento/GbdEnergyFlowTest.php`, `tests/Feature/Core/PerfezionamentoConDocumentiTest.php` (percorso nuovo)
- Test: `tests/Feature/Finanziamento/GbdPerfezionamentoDocumentiTest.php`

**Interfaces:**
- Consumes: Task 4 (tipi `bolletta`/`visura`/`estratto_conto`), Task 5 (`segment_not:`, `provenance`, `registro_imprese`), Task 2 (nomi dei tipi nel core).
- Produces: nodi, nell'ordine (chiavi esatte; i campi nuovi finiscono in `personal`): `codice` → `conferma_pratica` → `informativa` (file, `skip_if privacy_received`, `analyze`, `ack`) → `doc_bolletta` → `doc_identita` → `doc_visura` (`segment_not:privato`… vedi sotto) → `doc_conto` → `attesa_documenti` (wait) → `rivedi_dati` (review) → domande mancanti → `pagamento` → … → `riepilogo` (`provenance`).

- [ ] **Step 1: Test che fallisce** — `GbdPerfezionamentoDocumentiTest extends ConversationTestCase` (seed `GbdEnergySeeder`, agente `393927968199`), con `DocumentReader` finto come in `tests/Feature/Documents/PraticaFieldsTest.php` (copiare quell'helper, restituendo per `bolletta` i campi `pod`, `supply_address`, `committed_power`, `previous_supplier`; per `identita` cognome, nome, codice fiscale, ecc.; `privacy_verified_at` impostato sulla pratica per evitare il gate). Casi:
  1. **tutto saltato (privato)**: dopo `informativa`, scrivere `salta` ai quattro caricamenti, `avanti`; il bot chiede in ordine CF, cognome, nome, … `pod`, … e arriva a `riepilogo` con tutte e sole le righe 🟡.
  2. **bolletta letta**: carica la bolletta, `avanti`, conferma i dati proposti: il bot **non** chiede più POD, indirizzo di fornitura, potenza e fornitore attuale e il riepilogo li mostra con 🟢.
  3. **azienda** (`#azienda` nel preventivo): compare `doc_visura`, la domanda `destinazione_uso` non compare; con solo P.IVA digitata (e `Http::fake` del Registro Imprese) `ragione_sociale` e sede si autocompilano.
  4. **pagamento bollettino**: nessuna domanda IBAN; `personal['pagamento']` = `bollettino`.
  5. **terzo intestatario**: IBAN letto con `intestatario_iban` ≠ cognome+nome → `personal['terzo_intestatario']` = `si`.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Finanziamento/GbdPerfezionamentoDocumentiTest.php` — Expected: FAIL.
- [ ] **Step 3: Albero** — in `config/energy.php`, sostituire `perfezionamento` mantenendo `$choice/$text/$file/$summary` e aggiungendo `$file` (stessa definizione di `config/finanziamento.php`). Nodi (testi in italiano, titoli ≤24 caratteri):
  - `codice`, `conferma_pratica` (→ `informativa`), `informativa` (`$file('informativa', 'Per procedere invia l\'informativa privacy firmata dal cliente (foto o PDF).', 'doc_bolletta', ['skip_if' => 'privacy_received', 'analyze' => true, 'ack' => true])`);
  - `doc_bolletta` = `$file('bolletta', 'Invia la bolletta attuale del cliente (foto o PDF), oppure scrivi «salta».', 'doc_identita', ['skippable' => true, 'analyze' => true, 'ack' => true])`;
  - `doc_identita` (`kind documento_identita`, `skippable`, → `doc_visura`);
  - `doc_visura` (`kind visura`, `skippable`, `skip_if segment_not:azienda`, → `doc_conto`) con prompt «Invia la visura camerale…»;
  - `doc_conto` (`kind estratto_conto`, `skippable`, → `attesa_documenti`) «Invia una foto dell'estratto conto o dell'IBAN…»;
  - `attesa_documenti` e `rivedi_dati` (→ `codice_fiscale`) come in `config/finanziamento.php`;
  - **dati personali**: `codice_fiscale`, `cognome`, `nome`, `verifica_cf`, `conferma_cf`, `luogo_nascita`, `residenza` (con `skip_if filled:residenza`), documento `tipo/numero/scadenza` (`skip_if filled:`) — copiare dall'albero attuale di `config/energy.php`, aggiungendo `skip_if filled:` dove manca; per l'azienda `ragione_sociale` e `partita_iva` (`skip_if segment_not:azienda` + `checks ['partita_iva', 'registro_imprese']`) vengono **prima** di `codice_fiscale` (che qui è il legale rappresentante);
  - **fornitura**: `fornitura_uguale`, `indirizzo_fornitura` (`skip_if filled:indirizzo_fornitura`), `pod` (`skip_if product_not:luce`) e `pdr` (`skip_if product_not:gas`): il ramo `product_not:` di `shouldSkip` salta il nodo anche quando il dato è già in `$data` (letto dalla bolletta e confermato in `rivedi_dati`), vedi Step 4;
  - `potenza` (`skip_if filled:potenza`; testo «Potenza impegnata in kW (di solito 3 per le abitazioni):», regex numero, `error`), `fornitore_attuale` (`skip_if filled:fornitore_attuale`, checks `fornitore_energia`);
  - **pagamento**: `pagamento` = `$choice('Pagamento', 'Come paga il cliente?', ['sdd' => 'Addebito in conto (SDD)', 'bollettino' => 'Bollettino o carta'], ['sdd' => 'iban', 'bollettino' => 'titolo_possesso'])`; `iban` (`skip_if filled:iban`, checks `iban`, → `intestatario_conto`); `intestatario_conto` = `$choice('Intestatario conto', 'Il conto è intestato al richiedente del contratto?', $yn, 'titolo_possesso', ['skip_if' => 'filled:intestatario_iban'])`; se `intestatario_iban` è letto dall'AI il confronto avviene nel passo successivo (vedi `terzo_intestatario` sotto);
  - **dati integrativi**: `titolo_possesso` = choice `proprieta` «Proprietà» / `affitto` «Affitto / Locazione» / `comodato` «Comodato / Altro»; `destinazione_uso` = choice `residenza` «Abitazione di residenza» / `seconda` «Seconda casa» con `skip_if segment_not:privato` (default proposto dal preventivo: vedi Step 4); `telefono` (checks `mobile`), `email` (checks `email`), `contatto_diretto`;
  - `riepilogo` = `$summary(['provenance' => true, 'prompt' => 'Invio il contratto al cliente per la firma?', 'options' => ['conferma' => 'Invia per la firma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla']])`.
  - `labels` del flow: aggiungere `potenza`, `titolo_possesso`, `destinazione_uso`, `pagamento`, `intestatario_iban`, `terzo_intestatario` con le etichette italiane.
- [ ] **Step 4: Motore**
  - `shouldSkip`, ramo `product_not:` come descritto sopra (salta anche se il dato è già in `$data`).
  - `completePerfezionamento` → `perfectedAttributes($data + ['tipo_cliente' => $loan->answers['profilo'] ?? 'privato'], $company)` solo quando `$loan->answers['profilo']` esiste.
  - Terzo intestatario e destinazione d'uso: subito prima di `perfectedAttributes`, nel metodo `completePerfezionamento`, calcolare `terzo_intestatario` = `si` se `intestatario_conto === 'no'` oppure (`intestatario_iban` valorizzato e non coerente con `cognome nome`, confronto per token come `DocumentChecker::compareNames` — esporre un metodo pubblico `namesMatch(?string $a, ?string $b): bool` e usarlo) e, se `destinazione_uso` manca ma `answers['residenza']` esiste, valorizzarla da lì (`principale` → `residenza`, `seconda` → `seconda`).
  - La mail a GBD (`mailLoan`) include già tutto `personal`; verificare che i nuovi campi compaiano nell'allegato dati (test).
- [ ] **Step 5: Seeder** — in `GbdEnergySeeder::seedCore`, dopo la creazione dei task: chiamare `(new \Unico\Core\Seeders\EdisonSimulatedDocumentsSeeder)->run()` (se la classe esiste), poi aggiungere a `$ids['ALLA FIRMA']` i requisiti `Contratto di fornitura Edison` (ordine 2), `Modulo SDD Edison` (3), `Dichiarazione titolo di possesso immobile` (4) con `updateOrInsert(['task_id', 'document_type_id'], ['is_required' => true, 'sort_order' => n, …])`, e al task `Inserita`: `Bolletta luce/gas`, `Visura camerale`, `Estratto Conto Bancario` (facoltativi). **Modulo SDD:** `LoanTaskResolver` non sa saltarlo se il pagamento è bollettino → aggiungere in `LoanTaskResolver::modules` un filtro `->reject(fn ($m) => $loan->personal['pagamento'] === 'bollettino' && str_contains($m->name, 'SDD'))`: cambiare la firma in `modules(Company $company, string $stato, ?LoanRequest $loan = null)` e adattare `LoanDocumentProducer::run` (`$this->tasks->modules($company, $stato, $loan)`); test dedicato.
- [ ] **Step 6: Aggiornare i test esistenti** — `GbdEnergyFlowTest` (`toSupplyPoint`, `test_i_percorsi_di_gbd_…`: numero percorsi invariato = 2) e `PerfezionamentoConDocumentiTest` (`toSummary`): usare il nuovo percorso (`informativa` saltata impostando `privacy_received_at`/`privacy_verified_at` sulla pratica prima, quattro `salta`, poi le domande a mano).
- [ ] **Step 7:** `php artisan test --compact tests/Feature/Finanziamento/GbdPerfezionamentoDocumentiTest.php tests/Feature/Finanziamento/GbdEnergyFlowTest.php tests/Feature/Core` — Expected: PASS.
- [ ] **Step 8: Commit** — `feat: perfezionamento GBD con caricamento documenti letti dall'AI`.

---

### Task 7 (unicoagent): dati del plico Edison

**Files:**
- Modify: `app/Services/Documents/LoanModuleData.php`
- Test: `tests/Feature/Core/LoanModuleDataTest.php` (esistente: aggiungere i casi)

**Interfaces:**
- Produces: nuove chiavi `fornitura.potenza`, `fornitura.titolo_possesso`, `pagamento.iban`, `pagamento.intestatario` (cognome nome del titolare, o `intestatario_iban` se presente), `client.destinazione_uso`, tutte `null` se assenti.

- [ ] **Step 1: Test che fallisce** — con `personal` contenente `potenza => '3'`, `titolo_possesso => 'affitto'`, `iban => 'IT60…'`, `intestatario_iban => 'Mario Rossi'` verificare le quattro chiavi; con `personal` privo di esse verificare `null` (mai stringa vuota).
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Core/LoanModuleDataTest.php` — Expected: FAIL.
- [ ] **Step 3:** in `for()` aggiungere, nell'array `$values`: `'fornitura.potenza' => $p['potenza'] ?? null, 'fornitura.titolo_possesso' => $p['titolo_possesso'] ?? null, 'pagamento.iban' => $p['iban'] ?? null, 'pagamento.intestatario' => ($p['intestatario_iban'] ?? null) ?: trim(($p['cognome'] ?? '').' '.($p['nome'] ?? '')), 'client.destinazione_uso' => $p['destinazione_uso'] ?? null`.
- [ ] **Step 4:** stesso comando — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: dati di fornitura, pagamento e immobile nel plico Edison`.

---

### Task 8 (unicoagent): dati catastali in background (solo privati)

**Files:**
- Create: `database/migrations/2026_10_16_000001_add_cadastre_to_loan_requests.php`
- Create: `app/Jobs/FetchCadastreData.php`
- Modify: `app/Models/LoanRequest.php` (cast `cadastre` => `encrypted:array`)
- Modify: `app/Services/Conversation/ConversationEngine.php` (`completePerfezionamento`: dispatch)
- Test: `tests/Feature/Finanziamento/CatastoInBackgroundTest.php`

**Interfaces:**
- Consumes: `OpenApiCadastreService::lookup()` (Task 3).
- Produces: `loan_requests.cadastre` (testo cifrato nullable, array con `live` e `parcels`); `FetchCadastreData::dispatch(int $loanId)`.

- [ ] **Step 1: Test che fallisce** — (a) con `Queue::fake()`, completare un perfezionamento privato → `FetchCadastreData` in coda, azienda → no; (b) eseguire `(new FetchCadastreData($loan->id))->handle(app(OpenApiCadastreService::class))` con `catasto_live` spento → `cadastre['live'] === false`; (c) con il servizio che lancia eccezione, `handle` non propaga e `cadastre` resta `null`.
- [ ] **Step 2:** `php artisan test --compact tests/Feature/Finanziamento/CatastoInBackgroundTest.php` — Expected: FAIL.
- [ ] **Step 3:** migrazione `$table->text('cadastre')->nullable();` con `down()` che la rimuove; cast; job:
  ```php
  public function handle(OpenApiCadastreService $service): void
  {
      $loan = LoanRequest::find($this->loanId);
      $p = $loan?->personal ?? [];
      if (! $loan || blank($p['codice_fiscale'] ?? null) || blank($p['residenza'] ?? null)) {
          return;
      }
      try {
          $loan->update(['cadastre' => $service->lookup($p['codice_fiscale'], $p['residenza'])]);
      } catch (\Throwable $e) {
          Log::warning('Catasto non disponibile', ['loan' => $loan->code, 'exception' => $e::class]);
      }
  }
  ```
  Nel motore, dopo `$loan->update($this->perfectedAttributes(...))`: `if (($loan->answers['profilo'] ?? 'privato') === 'privato' && $company?->type === 'ENERGY') { FetchCadastreData::dispatch($loan->id); }` dentro un `try/catch` come `archiveDocuments`. (Il lancio avviene alla conferma e non dopo il solo indirizzo: lì i dati sono definitivi; non blocca il flusso.)
- [ ] **Step 4:** `php artisan migrate --no-interaction` e il comando dello Step 2 — Expected: PASS.
- [ ] **Step 5: Commit** — `feat: dati catastali dei privati in background da unico-core`.

---

### Task 9: verifica finale e suite completa

- [ ] **Step 1:** `cd /var/www/html/unicoagent && php artisan test --compact` — Expected: nessun fallimento nuovo. I due già rossi prima di questo lavoro (`BpmEventsTest::test_nothing_is_sent_without_configuration_or_for_test_practices`, `ArchiviazioneDopoPerfezionamentoTest::test_un_errore_nell_avvio_dell_archiviazione_non_blocca_il_perfezionamento`) possono restare rossi: riportarli come noti.
- [ ] **Step 2:** `cd /var/www/html/unico-core && vendor/bin/phpunit` — Expected: PASS.
- [ ] **Step 3:** Prova end-to-end con `SIGNATURE_DRIVER=fake`: seed `GbdEnergySeeder`, percorso completo con file finti, produzione del plico: tre `Document` con PDF compilati e firma `in_attesa` (coperto dal test di Task 6 e da `PerfezionamentoConDocumentiTest`).
- [ ] **Step 4:** Aggiornare lo stato della spec (`Stato: implementata`) e committare.

---

## Self-Review
- **Copertura spec:** tipi Edison e moduli simulati (Task 1–2), Catasto Openapi simulato (3), nuovi tipi AI (4), Registro Imprese riusato (5), «Salta» + domande mancanti dopo i caricamenti (6), pagamento SDD/bollettino e terzo intestatario (6), dati integrativi (6), riepilogo 🟢/🟡 (5–6), plico e firma (6–7), Catasto in background (8).
- **Punto aperto da confermare:** l'informativa firmata resta il primo passo del perfezionamento per via del filtro privacy esistente sulla lettura AI (vincolo di progetto sopra); il Catasto parte alla conferma, non subito dopo l'indirizzo.
- **Rischio principale:** il formato del Catasto di Openapi.it non è verificato; il servizio resta simulato (`catasto_live` spento) e la parte live va completata dopo la lettura della definizione OpenAPI.
