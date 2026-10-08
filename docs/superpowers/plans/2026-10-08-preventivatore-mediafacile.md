# Preventivatore Mediafacile Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sostituire la stima casuale dell'importo ottenibile con due simulazioni (scenario migliore e peggiore) sul servizio Mediafacile, mostrando al produttore l'intervallo dell'importo erogato.

**Architecture:** La richiesta guadagna due domande (fascia d'età, sesso) e fasce chiuse per importo, anzianità e reddito. `MediafacileLoanEstimator` (dietro l'interfaccia `LoanEstimator` già esistente) costruisce i due scenari con `ScenarioBuilder`, li invia con `MediafacileClient` e salva l'esito in `quote_simulations`. Le liste valori del PDF stanno in tabelle compilate da un seeder. Se il servizio non risponde o non dà offerte valide, `ConversationEngine` ripiega sull'email all'istruttoria. Il driver si sceglie con `QUOTE_DRIVER` (`random` di base).

**Tech Stack:** Laravel, Eloquent, Http client, SimpleXML, PHPUnit (SQLite in memoria), Filament (scheda Azienda).

**Spec:** `docs/superpowers/specs/2026-10-08-preventivatore-mediafacile-design.md`

## Global Constraints
- Rata Cessione/Delega = `reddito_mensile ÷ 5`.
- Data di nascita = **1° gennaio** dell'anno `anno corrente − età`; fascia 40-50 nel 2026 → `01-01-1986` (migliore) e `01-01-1976` (peggiore).
- Date nel servizio in formato `MM-GG-ANNO` (`m-d-Y`); decimali con la virgola.
- `Data_decorrenza` = 2 mesi dopo la data della richiesta (solo Cessione/Delega).
- Dall'output si usa solo `Importo_erogato` delle offerte con `Errore=2`; `Importo_provvigione` e `Provvigione` ignorati; `Rinnovo` sempre `NO`.
- Fasce chiuse (solo codici e valori): età `eta_30` 20-30, `eta_40` 30-40, `eta_50` 40-50, `eta_60` 50-60, `eta_75` 60-75 · anzianità `anz_1` 0-1, `anz_3` 1-3, `anz_10` 3-10, `anz_20` 10-20, `anz_30` 20-30, `anz_40` 30-40 · reddito mensile `red_1500` 1.000-1.500, `red_2000` 1.500-2.000, `red_3000` 2.000-3.000, `red_oltre` 3.000-5.000 · importo `imp_5k` 1.000-5.000, `imp_10k` 5.000-10.000, `imp_20k` 10.000-20.000, `imp_35k` 20.000-35.000, `imp_oltre` 35.000-50.000.
- Sesso: se non risponde, `M`.
- Titolo di un'opzione WhatsApp: massimo 24 caratteri.
- Commit direttamente su `main` (nessun branch), con `git -c core.fileMode=false`; ogni commit termina con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus
1. Cliente giovane con anzianità alta (es. 20 anni e 40 di servizio): la data di assunzione non può precedere i 18 anni → test nel Task 3.
2. Durata scelta non ammessa dal servizio (es. 60 mesi tolti dalla tabella): si usa la più vicina, a parità la più bassa → Task 3.
3. Servizio irraggiungibile, HTTP 500 o XML non valido: l'utente non vede errori, la richiesta parte per email → Task 4 e Task 5.
4. Scenario senza offerte valide (tutte `Errore=1`): stesso ripiego → Task 5.
5. Pratiche con codici di fascia vecchi (`red_1000`, `anz_oltre`) o senza reddito (lavoro «altro»): nessun importo inventato, ripiego sull'email → Task 3.
6. Numeri nel formato `12.345,67` e `12345.67`; se il peggiore supera il migliore l'intervallo si ordina → Task 4 e Task 5.

---

### Task 1: Catalogo delle liste valori

**Files:**
- Create: `database/migrations/2026_10_08_000001_create_quote_catalog_tables.php`
- Create: `app/Models/QuoteContractType.php`, `QuoteEmploymentType.php`, `QuoteDuration.php`, `QuoteEmploymentMap.php`, `QuoteBandBound.php`, `QuoteSimulation.php`
- Create: `database/seeders/QuoteCatalogSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/QuoteCatalogTest.php`

**Interfaces:**
- Produces (usati dai Task 3 e 5): `QuoteContractType` (`value`, `label`, `product`), `QuoteEmploymentType` (`value`, `contracts` array|null), `QuoteDuration` (`contract`, `months`), `QuoteEmploymentMap` (`lavoro`, `ente_pensione`, `dimensione_azienda`, `tipo_rapporto`, `priority`), `QuoteBandBound` (`dimension`, `code`, `low`, `high`), `QuoteSimulation` (`loan_request_id`, `scenario`, `request` array, `response`, `offers_count`, `erogato_min`, `erogato_max`).

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

namespace Tests\Feature;

use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_i_tipi_di_contratto_sono_quelli_del_servizio(): void
    {
        $this->assertSame(['Cessione', 'Delega', 'Prestito'], QuoteContractType::orderBy('value')->pluck('value')->all());
        $this->assertSame('quinto', QuoteContractType::where('value', 'Cessione')->value('product'));
        $this->assertSame('personale', QuoteContractType::where('value', 'Prestito')->value('product'));
    }

    public function test_i_tipi_di_rapporto_e_i_pensionati_solo_con_cessione_e_prestito(): void
    {
        $this->assertSame(12, QuoteEmploymentType::count());
        $this->assertSame(['Cessione', 'Prestito'], QuoteEmploymentType::where('value', 'Pensionato INPS')->first()->contracts);
        $this->assertNull(QuoteEmploymentType::where('value', 'Statale')->first()->contracts);
    }

    public function test_le_durate_ammesse_per_contratto(): void
    {
        $this->assertSame(9, QuoteDuration::where('contract', 'Cessione')->count());
        $this->assertSame(9, QuoteDuration::where('contract', 'Delega')->count());
        $this->assertSame(10, QuoteDuration::where('contract', 'Prestito')->count());
        $this->assertTrue(QuoteDuration::where('contract', 'Prestito')->where('months', 12)->exists());
        $this->assertFalse(QuoteDuration::where('contract', 'Cessione')->where('months', 12)->exists());
    }

    public function test_gli_estremi_delle_fasce(): void
    {
        $this->assertSame([40, 50], $this->bounds('eta', 'eta_50'));
        $this->assertSame([30, 40], $this->bounds('anzianita', 'anz_40'));
        $this->assertSame([1000, 1500], $this->bounds('reddito', 'red_1500'));
        $this->assertSame([35000, 50000], $this->bounds('importo', 'imp_oltre'));
    }

    public function test_la_mappatura_delle_risposte_in_tipo_rapporto(): void
    {
        $this->assertSame('Pubblico', QuoteEmploymentMap::where('lavoro', 'dip_pub')->value('tipo_rapporto'));
        $this->assertSame('Pensionato INPS', QuoteEmploymentMap::where('lavoro', 'pensionato')->where('ente_pensione', 'inps')->value('tipo_rapporto'));
        $this->assertSame('Privato SPA', QuoteEmploymentMap::where('lavoro', 'dip_priv')->where('dimensione_azienda', 'oltre15')->value('tipo_rapporto'));
    }

    /** @return array{0:int,1:int} */
    private function bounds(string $dimension, string $code): array
    {
        $b = QuoteBandBound::where('dimension', $dimension)->where('code', $code)->firstOrFail();

        return [(int) $b->low, (int) $b->high];
    }
}
```

- [ ] **Step 2: Verificare che fallisca**

Run: `php artisan test --compact --filter=QuoteCatalogTest`
Expected: FAIL (classi `App\Models\Quote*` inesistenti).

- [ ] **Step 3: Scrivere la migrazione**

```php
<?php

use Database\Seeders\QuoteCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Liste valori del servizio di simulazione Mediafacile (specifica 3.8), modificabili quando arriva il tracciato definitivo. */
    public function up(): void
    {
        Schema::create('quote_contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('value')->unique();
            $table->string('label');
            $table->string('product')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('quote_employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('value')->unique();
            $table->json('contracts')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_durations', function (Blueprint $table) {
            $table->id();
            $table->string('contract');
            $table->unsignedSmallInteger('months');
            $table->timestamps();
            $table->unique(['contract', 'months']);
        });

        Schema::create('quote_employment_map', function (Blueprint $table) {
            $table->id();
            $table->string('lavoro');
            $table->string('ente_pensione')->nullable();
            $table->string('dimensione_azienda')->nullable();
            $table->string('tipo_rapporto');
            $table->unsignedSmallInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('quote_band_bounds', function (Blueprint $table) {
            $table->id();
            $table->string('dimension');
            $table->string('code');
            $table->unsignedInteger('low');
            $table->unsignedInteger('high');
            $table->timestamps();
            $table->unique(['dimension', 'code']);
        });

        Schema::create('quote_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_request_id')->constrained()->cascadeOnDelete();
            $table->string('scenario');
            $table->json('request');
            $table->longText('response')->nullable();
            $table->unsignedSmallInteger('offers_count')->default(0);
            $table->decimal('erogato_min', 12, 2)->nullable();
            $table->decimal('erogato_max', 12, 2)->nullable();
            $table->timestamps();
        });

        (new QuoteCatalogSeeder)->run();
    }

    public function down(): void
    {
        foreach (['quote_simulations', 'quote_band_bounds', 'quote_employment_map', 'quote_durations', 'quote_employment_types', 'quote_contract_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 4: Scrivere i modelli** (tutti con `protected $guarded = [];`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo_contratto del servizio di simulazione, collegato al prodotto interno (quinto, personale). */
class QuoteContractType extends Model
{
    protected $guarded = [];
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipo_rapporto del servizio; `contracts` = contratti con cui è ammesso (null = tutti). */
class QuoteEmploymentType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['contracts' => 'array'];
    }
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durata (in mesi) ammessa dal servizio per un tipo di contratto. */
class QuoteDuration extends Model
{
    protected $guarded = [];
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dalle risposte del produttore (situazione lavorativa, ente, dimensione azienda) al Tipo_rapporto; le colonne nulle valgono «qualunque». */
class QuoteEmploymentMap extends Model
{
    protected $table = 'quote_employment_map';

    protected $guarded = [];
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Estremi numerici di una fascia di risposta: età e anzianità in anni, reddito e importo in euro. */
class QuoteBandBound extends Model
{
    protected $guarded = [];
}
```

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Esito di una simulazione (scenario `best` o `worst`) per una richiesta: dati inviati, risposta grezza, importi erogati. */
class QuoteSimulation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['request' => 'array'];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
```

- [ ] **Step 5: Scrivere il seeder**

```php
<?php

namespace Database\Seeders;

use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use Illuminate\Database\Seeder;

/** Liste valori della specifica Mediafacile 3.8 e ipotesi di mappatura/fasce, da tarare. Ripetibile. */
class QuoteCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Cessione', 'Cessione del quinto', 'quinto'],
            ['Delega', 'Delegazione di pagamento', null],
            ['Prestito', 'Prestito personale', 'personale'],
        ] as [$value, $label, $product]) {
            QuoteContractType::updateOrCreate(['value' => $value], ['label' => $label, 'product' => $product]);
        }

        $pensionati = ['Cessione', 'Prestito'];
        foreach ([
            ['Statale', null, null], ['Pubblico', null, null], ['Privato SPA', null, null], ['Privato Altra forma', null, null],
            ['Privato Small Business', null, null], ['Medico', null, null],
            ['Pensionato INPS', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Pensionato INPDAP', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Pensionato altri enti', $pensionati, 'Solo con Cessione, Prestito (e Mutuo)'],
            ['Postale', null, null], ['Ferroviere', null, null],
            ['Parapubblico', null, 'Comprende anche le categorie Municipalizzate e Parastatali'],
        ] as [$value, $contracts, $note]) {
            QuoteEmploymentType::updateOrCreate(['value' => $value], ['contracts' => $contracts, 'note' => $note]);
        }

        foreach (['Cessione' => range(24, 120, 12), 'Delega' => range(24, 120, 12), 'Prestito' => range(12, 120, 12)] as $contract => $months) {
            foreach ($months as $m) {
                QuoteDuration::updateOrCreate(['contract' => $contract, 'months' => $m]);
            }
        }

        // lavoro, ente_pensione, dimensione_azienda, Tipo_rapporto, priorità (vince la riga più specifica)
        QuoteEmploymentMap::query()->delete();
        foreach ([
            ['dip_pub', null, null, 'Pubblico', 0],
            ['dip_priv', null, null, 'Privato Altra forma', 0],
            ['dip_priv', null, 'oltre15', 'Privato SPA', 2],
            ['dip_priv', null, 'fino15', 'Privato Small Business', 2],
            ['pensionato', 'inps', null, 'Pensionato INPS', 1],
            ['pensionato', 'exinpdap', null, 'Pensionato INPDAP', 1],
            ['pensionato', 'altro', null, 'Pensionato altri enti', 1],
            ['autonomo', null, null, 'Privato Small Business', 0],
            ['altro', null, null, 'Privato Altra forma', 0],
        ] as [$lavoro, $ente, $dimensione, $rapporto, $priority]) {
            QuoteEmploymentMap::create(['lavoro' => $lavoro, 'ente_pensione' => $ente, 'dimensione_azienda' => $dimensione, 'tipo_rapporto' => $rapporto, 'priority' => $priority]);
        }

        foreach ([
            'eta' => ['eta_30' => [20, 30], 'eta_40' => [30, 40], 'eta_50' => [40, 50], 'eta_60' => [50, 60], 'eta_75' => [60, 75]],
            'anzianita' => ['anz_1' => [0, 1], 'anz_3' => [1, 3], 'anz_10' => [3, 10], 'anz_20' => [10, 20], 'anz_30' => [20, 30], 'anz_40' => [30, 40]],
            'reddito' => ['red_1500' => [1000, 1500], 'red_2000' => [1500, 2000], 'red_3000' => [2000, 3000], 'red_oltre' => [3000, 5000]],
            'importo' => ['imp_5k' => [1000, 5000], 'imp_10k' => [5000, 10000], 'imp_20k' => [10000, 20000], 'imp_35k' => [20000, 35000], 'imp_oltre' => [35000, 50000]],
        ] as $dimension => $bands) {
            foreach ($bands as $code => [$low, $high]) {
                QuoteBandBound::updateOrCreate(['dimension' => $dimension, 'code' => $code], ['low' => $low, 'high' => $high]);
            }
        }
    }
}
```

In `DatabaseSeeder::run()` aggiungere `QuoteCatalogSeeder::class,` dopo `DocumentCatalogSeeder::class,`.

- [ ] **Step 6: Verificare che passi**

Run: `php artisan test --compact --filter=QuoteCatalogTest`
Expected: PASS (5 test).

- [ ] **Step 7: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: catalogo delle liste valori del preventivatore Mediafacile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Fasce chiuse, fascia d'età e sesso nella richiesta

**Files:**
- Modify: `config/finanziamento.php` (righe 15-21 variabili, nodo `importo` riga 116, `durata` riga 117, nodi `anzianita`, `reddito`, `pensione_netta`, `anni_attivita`, `reddito_autonomo`)
- Create: `database/migrations/2026_10_08_000003_closed_bands_age_and_sex_in_richiesta.php`
- Modify (test esistenti): `tests/Feature/SiglaProduttoreTest.php`, `Finanziamento/ImportiOttenibiliTest.php`, `RichiestaFlowTest.php`, `ModificaPreventivoTest.php`, `FlowHeaderSkipTest.php`, `Flows/FlowJumpsTest.php`, `Flows/FlowConfigExporterTest.php`
- Test: `tests/Feature/Finanziamento/FasceChiuseTest.php`, `tests/Feature/Flows/FasceChiuseMigrationTest.php`

**Interfaces:**
- Produces (usati dal Task 3): risposte salvate in `loan.answers` con chiavi `eta` (`eta_30`…`eta_75`), `sesso` (`sesso_m`|`sesso_f`, assente se saltata), `importo`, `anzianita`/`anni_attivita`, `reddito`/`pensione_netta`/`reddito_autonomo`, con i codici di fascia del Task 1.

- [ ] **Step 1: Scrivere i test che falliscono** (`tests/Feature/Finanziamento/FasceChiuseTest.php`)

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;

class FasceChiuseTest extends ConversationTestCase
{
    public function test_le_fasce_di_importo_non_sono_piu_aperte(): void
    {
        $options = $this->say('#menu_richiedi', '#personale')[0]->options;

        $this->assertSame('1.000 - 5.000 €', $options['imp_5k']);
        $this->assertSame('35.000 - 50.000 €', $options['imp_oltre']);
    }

    public function test_dopo_la_durata_si_chiedono_eta_e_sesso_per_quinto_e_personale(): void
    {
        $replies = $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60');
        $this->assertSame(['eta_30', 'eta_40', 'eta_50', 'eta_60', 'eta_75'], array_keys(end($replies)->options));

        $replies = $this->say('#eta_40');
        $this->assertSame(['sesso_m', 'sesso_f'], array_keys(end($replies)->options));

        $replies = $this->say('#sesso_f');
        $this->assertArrayHasKey('dip_priv', end($replies)->options, 'poi la situazione lavorativa');
        $this->assertSame('sesso_f', Conversation::first()->data['sesso']);
    }

    public function test_il_sesso_si_puo_saltare(): void
    {
        $replies = $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', 'salta');

        $this->assertArrayHasKey('dip_priv', end($replies)->options);
        $this->assertArrayNotHasKey('sesso', Conversation::first()->data);
    }

    public function test_il_finalizzato_non_chiede_eta_e_sesso(): void
    {
        $replies = $this->say('#menu_richiedi', '#finalizzato', '#imp_10k', '#m36');

        $this->assertArrayHasKey('dip_priv', end($replies)->options);
    }

    public function test_anzianita_e_reddito_hanno_fasce_chiuse(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv');
        $anzianita = $this->say('#indet');
        $this->assertSame(['anz_1', 'anz_3', 'anz_10', 'anz_20', 'anz_30', 'anz_40'], array_keys(end($anzianita)->options));

        $reddito = $this->say('#anz_10');
        $this->assertSame(['red_1500', 'red_2000', 'red_3000', 'red_oltre'], array_keys(end($reddito)->options));
        $this->assertSame('3.000 - 5.000 €', end($reddito)->options['red_oltre']);
    }
}
```

Run: `php artisan test --compact --filter=FasceChiuseTest` → Expected: FAIL (nodi `eta`/`sesso` assenti).

- [ ] **Step 2: Modificare `config/finanziamo.php`** — in `config/finanziamento.php`:

Sostituire le righe `$redditi` e `$anzianita` lasciandole (servono ad altri percorsi) e aggiungere subito sotto `$durate`:

```php
$importiPrestito = ['imp_5k' => '1.000 - 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => '35.000 - 50.000 €'];
$redditiConsumo = ['red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => '3.000 - 5.000 €'];
$anzianitaConsumo = ['anz_1' => 'Meno di 1 anno', 'anz_3' => '1 - 3 anni', 'anz_10' => '3 - 10 anni', 'anz_20' => '10 - 20 anni', 'anz_30' => '20 - 30 anni', 'anz_40' => '30 - 40 anni'];
$eta = ['eta_30' => '20 - 30 anni', 'eta_40' => '30 - 40 anni', 'eta_50' => '40 - 50 anni', 'eta_60' => '50 - 60 anni', 'eta_75' => '60 - 75 anni'];
```

Nei nodi:
- `importo`: usare `$importiPrestito` al posto di `$importi` (`prezzo_bene` resta con `$importi`).
- `durata`: sostituire `$consumo('lavoro') + [...]` con `['personale' => 'eta', 'quinto' => 'eta', 'finalizzato' => 'lavoro', 'leasing' => 'leasing_anticipo', 'aziendale' => 'az_finalita']`.
- subito dopo `durata` (l'ordine conta per la migrazione) aggiungere:

```php
                'eta' => $choice('Fascia d\'età', 'Qual è l\'età del cliente?', $eta, 'sesso'),
                'sesso' => $choice('Sesso', 'Qual è il sesso del cliente? Se salti, considero maschio.', ['sesso_m' => 'Maschio', 'sesso_f' => 'Femmina'], 'lavoro', ['skippable' => true]),
```
- `anzianita` e `anni_attivita`: usare `$anzianitaConsumo`; `az_anzianita` resta con `$anzianita`.
- `reddito`, `pensione_netta`, `reddito_autonomo`: usare `$redditiConsumo`.

Se `$consumo` non è più usato, lasciarlo definito solo se serve altrove (`grep -n '\$consumo' config/finanziamento.php`); altrimenti eliminarlo.

- [ ] **Step 3: Aggiornare i test esistenti**

```bash
sed -i "s/'#m24', '#dip_priv'/'#m24', '#eta_40', '#sesso_m', '#dip_priv'/; s/'#m60', '#dip_pub'/'#m60', '#eta_40', '#sesso_m', '#dip_pub'/; s/'#red_1000'/'#red_1500'/g" \
  tests/Feature/SiglaProduttoreTest.php tests/Feature/Finanziamento/ImportiOttenibiliTest.php tests/Feature/Finanziamento/RichiestaFlowTest.php \
  tests/Feature/Finanziamento/ModificaPreventivoTest.php tests/Feature/Finanziamento/FlowHeaderSkipTest.php
sed -i "s/Valore attuale: \*Fino a 5.000 €\*/Valore attuale: *1.000 - 5.000 €*/" tests/Feature/Finanziamento/ModificaPreventivoTest.php
sed -i "s/'imp_5k' => 'Fino a 5.000 €',/'imp_5k' => '1.000 - 5.000 €',/" tests/Feature/Flows/FlowConfigExporterTest.php
```

In `tests/Feature/Flows/FlowJumpsTest.php`, il test `test_si_cambia_da_cosa_dipendono_i_salti` non cambia (sostituisce tutti i salti della durata). Eseguire `php artisan test --compact --filter='RichiestaFlowTest|ImportiOttenibiliTest|ModificaPreventivoTest|FlowHeaderSkipTest|SiglaProduttoreTest|FlowConfigExporterTest|FlowJumpsTest|FasceChiuseTest'` e correggere le sequenze rimaste (per esempio altri `'#anz_oltre'` o `'#red_1000'`) leggendo gli errori.
Expected: PASS.

- [ ] **Step 4: Scrivere il test della migrazione** (`tests/Feature/Flows/FasceChiuseMigrationTest.php`)

```php
<?php

namespace Tests\Feature\Flows;

use App\Models\Flow;
use App\Services\Flows\FlowRepository;
use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FasceChiuseMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_10_08_000003_closed_bands_age_and_sex_in_richiesta.php'))->up();
        app(FlowRepository::class)->forget();
    }

    private function production(): Flow
    {
        return Flow::where('code', 'richiesta')->where('is_test', false)->firstOrFail();
    }

    /** Riporta l'albero come in produzione prima della modifica. */
    private function age(Flow $flow): void
    {
        $flow->nodes()->whereIn('code', ['eta', 'sesso'])->get()->each->delete();
        $flow->nodes()->where('code', 'durata')->firstOrFail()->jumps()->whereIn('when_value', ['personale', 'quinto'])->update(['go_to' => 'lavoro']);
        $flow->nodes()->orderBy('sort_order')->pluck('code')->each(fn ($code, $i) => $flow->nodes()->where('code', $code)->update(['sort_order' => $i + 1]));

        $importo = $flow->nodes()->where('code', 'importo')->firstOrFail();
        $importo->options()->delete();
        foreach (['imp_5k' => 'Fino a 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => 'Oltre 35.000 €'] as $code => $title) {
            $importo->options()->create(['code' => $code, 'title' => $title, 'sort_order' => $importo->options()->count() + 1]);
        }
        $reddito = $flow->nodes()->where('code', 'reddito')->firstOrFail();
        $reddito->options()->delete();
        foreach (['red_1000' => 'Fino a 1.000 €', 'red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => 'Oltre 3.000 €'] as $code => $title) {
            $reddito->options()->create(['code' => $code, 'title' => $title, 'sort_order' => $reddito->options()->count() + 1]);
        }
    }

    public function test_un_albero_vecchio_diventa_uguale_alla_configurazione(): void
    {
        $this->seed(FlowSeeder::class);
        $this->age($this->production());
        app(FlowRepository::class)->forget();
        $this->assertNotEquals(config('finanziamento.flows.richiesta'), app(FlowRepository::class)->flow('richiesta'));

        $this->migrate();

        $this->assertEquals(config('finanziamento.flows.richiesta'), app(FlowRepository::class)->flow('richiesta'));
        $this->assertSame(array_keys(config('finanziamento.flows.richiesta.nodes')), array_keys(app(FlowRepository::class)->flow('richiesta')['nodes']));
    }

    public function test_e_ripetibile_e_non_tocca_le_opzioni_modificate_a_mano(): void
    {
        $this->seed(FlowSeeder::class);
        $flow = $this->production();
        $this->age($flow);
        $flow->nodes()->where('code', 'importo')->first()->options()->where('code', 'imp_5k')->update(['title' => 'Fino a 5 mila']);

        $this->migrate();
        $this->migrate();

        $this->assertSame(1, DB::table('flow_nodes')->where('flow_id', $flow->id)->where('code', 'eta')->count(), 'nessun doppione');
        $this->assertSame('Fino a 5 mila', $flow->nodes()->where('code', 'importo')->first()->options()->where('code', 'imp_5k')->value('title'));
        $this->assertSame('3.000 - 5.000 €', $flow->nodes()->where('code', 'reddito')->first()->options()->where('code', 'red_oltre')->value('title'));
    }
}
```

Run: `php artisan test --compact --filter=FasceChiuseMigrationTest` → Expected: FAIL (file di migrazione mancante).

- [ ] **Step 5: Scrivere la migrazione `2026_10_08_000003_closed_bands_age_and_sex_in_richiesta.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_ANZIANITA = ['anz_1' => 'Meno di 1 anno', 'anz_3' => '1 - 3 anni', 'anz_10' => '3 - 10 anni', 'anz_oltre' => 'Oltre 10 anni'];

    private const OLD_REDDITI = ['red_1000' => 'Fino a 1.000 €', 'red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => 'Oltre 3.000 €'];

    private const OLD_IMPORTI = ['imp_5k' => 'Fino a 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => 'Oltre 35.000 €'];

    /**
     * Fasce chiuse per importo, anzianità e reddito, più le domande su età e sesso dopo la durata.
     * Agisce sugli alberi già importati (produzione e prove): le opzioni cambiano solo se sono ancora
     * quelle originali (se sono state modificate a mano non si toccano). Ripetibile.
     */
    public function up(): void
    {
        $config = config('finanziamento.flows.richiesta.nodes');

        foreach (DB::table('flows')->where('code', 'richiesta')->get() as $flow) {
            foreach ([
                'importo' => self::OLD_IMPORTI, 'anzianita' => self::OLD_ANZIANITA, 'anni_attivita' => self::OLD_ANZIANITA,
                'reddito' => self::OLD_REDDITI, 'pensione_netta' => self::OLD_REDDITI, 'reddito_autonomo' => self::OLD_REDDITI,
            ] as $code => $old) {
                $this->replaceOptions($flow->id, $code, $old, $config[$code]['options']);
            }

            $this->addAgeAndSex($flow->id, $config);
        }
    }

    public function down(): void
    {
        // Non si torna indietro: l'albero si ripristina dalla configurazione.
    }

    private function replaceOptions(int $flowId, string $code, array $old, array $new): void
    {
        $node = DB::table('flow_nodes')->where('flow_id', $flowId)->where('code', $code)->first();
        if (! $node) {
            return;
        }

        $current = DB::table('flow_node_options')->where('flow_node_id', $node->id)->orderBy('sort_order')->pluck('title', 'code')->all();
        if ($current !== $old) {
            return;
        }

        DB::table('flow_node_options')->where('flow_node_id', $node->id)->delete();
        $this->insertOptions($node->id, $new);
    }

    private function addAgeAndSex(int $flowId, array $config): void
    {
        $durata = DB::table('flow_nodes')->where('flow_id', $flowId)->where('code', 'durata')->first();
        if (! $durata) {
            return;
        }

        if (! DB::table('flow_nodes')->where('flow_id', $flowId)->where('code', 'eta')->exists()) {
            DB::table('flow_nodes')->where('flow_id', $flowId)->where('sort_order', '>', $durata->sort_order)->increment('sort_order', 2);
            $this->insertNode($flowId, 'eta', $config['eta'], $durata->sort_order + 1);
            $this->insertNode($flowId, 'sesso', $config['sesso'], $durata->sort_order + 2);
        }

        // Quinto e personale passano dalle nuove domande; il salto cambia solo se andava ancora alla situazione lavorativa.
        DB::table('flow_node_jumps')->where('flow_node_id', $durata->id)->whereIn('when_value', ['personale', 'quinto'])->where('go_to', 'lavoro')
            ->update(['go_to' => 'eta', 'updated_at' => now()]);
    }

    private function insertNode(int $flowId, string $code, array $node, int $order): void
    {
        $id = DB::table('flow_nodes')->insertGetId([
            'flow_id' => $flowId, 'code' => $code, 'type' => 'choice', 'label' => $node['label'], 'prompt' => $node['prompt'],
            'sort_order' => $order, 'skippable' => $node['skippable'] ?? false, 'save' => true, 'jump_by' => 'answer',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('flow_node_jumps')->insert(['flow_node_id' => $id, 'when_value' => '*', 'go_to' => $node['next'], 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->insertOptions($id, $node['options']);
    }

    private function insertOptions(int $nodeId, array $options): void
    {
        $position = 0;
        foreach ($options as $code => $title) {
            DB::table('flow_node_options')->insert(['flow_node_id' => $nodeId, 'code' => (string) $code, 'title' => $title, 'sort_order' => ++$position, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
```

- [ ] **Step 6: Verificare**

Run: `php artisan test --compact --filter='FasceChiuse'` poi `php artisan test --compact` (la suite intera: restano solo i 3 fallimenti preesistenti di `FilamentAdminTest` sui produttori).
Expected: PASS salvo quei 3.

- [ ] **Step 7: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: fasce chiuse e domande su età e sesso nella richiesta

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Costruzione dei due scenari

**Files:**
- Create: `app/Services/Loans/QuoteUnavailable.php`
- Create: `app/Services/Loans/Mediafacile/ScenarioBuilder.php`
- Modify: `config/finanziamento.php` (blocco `quote`)
- Test: `tests/Feature/Loans/ScenarioBuilderTest.php`

**Interfaces:**
- Produces: `QuoteUnavailable extends \RuntimeException`; `ScenarioBuilder::build(LoanRequest $loan): array{best: array<string,string>, worst: array<string,string>}` (parametri del servizio **senza** `Passkey`; chiavi come nel PDF, es. `Data_nascita`, `Importo_rata`); lancia `QuoteUnavailable` se il prodotto non è simulabile, se una fascia o la durata manca/è sconosciuta, se non c'è il reddito, o se il rapporto non è ammesso col contratto.

- [ ] **Step 1: Aggiungere in `config/finanziamento.php`**, accanto a `'crm'`:

```php
    // Preventivatore: driver `random` (simulazione) o `mediafacile` (servizio di simulazione, specifica 3.8).
    'quote' => [
        'driver' => env('QUOTE_DRIVER', 'random'),
        'timeout' => 15,
        'default_sex' => 'M',
        // Anzianità assunta quando non è chiesta (pensionati).
        'default_seniority_years' => 20,
    ],
```

- [ ] **Step 2: Scrivere il test che fallisce** (`tests/Feature/Loans/ScenarioBuilderTest.php`)

```php
<?php

namespace Tests\Feature\Loans;

use App\Models\LoanRequest;
use App\Models\QuoteDuration;
use App\Services\Loans\Mediafacile\ScenarioBuilder;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScenarioBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function loan(array $answers): LoanRequest
    {
        return new LoanRequest(['product' => $answers['prodotto'], 'answers' => $answers]);
    }

    private function quinto(array $override = []): LoanRequest
    {
        return $this->loan($override + [
            'prodotto' => 'quinto', 'importo' => 'imp_20k', 'durata' => 'm60', 'eta' => 'eta_50', 'sesso' => 'sesso_f',
            'lavoro' => 'dip_pub', 'contratto' => 'indet', 'anzianita' => 'anz_10', 'reddito' => 'red_2000',
        ]);
    }

    public function test_cessione_migliore_e_peggiore(): void
    {
        $s = (new ScenarioBuilder)->build($this->quinto());

        $this->assertSame([
            'Data_nascita' => '01-01-1986', 'Data_assunzione' => '01-01-2016', 'Data_decorrenza' => '12-08-2026', 'Sesso' => 'F',
            'Tipo_contratto' => 'Cessione', 'Tipo_rapporto' => 'Pubblico', 'Durata' => '60', 'Importo_rata' => '400,00', 'Rinnovo' => 'NO',
        ], $s['best']);
        $this->assertSame('01-01-1976', $s['worst']['Data_nascita']);
        $this->assertSame('01-01-2023', $s['worst']['Data_assunzione']);
        $this->assertSame('300,00', $s['worst']['Importo_rata']);
    }

    public function test_prestito_usa_importo_e_reddito_senza_decorrenza_e_il_sesso_di_ripiego(): void
    {
        $s = (new ScenarioBuilder)->build($this->loan([
            'prodotto' => 'personale', 'importo' => 'imp_10k', 'durata' => 'm36', 'eta' => 'eta_40',
            'lavoro' => 'dip_priv', 'contratto' => 'det', 'anzianita' => 'anz_3', 'reddito' => 'red_3000',
        ]));

        $this->assertSame('Prestito', $s['best']['Tipo_contratto']);
        $this->assertSame('Privato Altra forma', $s['best']['Tipo_rapporto']);
        $this->assertSame('M', $s['best']['Sesso']);
        $this->assertSame('36', $s['best']['Durata']);
        $this->assertSame('10000,00', $s['best']['Importo_richiesto']);
        $this->assertSame('3000,00', $s['best']['Reddito_richiedenti']);
        $this->assertSame('5000,00', $s['worst']['Importo_richiesto']);
        $this->assertSame('2000,00', $s['worst']['Reddito_richiedenti']);
        $this->assertArrayNotHasKey('Data_decorrenza', $s['best']);
        $this->assertArrayNotHasKey('Importo_rata', $s['best']);
    }

    public function test_il_rapporto_dipende_dalla_dimensione_dell_azienda(): void
    {
        $spa = (new ScenarioBuilder)->build($this->quinto(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'oltre15']));
        $small = (new ScenarioBuilder)->build($this->quinto(['lavoro' => 'dip_priv', 'dimensione_azienda' => 'fino15']));

        $this->assertSame('Privato SPA', $spa['best']['Tipo_rapporto']);
        $this->assertSame('Privato Small Business', $small['best']['Tipo_rapporto']);
    }

    public function test_il_pensionato_usa_la_pensione_e_l_anzianita_di_ripiego(): void
    {
        $s = (new ScenarioBuilder)->build($this->loan([
            'prodotto' => 'personale', 'importo' => 'imp_5k', 'durata' => 'm24', 'eta' => 'eta_75',
            'lavoro' => 'pensionato', 'ente_pensione' => 'inps', 'pensione_netta' => 'red_1500',
        ]));

        $this->assertSame('Pensionato INPS', $s['best']['Tipo_rapporto']);
        $this->assertSame('1500,00', $s['best']['Reddito_richiedenti']);
        $this->assertSame('01-01-2006', $s['best']['Data_assunzione'], 'nato nel 1966, 20 anni di anzianità');
        $this->assertSame('01-01-2006', $s['worst']['Data_assunzione'], 'nato nel 1951: stesso ripiego di 20 anni');
    }

    public function test_l_assunzione_non_precede_i_diciotto_anni(): void
    {
        $s = (new ScenarioBuilder)->build($this->quinto(['eta' => 'eta_30', 'anzianita' => 'anz_40']));

        $this->assertSame('01-01-2006', $s['best']['Data_nascita']);
        $this->assertSame('01-01-2024', $s['best']['Data_assunzione'], 'non prima dei 18 anni');
    }

    public function test_una_durata_non_ammessa_diventa_la_piu_vicina_a_parita_la_piu_bassa(): void
    {
        QuoteDuration::where('contract', 'Cessione')->where('months', 60)->delete();

        $s = (new ScenarioBuilder)->build($this->quinto());

        $this->assertSame('48', $s['best']['Durata']);
    }

    /** @dataProvider nonSimulabili */
    public function test_le_richieste_non_simulabili_lanciano_quote_unavailable(array $override): void
    {
        $this->expectException(QuoteUnavailable::class);

        (new ScenarioBuilder)->build($this->quinto($override));
    }

    public static function nonSimulabili(): array
    {
        return [
            'finalizzato' => [['prodotto' => 'finalizzato']],
            'fascia di reddito vecchia' => [['reddito' => 'red_1000']],
            'fascia di anzianità vecchia' => [['anzianita' => 'anz_oltre']],
            'senza età' => [['eta' => null]],
            'senza durata' => [['durata' => null]],
            'senza reddito (lavoro altro)' => [['lavoro' => 'altro', 'reddito' => null]],
        ];
    }
}
```

- [ ] **Step 3: Verificare che fallisca**

Run: `php artisan test --compact --filter=ScenarioBuilderTest`
Expected: FAIL (classi mancanti).

- [ ] **Step 4: Implementare**

```php
<?php

namespace App\Services\Loans;

/** Il servizio di preventivazione non può dare un importo per questa richiesta (dati insufficienti, non simulabile, errore o nessuna offerta). */
class QuoteUnavailable extends \RuntimeException {}
```

```php
<?php

namespace App\Services\Loans\Mediafacile;

use App\Models\LoanRequest;
use App\Models\QuoteBandBound;
use App\Models\QuoteContractType;
use App\Models\QuoteDuration;
use App\Models\QuoteEmploymentMap;
use App\Models\QuoteEmploymentType;
use App\Services\Loans\QuoteUnavailable;
use Carbon\CarbonImmutable;

/**
 * Dalle risposte a fasce della richiesta ai parametri del servizio di simulazione, per due scenari:
 * `best` (giovane, molta anzianità, reddito alto) e `worst` (anziano, poca anzianità, reddito basso).
 * Non include la Passkey. Lancia QuoteUnavailable se la richiesta non si può simulare.
 */
class ScenarioBuilder
{
    private const INCOME_NODES = ['pensionato' => 'pensione_netta', 'autonomo' => 'reddito_autonomo'];

    /** @return array{best: array<string,string>, worst: array<string,string>} */
    public function build(LoanRequest $loan): array
    {
        $answers = $loan->answers ?? [];
        $contract = QuoteContractType::where('product', $loan->product)->first()
            ?? throw new QuoteUnavailable("Il prodotto {$loan->product} non si simula.");

        $employment = $this->employmentType($answers, $contract->value);
        $months = $this->duration($contract->value, $answers['durata'] ?? null);
        $now = now()->toImmutable();

        return [
            'best' => $this->scenario($answers, $contract->value, $employment, $months, $now, best: true),
            'worst' => $this->scenario($answers, $contract->value, $employment, $months, $now, best: false),
        ];
    }

    /** @return array<string,string> */
    private function scenario(array $answers, string $contract, string $employment, int $months, CarbonImmutable $now, bool $best): array
    {
        $age = $this->bound('eta', $answers['eta'] ?? null, low: $best);
        $birth = $now->setDate($now->year - $age, 1, 1)->startOfDay();

        $seniorityCode = $answers['anzianita'] ?? $answers['anni_attivita'] ?? null;
        $seniority = $seniorityCode !== null
            ? $this->bound('anzianita', $seniorityCode, low: ! $best)
            : (int) config('finanziamento.quote.default_seniority_years', 20);
        $hired = max($now->setDate($now->year - $seniority, 1, 1)->startOfDay(), $birth->addYears(18));

        $income = $this->bound('reddito', $answers[self::INCOME_NODES[$answers['lavoro'] ?? ''] ?? 'reddito'] ?? null, low: ! $best);

        $common = [
            'Data_nascita' => $this->date($birth),
            'Data_assunzione' => $this->date($hired),
        ];

        if ($contract === 'Prestito') {
            return $common + [
                'Sesso' => $this->sex($answers),
                'Tipo_contratto' => $contract,
                'Tipo_rapporto' => $employment,
                'Durata' => (string) $months,
                'Importo_richiesto' => $this->money($this->bound('importo', $answers['importo'] ?? null, low: ! $best)),
                'Reddito_richiedenti' => $this->money($income),
            ];
        }

        return $common + [
            'Data_decorrenza' => $this->date($now->addMonthsNoOverflow(2)),
            'Sesso' => $this->sex($answers),
            'Tipo_contratto' => $contract,
            'Tipo_rapporto' => $employment,
            'Durata' => (string) $months,
            'Importo_rata' => $this->money($income / 5),
            'Rinnovo' => 'NO',
        ];
    }

    private function employmentType(array $answers, string $contract): string
    {
        $type = QuoteEmploymentMap::where('lavoro', $answers['lavoro'] ?? '')
            ->where(fn ($q) => $q->whereNull('ente_pensione')->orWhere('ente_pensione', $answers['ente_pensione'] ?? ''))
            ->where(fn ($q) => $q->whereNull('dimensione_azienda')->orWhere('dimensione_azienda', $answers['dimensione_azienda'] ?? ''))
            ->orderByDesc('priority')->value('tipo_rapporto')
            ?? throw new QuoteUnavailable('Situazione lavorativa non riconosciuta.');

        $allowed = QuoteEmploymentType::where('value', $type)->first()?->contracts;
        if ($allowed !== null && ! in_array($contract, $allowed, true)) {
            throw new QuoteUnavailable("{$type} non è ammesso con {$contract}.");
        }

        return $type;
    }

    private function duration(string $contract, ?string $code): int
    {
        if ($code === null || ! preg_match('/^m(\d+)$/', $code, $m)) {
            throw new QuoteUnavailable('Durata mancante.');
        }

        $wanted = (int) $m[1];
        $closest = QuoteDuration::where('contract', $contract)->orderBy('months')->pluck('months')
            ->sortBy(fn ($months) => abs($months - $wanted))->first();

        return $closest ?? throw new QuoteUnavailable("Nessuna durata ammessa per {$contract}.");
    }

    /** Estremo basso o alto della fascia (anni o euro). */
    private function bound(string $dimension, ?string $code, bool $low): int
    {
        $band = $code !== null ? QuoteBandBound::where('dimension', $dimension)->where('code', $code)->first() : null;

        return (int) ($band?->{$low ? 'low' : 'high'} ?? throw new QuoteUnavailable("Fascia {$dimension} mancante o sconosciuta."));
    }

    private function sex(array $answers): string
    {
        return ($answers['sesso'] ?? null) === 'sesso_f' ? 'F' : (($answers['sesso'] ?? null) === 'sesso_m' ? 'M' : (string) config('finanziamento.quote.default_sex', 'M'));
    }

    private function date(CarbonImmutable $date): string
    {
        return $date->format('m-d-Y');
    }

    private function money(float|int $value): string
    {
        return number_format($value, 2, ',', '');
    }
}
```

`sortBy` sulla collezione è stabile: a parità di distanza resta il primo in ordine crescente di mesi, cioè la durata più bassa.

- [ ] **Step 5: Verificare che passi**

Run: `php artisan test --compact --filter=ScenarioBuilderTest`
Expected: PASS (6 test più i 6 casi non simulabili).

- [ ] **Step 6: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: scenari migliore e peggiore per il preventivatore Mediafacile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Client HTTP e lettura della risposta

**Files:**
- Create: `app/Services/Loans/Mediafacile/MediafacileClient.php`
- Test: `tests/Feature/Loans/MediafacileClientTest.php`

**Interfaces:**
- Produces: `MediafacileClient::request(string $url, string $passkey, array $params): string` (corpo grezzo; `QuoteUnavailable` se irraggiungibile o HTTP non 2xx); `MediafacileClient::parse(string $xml): array` → lista di `['valid' => bool, 'erogato' => float]`, una per elemento che contiene `Importo_erogato`; `valid` = `Errore` uguale a `2`; `QuoteUnavailable` se l'XML non è valido. **Nomi degli elementi e codifica della richiesta (form) sono ipotesi** finché non arriva il tracciato: questa classe è l'unico punto da cambiare.

- [ ] **Step 1: Scrivere il test che fallisce**

```php
<?php

namespace Tests\Feature\Loans;

use App\Services\Loans\Mediafacile\MediafacileClient;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileClientTest extends TestCase
{
    private const XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Offerte>
  <Offerta><ID>1</ID><Importo_erogato>12.345,67</Importo_erogato><Errore>2</Errore><Stato></Stato></Offerta>
  <Offerta><ID>2</ID><Importo_erogato>10000.50</Importo_erogato><Errore>2</Errore></Offerta>
  <Offerta><ID>3</ID><Importo_erogato>0</Importo_erogato><Errore>1</Errore><Stato>Importo fuori limiti</Stato></Offerta>
</Offerte>
XML;

    public function test_legge_gli_importi_nei_due_formati_e_distingue_le_offerte_valide(): void
    {
        $this->assertSame([
            ['valid' => true, 'erogato' => 12345.67],
            ['valid' => true, 'erogato' => 10000.5],
            ['valid' => false, 'erogato' => 0.0],
        ], MediafacileClient::parse(self::XML));
    }

    public function test_un_xml_non_valido_o_senza_offerte(): void
    {
        $this->assertSame([], MediafacileClient::parse('<Offerte/>'));

        $this->expectException(QuoteUnavailable::class);
        MediafacileClient::parse('questo non è xml');
    }

    public function test_invia_passkey_e_parametri_in_post_e_restituisce_il_corpo(): void
    {
        Http::fake(['crm.example.com/*' => Http::response(self::XML)]);

        $body = (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', ['Tipo_contratto' => 'Cessione', 'Durata' => '60']);

        $this->assertSame(self::XML, $body);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://crm.example.com/ws/offerte'
            && $r['Passkey'] === 'KEY' && $r['Tipo_contratto'] === 'Cessione' && $r['Durata'] === '60');
    }

    public function test_un_errore_http_o_di_connessione_diventa_quote_unavailable(): void
    {
        Http::fake(['crm.example.com/*' => Http::response('boom', 500)]);
        try {
            (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', []);
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            $this->addToAssertionCount(1);
        }

        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->expectException(QuoteUnavailable::class);
        (new MediafacileClient)->request('https://crm.example.com/ws/offerte', 'KEY', []);
    }
}
```

- [ ] **Step 2: Verificare che fallisca**

Run: `php artisan test --compact --filter=MediafacileClientTest`
Expected: FAIL (classe mancante).

- [ ] **Step 3: Implementare**

```php
<?php

namespace App\Services\Loans\Mediafacile;

use App\Services\Loans\QuoteUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Chiamata HTTP al servizio di simulazione e lettura della risposta XML.
 * Tracciato della richiesta (form) e nomi degli elementi: ipotesi dalla specifica sommaria 3.8,
 * da verificare quando arriva il tracciato definitivo. È l'unico punto che dipende da quei dettagli.
 */
class MediafacileClient
{
    /** @param  array<string,string>  $params  senza Passkey */
    public function request(string $url, string $passkey, array $params): string
    {
        try {
            $response = Http::asForm()->timeout((int) config('finanziamento.quote.timeout', 15))->post($url, ['Passkey' => $passkey] + $params);
        } catch (ConnectionException $e) {
            throw new QuoteUnavailable('Servizio di simulazione non raggiungibile.', 0, $e);
        }

        if ($response->failed()) {
            throw new QuoteUnavailable("Il servizio di simulazione ha risposto {$response->status()}.");
        }

        return $response->body();
    }

    /** @return list<array{valid: bool, erogato: float}> una voce per ogni elemento che contiene Importo_erogato */
    public static function parse(string $raw): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new QuoteUnavailable('Risposta del servizio di simulazione non valida.');
        }

        $offers = [];
        foreach ($xml->xpath('//Importo_erogato/..') ?: [] as $node) {
            $offers[] = ['valid' => trim((string) $node->Errore) === '2', 'erogato' => self::number((string) $node->Importo_erogato)];
        }

        return $offers;
    }

    /** «12.345,67» (italiano) oppure «12345.67». */
    private static function number(string $value): float
    {
        $value = trim($value);

        return (float) (str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value);
    }
}
```

- [ ] **Step 4: Verificare che passi**

Run: `php artisan test --compact --filter=MediafacileClientTest`
Expected: PASS (4 test).

- [ ] **Step 5: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: client HTTP e lettura XML del servizio di simulazione Mediafacile

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Stima, passkey, driver e ripiego sull'email

**Files:**
- Create: `app/Services/Loans/MediafacileLoanEstimator.php`
- Create: `database/migrations/2026_10_08_000002_add_preventivatore_passkey_to_companies_table.php`
- Modify: `app/Models/Company.php` (cast), `app/Filament/Resources/Companies/Schemas/CompanyForm.php` (campo), `app/Providers/AppServiceProvider.php` (binding), `app/Services/Conversation/ConversationEngine.php` (`outcomeText`)
- Test: `tests/Feature/Loans/MediafacileLoanEstimatorTest.php`, `tests/Feature/Finanziamento/QuoteFallbackTest.php`

**Interfaces:**
- Consumes: `ScenarioBuilder::build`, `MediafacileClient::request/parse` (Task 3 e 4), `QuoteSimulation` (Task 1), `Company::forWhatsApp` (esistente), `Company::hasQuoteCrm()` (esistente).
- Produces: `MediafacileLoanEstimator implements LoanEstimator` (`estimate(LoanRequest): array{min:int,max:int}`, lancia `QuoteUnavailable`); colonna `companies.preventivatore_passkey` (cifrata).

- [ ] **Step 1: Migrazione e cast**

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
            // Passkey del servizio di simulazione Mediafacile, usata con l'URL del preventivatore.
            $table->text('preventivatore_passkey')->nullable()->after('url_preventivatore');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('preventivatore_passkey'));
    }
};
```

In `Company::casts()` aggiungere `'preventivatore_passkey' => 'encrypted',`.

In `CompanyForm`, dopo il campo `url_preventivatore`:

```php
                    TextInput::make('preventivatore_passkey')->label('Passkey del preventivatore')->password()->revealable()->maxLength(255)
                        ->helperText('Fornita con il servizio di simulazione; serve solo se il preventivatore è quello Mediafacile.'),
```

- [ ] **Step 2: Scrivere i test che falliscono**

`tests/Feature/Loans/MediafacileLoanEstimatorTest.php`:

```php
<?php

namespace Tests\Feature\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\QuoteSimulation;
use App\Services\Loans\MediafacileLoanEstimator;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediafacileLoanEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private function xml(array $offers): string
    {
        $items = collect($offers)->map(fn ($o) => "<Offerta><Importo_erogato>{$o[0]}</Importo_erogato><Errore>{$o[1]}</Errore></Offerta>")->implode('');

        return "<?xml version=\"1.0\"?><Offerte>{$items}</Offerte>";
    }

    private function loan(): LoanRequest
    {
        return LoanRequest::create([
            'code' => 'FIN-2026-0001', 'agent_wa_number' => '393331112222', 'product' => 'quinto', 'status' => 'richiesta',
            'answers' => ['prodotto' => 'quinto', 'importo' => 'imp_20k', 'durata' => 'm60', 'eta' => 'eta_50', 'lavoro' => 'dip_pub', 'contratto' => 'indet', 'anzianita' => 'anz_10', 'reddito' => 'red_2000'],
        ]);
    }

    private function company(array $override = []): Company
    {
        return Company::create($override + ['name' => 'Hassisto Srl', 'url_preventivatore' => 'https://crm.example.com/ws/offerte', 'preventivatore_passkey' => 'KEY']);
    }

    public function test_l_intervallo_va_dal_minimo_del_peggiore_al_massimo_del_migliore(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()
            ->push($this->xml([['15000,40', 2], ['14000,00', 2], ['99999,00', 1]]))   // migliore
            ->push($this->xml([['9000,00', 2], ['8000,60', 2], ['100,00', 1]]))],      // peggiore
        );

        $range = app(MediafacileLoanEstimator::class)->estimate($this->loan());

        $this->assertSame(['min' => 8001, 'max' => 15000], $range);
        Http::assertSent(fn (Request $r) => $r['Passkey'] === 'KEY' && $r['Data_nascita'] === '01-01-1986' && $r['Importo_rata'] === '400,00');
        $this->assertSame(['best', 'worst'], QuoteSimulation::orderBy('id')->pluck('scenario')->all());
        $this->assertSame(3, QuoteSimulation::where('scenario', 'best')->value('offers_count'));
        $this->assertEquals(14000, QuoteSimulation::where('scenario', 'best')->value('erogato_min'));
        $this->assertArrayNotHasKey('Passkey', QuoteSimulation::first()->request);
    }

    public function test_se_il_peggiore_supera_il_migliore_l_intervallo_si_ordina(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()->push($this->xml([['5000,00', 2]]))->push($this->xml([['7000,00', 2]]))]);

        $this->assertSame(['min' => 5000, 'max' => 7000], app(MediafacileLoanEstimator::class)->estimate($this->loan()));
    }

    public function test_senza_offerte_valide_in_uno_scenario_non_c_e_stima_ma_resta_la_traccia(): void
    {
        $this->company();
        Http::fake(['crm.example.com/*' => Http::sequence()->push($this->xml([['0', 1]]))]);

        try {
            app(MediafacileLoanEstimator::class)->estimate($this->loan());
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            $this->assertSame(1, QuoteSimulation::count());
            $this->assertNull(QuoteSimulation::first()->erogato_min);
        }
    }

    public function test_senza_url_o_passkey_non_si_chiama_il_servizio(): void
    {
        Http::fake();
        $this->company(['preventivatore_passkey' => null]);

        try {
            app(MediafacileLoanEstimator::class)->estimate($this->loan());
            $this->fail('atteso QuoteUnavailable');
        } catch (QuoteUnavailable) {
            Http::assertNothingSent();
        }
    }
}
```

`tests/Feature/Finanziamento/QuoteFallbackTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Mail\QuoteMail;
use App\Models\Company;
use App\Models\Fornitore;
use App\Models\LoanRequest;
use App\Services\Loans\LoanEstimator;
use App\Services\Loans\MediafacileLoanEstimator;
use App\Services\Loans\QuoteUnavailable;
use Illuminate\Support\Facades\Mail;

class QuoteFallbackTest extends ConversationTestCase
{
    private const FLOW = ['#menu_richiedi', '#personale', '#imp_5k', '#m24', '#eta_40', '#sesso_m', '#dip_priv', '#det', '#anz_1', '#red_1500', '#no', '#no', '#conferma'];

    public function test_se_la_stima_non_e_disponibile_la_richiesta_parte_per_email(): void
    {
        $this->app->instance(LoanEstimator::class, new class implements LoanEstimator
        {
            public function estimate(LoanRequest $loan): array
            {
                throw new QuoteUnavailable('servizio giù');
            }
        });
        Company::create(['name' => 'Hassisto Srl', 'url_preventivatore' => 'https://crm.example.com/ws/offerte']);
        Fornitore::create(['name' => 'Agenzia Bianchi', 'tel' => '+39 333 111 2222', 'is_active' => true]);

        $body = $this->bodies($this->say(...self::FLOW));

        $this->assertStringContainsString('Codice pratica', $body);
        $this->assertStringContainsString('inoltrato la richiesta all\'istruttoria', $body);
        $this->assertStringNotContainsString('Importo ottenibile', $body);
        Mail::assertSent(QuoteMail::class);
    }

    public function test_con_driver_mediafacile_il_vero_stimatore_e_collegato(): void
    {
        config(['finanziamento.quote.driver' => 'mediafacile']);

        $this->assertInstanceOf(MediafacileLoanEstimator::class, app(LoanEstimator::class));
    }
}
```

Run: `php artisan test --compact --filter='MediafacileLoanEstimatorTest|QuoteFallbackTest'`
Expected: FAIL.

- [ ] **Step 3: Implementare lo stimatore**

```php
<?php

namespace App\Services\Loans;

use App\Models\Company;
use App\Models\LoanRequest;
use App\Models\QuoteSimulation;
use App\Services\Loans\Mediafacile\MediafacileClient;
use App\Services\Loans\Mediafacile\ScenarioBuilder;

/**
 * Importo erogato minimo e massimo dal servizio di simulazione Mediafacile: due chiamate,
 * una con le condizioni migliori (massimo) e una con le peggiori (minimo). Registra ogni simulazione.
 */
class MediafacileLoanEstimator implements LoanEstimator
{
    public function __construct(private ScenarioBuilder $scenarios, private MediafacileClient $client) {}

    public function estimate(LoanRequest $loan): array
    {
        $company = Company::forWhatsApp($loan->agent_wa_number);
        if (! $company?->hasQuoteCrm() || blank($company->preventivatore_passkey)) {
            throw new QuoteUnavailable('Preventivatore non configurato.');
        }

        $erogato = [];
        foreach ($this->scenarios->build($loan) as $name => $params) {
            $raw = $this->client->request($company->url_preventivatore, $company->preventivatore_passkey, $params);
            $offers = MediafacileClient::parse($raw);
            $valid = collect($offers)->where('valid', true)->pluck('erogato');

            QuoteSimulation::create([
                'loan_request_id' => $loan->id, 'scenario' => $name, 'request' => $params, 'response' => $raw,
                'offers_count' => count($offers), 'erogato_min' => $valid->min(), 'erogato_max' => $valid->max(),
            ]);

            if ($valid->isEmpty()) {
                throw new QuoteUnavailable("Nessuna offerta valida nello scenario {$name}.");
            }
            $erogato[$name] = $name === 'best' ? $valid->max() : $valid->min();
        }

        return ['min' => (int) round(min($erogato)), 'max' => (int) round(max($erogato))];
    }
}
```

- [ ] **Step 4: Collegare il driver** in `AppServiceProvider`, al posto del binding di `RandomLoanEstimator`:

```php
        // Calcolo degli importi ottenibili: simulazione di base, servizio Mediafacile con QUOTE_DRIVER=mediafacile.
        $this->app->bind(LoanEstimator::class, fn ($app) => config('finanziamento.quote.driver') === 'mediafacile'
            ? $app->make(MediafacileLoanEstimator::class)
            : new RandomLoanEstimator);
```

con `use App\Services\Loans\MediafacileLoanEstimator;` tra gli import.

- [ ] **Step 5: Ripiego nel motore** — in `ConversationEngine::outcomeText`, sostituire il blocco `if (Fornitore::isProducer(...)) { ... }` con:

```php
        if (Fornitore::isProducer($waNumber)) {
            // Senza preventivatore (CRM) i dati vanno per email all'istruttoria, che risponderà.
            if (! $company?->hasQuoteCrm()) {
                return $this->forwardQuote($loan);
            }

            try {
                $range = $this->estimator->estimate($loan);
            } catch (QuoteUnavailable $e) {
                Log::warning('Preventivatore non disponibile: '.$e->getMessage(), ['loan' => $loan->code]);

                return $this->forwardQuote($loan);
            }

            return '💶 Importo ottenibile: da *'.number_format($range['min'], 0, ',', '.').' €* a *'.number_format($range['max'], 0, ',', '.').' €*.';
        }
```

e aggiungere subito dopo `outcomeText`:

```php
    /** I dati del preventivo vanno per email all'istruttoria, che ricontatta il produttore. */
    private function forwardQuote(LoanRequest $loan): string
    {
        return $this->quoteMailer->send($loan)
            ? '📨 Ho inoltrato la richiesta all\'istruttoria: ti ricontatteranno con l\'esito.'
            : '⚠️ Non sono riuscito a inoltrare la richiesta all\'istruttoria: contattala indicando il codice pratica.';
    }
```

Aggiungere gli import `App\Services\Loans\QuoteUnavailable` e, se mancante, `Illuminate\Support\Facades\Log`.

- [ ] **Step 6: Verificare**

Run: `php artisan test --compact` (suite intera).
Expected: PASS, salvo i 3 fallimenti preesistenti di `FilamentAdminTest` sui produttori.

- [ ] **Step 7: Aggiungere `QUOTE_DRIVER=random` a `.env.example`** (se il file esiste) con un commento che spiega `mediafacile`.

- [ ] **Step 8: Commit**

```bash
git -c core.fileMode=false add -A
git -c core.fileMode=false commit -m "feat: stima dell'importo erogato dal servizio Mediafacile con ripiego sull'email

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
git -c core.fileMode=false push origin main
```

---

## Self-Review

- **Copertura della specifica:** due scenari e regole best/worst (Task 3); solo `Importo_erogato` valido e intervallo (Task 5); ripiego sull'email (Task 5); rata = reddito/5, nascita al 1° gennaio, decorrenza +2 mesi, rinnovo NO, sesso di ripiego (Task 3); domande età e sesso e fasce chiuse (Task 2); tabelle del catalogo e `quote_simulations` (Task 1 e 5); `passkey` per company e driver (Task 5); client isolato (Task 4).
- **Scostamento dalla specifica:** il Finalizzato, con driver `mediafacile`, non è simulabile e quindi segue il ripiego sull'email (la specifica diceva «resta il comportamento attuale»: con numeri casuali non avrebbe senso accanto a importi veri). Con driver `random` non cambia nulla.
- **Placeholder:** nessuno.
- **Coerenza dei tipi:** `ScenarioBuilder::build` → `array{best,worst}` usato come `foreach ($this->scenarios->build($loan) as $name => $params)` nel Task 5; `MediafacileClient::parse` statico usato in `MediafacileLoanEstimator`; `QuoteUnavailable` in `App\Services\Loans`.
