# Dialogo WhatsApp Richiedi/Perfeziona Finanziamento — Piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sostituire il menu a un passo di `WhatsAppController` con due conversazioni guidate (richiesta anonima, perfezionamento con informativa firmata) salvate nel database.

**Architecture:** L'albero di domande è dato puro in `config/finanziamento.php`. Un `ConversationEngine` generico legge lo stato di una `Conversation` (database), valida la risposta, avanza e restituisce oggetti `Reply`. Il controller trasforma il payload Meta in `IncomingMessage`, chiama il motore e invia i `Reply` con `WhatsAppClient`, dentro una transazione che si annulla se un invio fallisce.

**Tech Stack:** PHP 8.3, Laravel 13, PHPUnit 12 (sqlite in-memory nei test, MySQL in produzione), `Http` facade verso la Graph API di Meta v20.0.

**Spec:** `docs/superpowers/specs/2026-10-07-finanziamento-whatsapp-design.md`

## Global Constraints

- Titolo di una voce di lista: max **24** caratteri; titolo di un pulsante: max **20**; max **10** voci per lista, max **3** pulsanti. Corpo di un messaggio interattivo: max **1024** caratteri.
- Fase 1 (`richiesta`): **nessun** dato identificativo (nome, codice fiscale, telefono, email, ragione sociale, P.IVA, indirizzo); valori a fasce; niente note libere né allegati.
- Fase 2 (`perfezionamento`): nessun dato personale chiesto prima di aver ricevuto l'informativa firmata; basta ricevere il file.
- Dati personali in `loan_requests.personal` (cast `encrypted:array`) e in `conversations.data` (cast `encrypted:array`); file sul disco `local` (`storage/app/private`), mai pubblico.
- Comandi sempre validi in conversazione: `indietro`, `menu`, `annulla`.
- Codice pratica: `FIN-AAAA-NNNN`, progressivo per anno.
- L'albero non contiene closure salvate (compatibile con `config:cache`).
- Fuori ambito: firma `X-Hub-Signature-256`, deduplica dei messaggi, notifiche, CRM, pannello web.

## Scostamenti dal design (decisi in fase di piano)

1. `conversations` ha anche la colonna `history` (JSON) per `indietro`, e `data` (JSON cifrato) per le risposte in corso; la pratica nasce solo alla conferma.
2. Con le fasce non si può calcolare la percentuale sul valore dell'immobile: il mutuo chiede direttamente la quota da finanziare (fasce) e non mostra l'avviso oltre l'80%.
3. "Codice non trovato" e "pratica di un altro agente" danno lo stesso messaggio, per non rivelare l'esistenza dei codici altrui.
4. "Modifica" nel riepilogo ricomincia il ramo (etichetta "Ricomincia").

## Review Focus

1. **Messaggio non supportato a metà conversazione** (sticker, audio, posizione): risposta gentile e stessa domanda, nessuna eccezione. Test in Task 7.
2. **Eventi di stato di Meta** (`statuses`, senza `messages`): arrivano di continuo; il webhook deve rispondere 200 senza fare nulla. Test in Task 9.
3. **Risposta digitata invece di toccata** ("sì", " SI ", "Sì ", "2"): deve corrispondere all'opzione. Test in Task 7.
4. **Date impossibili** (`31/02/2026`) e IBAN/telefono con spazi: rifiutate o normalizzate. Test in Task 8.
5. **Dato identificativo digitato in fase 1** in qualunque forma (CF minuscolo, telefono con spazi, email): rifiutato prima della validazione. Test in Task 3 e Task 7.

## Mappa dei file

| File | Responsabilità |
|---|---|
| `database/migrations/2026_10_07_00000{1,2,3}_*.php` | tabelle `loan_requests`, `conversations`, `attachments` |
| `app/Models/{LoanRequest,Conversation,Attachment}.php` | modelli e cast |
| `app/Services/Conversation/IncomingMessage.php` | trasforma il payload Meta in un messaggio tipizzato |
| `app/Services/Conversation/Reply.php` | messaggio in uscita (testo, pulsanti o lista) |
| `app/Services/Conversation/SensitiveDataGuard.php` | riconosce dati identificativi nel testo |
| `app/Services/Conversation/LoanRequestCode.php` | genera il codice progressivo |
| `app/Services/Conversation/ConversationEngine.php` | stato, validazione, avanzamento |
| `app/Services/Whatsapp/WhatsAppClient.php` | invio verso Meta, download media |
| `config/finanziamento.php` | menu e alberi delle due conversazioni |
| `app/Http/Controllers/WhatsAppController.php` | webhook: parse, motore, invio |
| `tests/...` | vedi i singoli task |

---

### Task 1: Tabelle e modelli

**Files:**
- Create: `database/migrations/2026_10_07_000001_create_loan_requests_table.php`
- Create: `database/migrations/2026_10_07_000002_create_conversations_table.php`
- Create: `database/migrations/2026_10_07_000003_create_attachments_table.php`
- Create: `app/Models/LoanRequest.php`, `app/Models/Conversation.php`, `app/Models/Attachment.php`
- Test: `tests/Feature/ModelsTest.php`

**Interfaces:**
- Produces: `LoanRequest` (`code`, `agent_wa_number`, `product`, `status`, `answers` array, `personal` encrypted array, `privacy_received_at`, `perfected_at`, `attachments()`); `Conversation` (`wa_number`, `flow`, `node`, `loan_request_id`, `data` encrypted array, `history` array, `status`, `loanRequest()`); `Attachment` (`loan_request_id`, `kind`, `path`, `mime`, `wa_media_id`, `received_at`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ModelsTest`
Expected: FAIL (`Class "App\Models\LoanRequest" not found`)

- [ ] **Step 3: Write the migrations and models**

`database/migrations/2026_10_07_000001_create_loan_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('agent_wa_number')->index();
            $table->string('product');
            $table->string('status')->default('richiesta');
            $table->json('answers');
            $table->text('personal')->nullable();
            $table->timestamp('privacy_received_at')->nullable();
            $table->timestamp('perfected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_requests');
    }
};
```

`database/migrations/2026_10_07_000002_create_conversations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('wa_number')->index();
            $table->string('flow');
            $table->string('node');
            $table->foreignId('loan_request_id')->nullable()->constrained()->nullOnDelete();
            $table->text('data')->nullable();
            $table->json('history')->nullable();
            $table->string('status')->default('attiva')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
```

`database/migrations/2026_10_07_000003_create_attachments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_request_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('path');
            $table->string('mime');
            $table->string('wa_media_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
```

`app/Models/LoanRequest.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'personal' => 'encrypted:array',
            'privacy_received_at' => 'datetime',
            'perfected_at' => 'datetime',
        ];
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
```

`app/Models/Conversation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'encrypted:array',
            'history' => 'array',
        ];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
```

`app/Models/Attachment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function loanRequest(): BelongsTo
    {
        return $this->belongsTo(LoanRequest::class);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ModelsTest`
Expected: PASS (2 tests). I test usano sqlite in memoria; non toccare il database MySQL.

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models tests/Feature/ModelsTest.php
git commit -m "feat: tabelle e modelli per pratiche, conversazioni e allegati"
```

---

### Task 2: IncomingMessage e Reply

**Files:**
- Create: `app/Services/Conversation/IncomingMessage.php`, `app/Services/Conversation/Reply.php`
- Test: `tests/Unit/MessageDtoTest.php`

**Interfaces:**
- Produces:
  - `IncomingMessage::__construct(string $from, string $type, ?string $text = null, ?string $replyId = null, ?string $mediaId = null, ?string $mime = null)`; `type` ∈ `text|interactive|media|unsupported`; `IncomingMessage::fromWebhook(array $payload): ?IncomingMessage` (null se non c'è un messaggio).
  - `Reply::text(string $body): Reply`; `Reply::choice(string $body, array $options): Reply` con `$options` = `id => titolo`; proprietà pubbliche `kind` (`text|buttons|list`), `body`, `options`, `label`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Conversation\IncomingMessage;
use App\Services\Conversation\Reply;
use PHPUnit\Framework\TestCase;

class MessageDtoTest extends TestCase
{
    private function payload(array $message): array
    {
        return ['entry' => [['changes' => [['value' => ['messages' => [$message]]]]]]];
    }

    public function test_messaggio_di_testo(): void
    {
        $m = IncomingMessage::fromWebhook($this->payload([
            'from' => '3933', 'type' => 'text', 'text' => ['body' => '  Ciao ']]));

        $this->assertSame('text', $m->type);
        $this->assertSame('Ciao', $m->text);
        $this->assertSame('3933', $m->from);
    }

    public function test_risposta_a_pulsante_e_a_lista(): void
    {
        $b = IncomingMessage::fromWebhook($this->payload([
            'from' => '3933', 'type' => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'si', 'title' => 'Sì']]]));
        $l = IncomingMessage::fromWebhook($this->payload([
            'from' => '3933', 'type' => 'interactive',
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'm60', 'title' => '60 mesi']]]));

        $this->assertSame(['interactive', 'si', 'Sì'], [$b->type, $b->replyId, $b->text]);
        $this->assertSame(['interactive', 'm60'], [$l->type, $l->replyId]);
    }

    public function test_immagine_e_documento_diventano_media(): void
    {
        $i = IncomingMessage::fromWebhook($this->payload([
            'from' => '3933', 'type' => 'image', 'image' => ['id' => 'M1', 'mime_type' => 'image/jpeg']]));
        $d = IncomingMessage::fromWebhook($this->payload([
            'from' => '3933', 'type' => 'document', 'document' => ['id' => 'M2', 'mime_type' => 'application/pdf']]));

        $this->assertSame(['media', 'M1', 'image/jpeg'], [$i->type, $i->mediaId, $i->mime]);
        $this->assertSame(['media', 'M2', 'application/pdf'], [$d->type, $d->mediaId, $d->mime]);
    }

    public function test_tipi_non_gestiti_sono_unsupported(): void
    {
        $m = IncomingMessage::fromWebhook($this->payload(['from' => '3933', 'type' => 'sticker']));

        $this->assertSame('unsupported', $m->type);
    }

    public function test_payload_senza_messaggi_restituisce_null(): void
    {
        $statuses = ['entry' => [['changes' => [['value' => ['statuses' => [['status' => 'delivered']]]]]]]];

        $this->assertNull(IncomingMessage::fromWebhook($statuses));
        $this->assertNull(IncomingMessage::fromWebhook([]));
    }

    public function test_reply_choice_usa_pulsanti_solo_se_stanno_nei_limiti(): void
    {
        $buttons = Reply::choice('Scegli', ['si' => 'Sì', 'no' => 'No']);
        $longTitle = Reply::choice('Scegli', ['a' => 'Richiedi Finanziamento', 'b' => 'No']);
        $many = Reply::choice('Scegli', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);

        $this->assertSame('buttons', $buttons->kind);
        $this->assertSame('list', $longTitle->kind);
        $this->assertSame('list', $many->kind);
        $this->assertSame('text', Reply::text('Ciao')->kind);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MessageDtoTest`
Expected: FAIL (`Class "App\Services\Conversation\IncomingMessage" not found`)

- [ ] **Step 3: Write minimal implementation**

`app/Services/Conversation/IncomingMessage.php`:

```php
<?php

namespace App\Services\Conversation;

final class IncomingMessage
{
    public function __construct(
        public readonly string $from,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $replyId = null,
        public readonly ?string $mediaId = null,
        public readonly ?string $mime = null,
    ) {}

    public static function fromWebhook(array $payload): ?self
    {
        $m = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;
        if (! $m || empty($m['from'])) {
            return null;
        }

        $type = $m['type'] ?? 'text';

        return match ($type) {
            'text' => new self($m['from'], 'text', trim($m['text']['body'] ?? '')),
            'interactive' => self::fromInteractive($m),
            'image', 'document' => new self(
                $m['from'], 'media', $m[$type]['caption'] ?? null, null, $m[$type]['id'] ?? null, $m[$type]['mime_type'] ?? null
            ),
            default => new self($m['from'], 'unsupported'),
        };
    }

    private static function fromInteractive(array $m): self
    {
        $reply = $m['interactive'][$m['interactive']['type'] ?? ''] ?? [];

        return new self($m['from'], 'interactive', $reply['title'] ?? null, $reply['id'] ?? null);
    }
}
```

`app/Services/Conversation/Reply.php`:

```php
<?php

namespace App\Services\Conversation;

final class Reply
{
    /** @param array<string,string> $options id => titolo */
    private function __construct(
        public readonly string $kind,
        public readonly string $body,
        public readonly array $options = [],
        public readonly string $label = 'Scegli',
    ) {}

    public static function text(string $body): self
    {
        return new self('text', $body);
    }

    /** @param array<string,string> $options id => titolo */
    public static function choice(string $body, array $options): self
    {
        $fitsButtons = count($options) <= 3
            && collect($options)->every(fn ($title) => mb_strlen($title) <= 20);

        return new self($fitsButtons ? 'buttons' : 'list', $body, $options);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MessageDtoTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Conversation tests/Unit/MessageDtoTest.php
git commit -m "feat: IncomingMessage e Reply"
```

---

### Task 3: SensitiveDataGuard

**Files:**
- Create: `app/Services/Conversation/SensitiveDataGuard.php`
- Test: `tests/Unit/SensitiveDataGuardTest.php`

**Interfaces:**
- Produces: `SensitiveDataGuard::containsIdentifyingData(string $text): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\Conversation\SensitiveDataGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SensitiveDataGuardTest extends TestCase
{
    public static function identificativi(): array
    {
        return [
            'codice fiscale' => ['RSSMRA80A01H501U'],
            'codice fiscale minuscolo' => ['rssmra80a01h501u'],
            'codice fiscale in frase' => ['il cliente è rssmra80a01h501u ok'],
            'email' => ['mario.rossi@example.com'],
            'cellulare con spazi' => ['333 123 4567'],
            'cellulare internazionale' => ['+39 333 1234567'],
            'fisso con trattino' => ['02-1234567890'],
            'partita iva' => ['12345678901'],
        ];
    }

    public static function innocui(): array
    {
        return [
            'scelta' => ['dipendente privato'],
            'importo' => ['1500'],
            'fascia' => ['Fino a 5.000 €'],
            'anni' => ['12 anni'],
            'numero e testo' => ['60 mesi'],
        ];
    }

    #[DataProvider('identificativi')]
    public function test_riconosce_dati_identificativi(string $text): void
    {
        $this->assertTrue((new SensitiveDataGuard)->containsIdentifyingData($text));
    }

    #[DataProvider('innocui')]
    public function test_lascia_passare_dati_di_profilo(string $text): void
    {
        $this->assertFalse((new SensitiveDataGuard)->containsIdentifyingData($text));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SensitiveDataGuardTest`
Expected: FAIL (`Class "App\Services\Conversation\SensitiveDataGuard" not found`)

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Conversation;

class SensitiveDataGuard
{
    public function containsIdentifyingData(string $text): bool
    {
        if (preg_match('/[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]/i', $text)) {
            return true;
        }

        if (preg_match('/[^\s@]+@[^\s@]+\.[^\s@]+/', $text)) {
            return true;
        }

        $digits = preg_replace('/[\s.\-\/()]/', '', $text);

        return (bool) preg_match('/\d{10,}/', $digits);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SensitiveDataGuardTest`
Expected: PASS (13 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Conversation/SensitiveDataGuard.php tests/Unit/SensitiveDataGuardTest.php
git commit -m "feat: SensitiveDataGuard per la fase anonima"
```

---

### Task 4: LoanRequestCode

**Files:**
- Create: `app/Services/Conversation/LoanRequestCode.php`
- Test: `tests/Feature/LoanRequestCodeTest.php`

**Interfaces:**
- Consumes: `LoanRequest` (Task 1).
- Produces: `LoanRequestCode::next(): string` → `FIN-AAAA-NNNN`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\LoanRequest;
use App\Services\Conversation\LoanRequestCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanRequestCodeTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $code): void
    {
        LoanRequest::create(['code' => $code, 'agent_wa_number' => '39', 'product' => 'mutuo', 'answers' => []]);
    }

    public function test_il_primo_codice_dell_anno_e_0001(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');

        $this->assertSame('FIN-2026-0001', LoanRequestCode::next());
    }

    public function test_il_codice_e_progressivo_nell_anno(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->make('FIN-2026-0001');
        $this->make('FIN-2026-0009');

        $this->assertSame('FIN-2026-0010', LoanRequestCode::next());
    }

    public function test_si_riparte_da_1_a_nuovo_anno(): void
    {
        Carbon::setTestNow('2027-01-02 10:00:00');
        $this->make('FIN-2026-0042');

        $this->assertSame('FIN-2027-0001', LoanRequestCode::next());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LoanRequestCodeTest`
Expected: FAIL (`Class "App\Services\Conversation\LoanRequestCode" not found`)

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Conversation;

use App\Models\LoanRequest;
use Illuminate\Support\Facades\DB;

class LoanRequestCode
{
    public static function next(): string
    {
        return DB::transaction(function () {
            $year = now()->year;
            $last = LoanRequest::where('code', 'like', "FIN-{$year}-%")
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            return sprintf('FIN-%d-%04d', $year, $last ? (int) substr($last, -4) + 1 : 1);
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LoanRequestCodeTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Conversation/LoanRequestCode.php tests/Feature/LoanRequestCodeTest.php
git commit -m "feat: generatore del codice pratica"
```

---

### Task 5: WhatsAppClient

**Files:**
- Create: `app/Services/Whatsapp/WhatsAppClient.php`
- Test: `tests/Feature/WhatsAppClientTest.php`

**Interfaces:**
- Consumes: `Reply` (Task 2); `config('services.whatsapp.token')`, `config('services.whatsapp.phone_number_id')`.
- Produces: `WhatsAppClient::send(string $to, Reply $reply): bool`; `WhatsAppClient::downloadMedia(string $mediaId): ?array` → `['body' => string, 'mime' => string]` o `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\Conversation\Reply;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
    }

    public function test_invia_un_testo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $this->assertTrue((new WhatsAppClient)->send('3933', Reply::text('Ciao')));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v20.0/555/messages'
            && $r->hasHeader('Authorization', 'Bearer TOK')
            && $r['to'] === '3933' && $r['type'] === 'text' && $r['text']['body'] === 'Ciao');
    }

    public function test_invia_pulsanti(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);

        (new WhatsAppClient)->send('3933', Reply::choice('Confermi?', ['si' => 'Sì', 'no' => 'No']));

        Http::assertSent(fn (Request $r) => $r['interactive']['type'] === 'button'
            && $r['interactive']['action']['buttons'][0]['reply'] === ['id' => 'si', 'title' => 'Sì']
            && count($r['interactive']['action']['buttons']) === 2);
    }

    public function test_invia_lista_e_tronca_i_titoli_oltre_i_limiti(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);
        $long = str_repeat('x', 40);

        (new WhatsAppClient)->send('3933', Reply::choice(str_repeat('b', 2000), ['a' => $long, 'b' => 'B', 'c' => 'C', 'd' => 'D']));

        Http::assertSent(function (Request $r) {
            $rows = $r['interactive']['action']['sections'][0]['rows'];

            return $r['interactive']['type'] === 'list'
                && mb_strlen($rows[0]['title']) === 24
                && mb_strlen($r['interactive']['body']['text']) === 1024
                && $r['interactive']['action']['button'] === 'Scegli'
                && count($rows) === 4;
        });
    }

    public function test_un_errore_di_meta_restituisce_false(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no']], 400)]);

        $this->assertFalse((new WhatsAppClient)->send('3933', Reply::text('Ciao')));
    }

    public function test_scarica_un_media(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/M1' => Http::response(['url' => 'https://lookaside.fbsbx.com/f', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('BYTES'),
        ]);

        $this->assertSame(['body' => 'BYTES', 'mime' => 'image/jpeg'], (new WhatsAppClient)->downloadMedia('M1'));
    }

    public function test_download_fallito_restituisce_null(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 404)]);

        $this->assertNull((new WhatsAppClient)->downloadMedia('M1'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WhatsAppClientTest`
Expected: FAIL (`Class "App\Services\Whatsapp\WhatsAppClient" not found`)

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Whatsapp;

use App\Services\Conversation\Reply;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppClient
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    public function send(string $to, Reply $reply): bool
    {
        $response = Http::withToken(config('services.whatsapp.token'))
            ->post(self::BASE.'/'.config('services.whatsapp.phone_number_id').'/messages', $this->payload($to, $reply));

        if ($response->failed()) {
            Log::error('Errore invio WhatsApp', (array) $response->json());

            return false;
        }

        return true;
    }

    /** @return array{body: string, mime: string}|null */
    public function downloadMedia(string $mediaId): ?array
    {
        $token = config('services.whatsapp.token');

        $meta = Http::withToken($token)->get(self::BASE.'/'.$mediaId);
        if ($meta->failed() || ! $meta->json('url')) {
            Log::error('Errore recupero media WhatsApp', ['media' => $mediaId]);

            return null;
        }

        $file = Http::withToken($token)->get($meta->json('url'));
        if ($file->failed()) {
            Log::error('Errore download media WhatsApp', ['media' => $mediaId]);

            return null;
        }

        return ['body' => $file->body(), 'mime' => (string) $meta->json('mime_type')];
    }

    private function payload(string $to, Reply $reply): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];

        return $base + match ($reply->kind) {
            'text' => ['type' => 'text', 'text' => ['preview_url' => false, 'body' => mb_substr($reply->body, 0, 4096)]],
            'buttons' => ['type' => 'interactive', 'interactive' => [
                'type' => 'button',
                'body' => ['text' => mb_substr($reply->body, 0, 1024)],
                'action' => ['buttons' => collect($reply->options)->map(fn ($title, $id) => [
                    'type' => 'reply', 'reply' => ['id' => $id, 'title' => mb_substr($title, 0, 20)],
                ])->values()->all()],
            ]],
            'list' => ['type' => 'interactive', 'interactive' => [
                'type' => 'list',
                'body' => ['text' => mb_substr($reply->body, 0, 1024)],
                'action' => [
                    'button' => mb_substr($reply->label, 0, 20),
                    'sections' => [['title' => 'Opzioni', 'rows' => collect($reply->options)->map(fn ($title, $id) => [
                        'id' => (string) $id, 'title' => mb_substr($title, 0, 24),
                    ])->values()->all()]],
                ],
            ]],
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WhatsAppClientTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Whatsapp tests/Feature/WhatsAppClientTest.php
git commit -m "feat: WhatsAppClient per invio e download media"
```

---

### Task 6: Albero di conversazione in config

**Files:**
- Create: `config/finanziamento.php`
- Test: `tests/Feature/FinanziamentoConfigTest.php`

**Interfaces:**
- Produces: `config('finanziamento.menu')` = `['body' => string, 'options' => id => titolo]`; `config('finanziamento.flows.{richiesta|perfezionamento}')` = `['start' => nodo, 'restart' => nodo, 'nodes' => [...]]`.
- Schema di un nodo: `type` (`choice`|`text`|`code`|`file`|`summary`), `prompt`, `label`, `options` (choice/summary), `rules` (array, text), `upper`/`strip_spaces` (bool, text), `error` (testo), `kind` (file), `optional` (file), `skip_if` (`privacy_received`), `prompt_summary` (bool), `docs` (summary), `save` (bool, default true), `next` (stringa oppure mappa), `next_by` (`answer` default oppure chiave delle risposte, es. `prodotto`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanziamentoConfigTest extends TestCase
{
    public function test_il_menu_rispetta_i_limiti_di_whatsapp(): void
    {
        $options = config('finanziamento.menu.options');

        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato'], array_keys($options));
        $this->assertSame('Richiedi Finanziamento', $options['menu_richiedi']);
        $this->assertSame('Perfeziona Finanziamento', $options['menu_perfeziona']);
        foreach ($options as $title) {
            $this->assertLessThanOrEqual(24, mb_strlen($title));
        }
    }

    public function test_gli_alberi_sono_coerenti(): void
    {
        foreach (config('finanziamento.flows') as $flow => $def) {
            $nodes = $def['nodes'];
            $this->assertArrayHasKey($def['start'], $nodes, "$flow: nodo start mancante");
            $this->assertArrayHasKey($def['restart'], $nodes, "$flow: nodo restart mancante");

            $targets = [];
            foreach ($nodes as $name => $node) {
                $this->assertContains($node['type'], ['choice', 'text', 'code', 'file', 'summary'], "$flow.$name tipo");
                $this->assertNotEmpty($node['prompt'] ?? null, "$flow.$name senza prompt");
                $this->assertLessThanOrEqual(900, mb_strlen($node['prompt']), "$flow.$name prompt troppo lungo");

                if (in_array($node['type'], ['choice', 'summary'], true)) {
                    $this->assertLessThanOrEqual(10, count($node['options']), "$flow.$name troppe opzioni");
                    foreach ($node['options'] as $id => $title) {
                        $this->assertLessThanOrEqual(24, mb_strlen($title), "$flow.$name.$id titolo troppo lungo");
                    }
                }
                if ($node['type'] === 'text') {
                    $this->assertNotEmpty($node['rules'] ?? null, "$flow.$name senza regole");
                }
                if ($node['type'] === 'file') {
                    $this->assertNotEmpty($node['kind'] ?? null, "$flow.$name senza kind");
                }
                if ($node['type'] !== 'summary') {
                    $this->assertArrayHasKey('next', $node, "$flow.$name senza next");
                    $targets[$name] = (array) $node['next'];
                }
            }

            $seen = [];
            $queue = [$def['start'], $def['restart']];
            while ($queue) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $this->assertArrayHasKey($name, $nodes, "$flow: il nodo '$name' non esiste");
                $seen[$name] = true;
                array_push($queue, ...($targets[$name] ?? []));
            }
            $this->assertSame([], array_values(array_diff(array_keys($nodes), array_keys($seen))), "$flow: nodi irraggiungibili");
        }
    }

    public function test_la_fase_anonima_non_chiede_dati_identificativi(): void
    {
        $forbidden = ['nome', 'cognome', 'codice_fiscale', 'telefono', 'email', 'ragione_sociale', 'partita_iva', 'residenza'];

        $this->assertSame([], array_intersect($forbidden, array_keys(config('finanziamento.flows.richiesta.nodes'))));
        foreach (config('finanziamento.flows.richiesta.nodes') as $name => $node) {
            $this->assertNotContains($node['type'], ['text', 'file'], "richiesta.$name non deve essere testo libero o file");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=FinanziamentoConfigTest`
Expected: FAIL (config `finanziamento.menu.options` è `null`)

- [ ] **Step 3: Write the config**

`config/finanziamento.php`:

```php
<?php

// Alberi delle conversazioni WhatsApp. Solo dati: nessuna closure salvata (compatibile con config:cache).
// Limiti WhatsApp: titolo opzione max 24 caratteri, max 10 opzioni per nodo.

$yn = ['si' => 'Sì', 'no' => 'No'];
$importi = ['imp_5k' => 'Fino a 5.000 €', 'imp_10k' => '5.000 - 10.000 €', 'imp_20k' => '10.000 - 20.000 €', 'imp_35k' => '20.000 - 35.000 €', 'imp_oltre' => 'Oltre 35.000 €'];
$grandi = ['g_100k' => 'Fino a 100.000 €', 'g_200k' => '100.000 - 200.000 €', 'g_400k' => '200.000 - 400.000 €', 'g_oltre' => 'Oltre 400.000 €'];
$redditi = ['red_1000' => 'Fino a 1.000 €', 'red_1500' => '1.000 - 1.500 €', 'red_2000' => '1.500 - 2.000 €', 'red_3000' => '2.000 - 3.000 €', 'red_oltre' => 'Oltre 3.000 €'];
$anzianita = ['anz_1' => 'Meno di 1 anno', 'anz_3' => '1 - 3 anni', 'anz_10' => '3 - 10 anni', 'anz_oltre' => 'Oltre 10 anni'];
$durate = ['m24' => '24 mesi', 'm36' => '36 mesi', 'm48' => '48 mesi', 'm60' => '60 mesi', 'm84' => '84 mesi', 'm120' => '120 mesi'];
$durateMutuo = ['m120' => '120 mesi', 'm180' => '180 mesi', 'm240' => '240 mesi', 'm300' => '300 mesi', 'm360' => '360 mesi'];
$consumo = fn (string $to) => ['personale' => $to, 'quinto' => $to, 'finalizzato' => $to];

$choice = fn (string $label, string $prompt, array $options, string|array $next, array $extra = []) => array_merge(
    ['type' => 'choice', 'label' => $label, 'prompt' => $prompt, 'options' => $options, 'next' => $next], $extra
);
$text = fn (string $label, string $prompt, array $rules, string|array $next, array $extra = []) => array_merge(
    ['type' => 'text', 'label' => $label, 'prompt' => $prompt, 'rules' => $rules, 'next' => $next], $extra
);
$file = fn (string $kind, string $prompt, string $next, array $extra = []) => array_merge(
    ['type' => 'file', 'kind' => $kind, 'prompt' => $prompt, 'next' => $next, 'save' => false], $extra
);
$summary = fn (array $extra = []) => array_merge([
    'type' => 'summary', 'prompt' => 'Confermi i dati inseriti?', 'save' => false,
    'options' => ['conferma' => 'Conferma', 'modifica' => 'Ricomincia', 'annulla' => 'Annulla'],
], $extra);

return [

    'menu' => [
        'body' => 'Ciao! Benvenuto nel servizio agenti. Cosa vuoi fare?',
        'options' => [
            'menu_richiedi' => 'Richiedi Finanziamento',
            'menu_perfeziona' => 'Perfeziona Finanziamento',
            'menu_stato' => 'Stato Pratiche',
        ],
    ],

    'flows' => [

        // Fase 1: nessun dato identificativo, solo profilo a fasce.
        'richiesta' => [
            'start' => 'prodotto',
            'restart' => 'prodotto',
            'nodes' => [
                'prodotto' => $choice('Prodotto', 'Che tipo di finanziamento vuoi richiedere?', [
                    'personale' => 'Prestito personale', 'quinto' => 'Cessione del quinto', 'finalizzato' => 'Finalizzato (beni)',
                    'mutuo' => 'Mutuo', 'leasing' => 'Leasing', 'aziendale' => 'Finanziamento aziendale',
                ], [
                    'personale' => 'importo', 'quinto' => 'importo', 'finalizzato' => 'importo',
                    'mutuo' => 'mutuo_scopo', 'leasing' => 'leasing_bene', 'aziendale' => 'az_forma',
                ]),

                // Comune al consumo
                'importo' => $choice('Importo', 'Quale importo ti serve?', $importi, 'durata'),
                'durata' => $choice('Durata', 'Su quale durata?', $durate, $consumo('lavoro') + ['leasing' => 'leasing_anticipo', 'aziendale' => 'az_finalita'], ['next_by' => 'prodotto']),

                // Credito al consumo
                'lavoro' => $choice('Situazione lavorativa', 'Qual è la situazione lavorativa del cliente?', [
                    'dip_priv' => 'Dipendente privato', 'dip_pub' => 'Dipendente pubblico', 'pensionato' => 'Pensionato',
                    'autonomo' => 'Autonomo', 'altro' => 'Altro',
                ], ['dip_priv' => 'contratto', 'dip_pub' => 'contratto', 'pensionato' => 'ente_pensione', 'autonomo' => 'anni_attivita', '*' => 'impegni']),
                'contratto' => $choice('Contratto', 'Che tipo di contratto ha?', ['indet' => 'Tempo indeterminato', 'det' => 'Tempo determinato'], 'anzianita'),
                'anzianita' => $choice('Anzianità lavorativa', 'Da quanto lavora presso l\'attuale datore?', $anzianita, 'reddito'),
                'reddito' => $choice('Reddito netto mensile', 'Qual è il reddito netto mensile?', $redditi, ['quinto' => 'dimensione_azienda', '*' => 'impegni'], ['next_by' => 'prodotto']),
                'dimensione_azienda' => $choice('Dimensione azienda', 'Quanti dipendenti ha l\'azienda?', ['oltre15' => 'Oltre 15 dipendenti', 'fino15' => 'Fino a 15 dipendenti'], 'impegni'),
                'ente_pensione' => $choice('Ente pensionistico', 'Da quale ente riceve la pensione?', ['inps' => 'INPS', 'exinpdap' => 'Ex INPDAP', 'altro' => 'Altro ente'], 'pensione_netta'),
                'pensione_netta' => $choice('Pensione netta mensile', 'Qual è la pensione netta mensile?', $redditi, 'impegni'),
                'anni_attivita' => $choice('Anni di attività', 'Da quanti anni svolge l\'attività?', $anzianita, 'reddito_autonomo'),
                'reddito_autonomo' => $choice('Reddito', 'Qual è il reddito dell\'ultima dichiarazione (mensile netto)?', $redditi, 'impegni'),
                'impegni' => $choice('Finanziamenti in corso', 'Ci sono finanziamenti in corso?', $yn, ['si' => 'rata', 'no' => 'crif']),
                'rata' => $choice('Rata mensile', 'A quanto ammonta la rata mensile totale?', ['rata_200' => 'Fino a 200 €', 'rata_400' => '200 - 400 €', 'rata_oltre' => 'Oltre 400 €'], 'crif'),
                'crif' => $choice('Segnalazioni CRIF', 'Ci sono segnalazioni in CRIF o protesti?', ['no' => 'No', 'si' => 'Sì', 'nonso' => 'Non so'], ['quinto' => 'quote_cedute', 'finalizzato' => 'bene', '*' => 'riepilogo'], ['next_by' => 'prodotto']),
                'quote_cedute' => $choice('Quote già cedute', 'Ci sono quote dello stipendio già cedute?', $yn, 'riepilogo'),
                'bene' => $choice('Bene', 'Quale bene si vuole acquistare?', ['auto_nuova' => 'Auto nuova', 'auto_usata' => 'Auto usata', 'moto' => 'Moto', 'altro' => 'Altro bene'], 'prezzo_bene'),
                'prezzo_bene' => $choice('Prezzo del bene', 'Qual è il prezzo del bene?', $importi, 'anticipo'),
                'anticipo' => $choice('Anticipo', 'È previsto un anticipo?', $yn, 'riepilogo'),

                // Mutuo
                'mutuo_scopo' => $choice('Scopo', 'Qual è lo scopo del mutuo?', [
                    'prima' => 'Acquisto prima casa', 'seconda' => 'Acquisto seconda casa', 'surroga' => 'Surroga', 'liquidita' => 'Liquidità',
                ], 'mutuo_valore'),
                'mutuo_valore' => $choice('Valore immobile', 'Qual è il valore dell\'immobile?', $grandi, 'mutuo_ltv'),
                'mutuo_ltv' => $choice('Quota da finanziare', 'Quale quota del valore vuoi finanziare?', ['ltv_50' => 'Fino al 50%', 'ltv_80' => '50% - 80%', 'ltv_oltre' => 'Oltre l\'80%'], 'durata_mutuo'),
                'durata_mutuo' => $choice('Durata', 'Su quale durata?', $durateMutuo, 'mutuo_reddito'),
                'mutuo_reddito' => $choice('Reddito familiare', 'Qual è il reddito netto mensile familiare?', [
                    'fam_2000' => 'Fino a 2.000 €', 'fam_3500' => '2.000 - 3.500 €', 'fam_5000' => '3.500 - 5.000 €', 'fam_oltre' => 'Oltre 5.000 €',
                ], 'mutuo_intestatari'),
                'mutuo_intestatari' => $choice('Intestatari', 'Quanti saranno gli intestatari?', ['int_1' => '1 intestatario', 'int_2' => '2 intestatari', 'int_3' => '3 o più'], 'mutuo_tasso'),
                'mutuo_tasso' => $choice('Tasso', 'Che tipo di tasso preferisce?', ['fisso' => 'Tasso fisso', 'variabile' => 'Tasso variabile', 'nonso' => 'Non so'], 'riepilogo'),

                // Leasing
                'leasing_bene' => $choice('Bene', 'Che tipo di bene è in leasing?', ['auto' => 'Auto/veicoli', 'strumentale' => 'Bene strumentale', 'immobiliare' => 'Immobiliare'], 'leasing_valore'),
                'leasing_valore' => $choice('Valore del bene', 'Qual è il valore del bene?', $grandi, 'durata'),
                'leasing_anticipo' => $choice('Anticipo/maxicanone', 'È previsto un anticipo o maxicanone?', $yn, 'leasing_riscatto'),
                'leasing_riscatto' => $choice('Riscatto finale', 'È previsto il riscatto finale?', $yn, 'az_forma'),

                // Aziende (anche per il leasing): nessun dato identificativo
                'az_forma' => $choice('Forma giuridica', 'Qual è la forma giuridica?', [
                    'ditta' => 'Ditta individuale', 'snc_sas' => 'Snc / Sas', 'srl' => 'Srl', 'spa' => 'Spa', 'professionista' => 'Libero professionista',
                ], 'az_anzianita'),
                'az_anzianita' => $choice('Anzianità attività', 'Da quanti anni è attiva?', $anzianita, 'az_fatturato'),
                'az_fatturato' => $choice('Fatturato', 'Qual è il fatturato dell\'ultimo anno?', [
                    'fat_100' => 'Fino a 100.000 €', 'fat_500' => '100.000 - 500.000 €', 'fat_2m' => '500.000 - 2 mln €', 'fat_oltre' => 'Oltre 2 mln €',
                ], ['aziendale' => 'az_importo', 'leasing' => 'riepilogo'], ['next_by' => 'prodotto']),
                'az_importo' => $choice('Importo', 'Quale importo serve?', $grandi, 'durata'),
                'az_finalita' => $choice('Finalità', 'Qual è la finalità?', [
                    'liquidita' => 'Liquidità', 'investimenti' => 'Investimenti', 'macchinari' => 'Acquisto macchinari', 'altro' => 'Altro',
                ], 'az_garanzie'),
                'az_garanzie' => $choice('Garanzie', 'Quali garanzie sono disponibili?', [
                    'fondo_pmi' => 'Fondo Garanzia PMI', 'ipoteca' => 'Ipoteca', 'garante' => 'Garante personale', 'nessuna' => 'Nessuna',
                ], 'riepilogo'),

                'riepilogo' => $summary(),
            ],
        ],

        // Fase 2: dati personali solo dopo l'informativa firmata.
        'perfezionamento' => [
            'start' => 'codice',
            'restart' => 'nome',
            'nodes' => [
                'codice' => ['type' => 'code', 'prompt' => 'Inserisci il codice della pratica (es. FIN-2026-0001):', 'save' => false, 'next' => 'conferma_pratica'],
                'conferma_pratica' => $choice('Conferma', 'È la pratica giusta?', $yn, ['si' => 'informativa', 'no' => 'codice'], ['save' => false, 'prompt_summary' => true]),
                'informativa' => $file('informativa', 'Per procedere invia l\'informativa privacy firmata dal cliente (foto o PDF).', 'nome', ['skip_if' => 'privacy_received']),

                'nome' => $text('Nome', 'Nome del cliente:', ['required', 'string', 'max:60'], 'cognome'),
                'cognome' => $text('Cognome', 'Cognome del cliente:', ['required', 'string', 'max:60'], 'codice_fiscale'),
                'codice_fiscale' => $text('Codice fiscale', 'Codice fiscale:', ['required', 'regex:/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/'], 'data_nascita', ['upper' => true, 'strip_spaces' => true, 'error' => 'Codice fiscale non valido (16 caratteri), riprova.']),
                'data_nascita' => $text('Data di nascita', 'Data di nascita (gg/mm/aaaa):', ['required', 'date_format:d/m/Y'], 'luogo_nascita', ['error' => 'Data non valida: usa il formato gg/mm/aaaa.']),
                'luogo_nascita' => $text('Luogo di nascita', 'Luogo di nascita:', ['required', 'string', 'max:80'], 'residenza'),
                'residenza' => $text('Residenza', 'Indirizzo di residenza (via, numero, CAP, città):', ['required', 'string', 'max:160'], 'stato_civile'),
                'stato_civile' => $choice('Stato civile', 'Stato civile:', ['celibe' => 'Celibe/Nubile', 'coniugato' => 'Coniugato/a', 'separato' => 'Separato/a', 'vedovo' => 'Vedovo/a'], 'documento_tipo'),
                'documento_tipo' => $choice('Documento', 'Tipo di documento d\'identità:', ['ci' => 'Carta d\'identità', 'patente' => 'Patente', 'passaporto' => 'Passaporto'], 'documento_numero'),
                'documento_numero' => $text('Numero documento', 'Numero del documento:', ['required', 'string', 'max:30'], 'documento_scadenza'),
                'documento_scadenza' => $text('Scadenza documento', 'Scadenza del documento (gg/mm/aaaa):', ['required', 'date_format:d/m/Y'], 'telefono', ['error' => 'Data non valida: usa il formato gg/mm/aaaa.']),
                'telefono' => $text('Telefono', 'Telefono del cliente:', ['required', 'regex:/^\+?\d{8,15}$/'], 'email', ['strip_spaces' => true, 'error' => 'Numero non valido, riprova.']),
                'email' => $text('Email', 'Email del cliente:', ['required', 'email'], 'iban', ['error' => 'Email non valida, riprova.']),
                'iban' => $text('IBAN', 'IBAN per l\'erogazione:', ['required', 'regex:/^IT\d{2}[A-Z0-9]{23}$/'], ['aziendale' => 'ragione_sociale', 'leasing' => 'ragione_sociale', '*' => 'datore_lavoro'], ['upper' => true, 'strip_spaces' => true, 'next_by' => 'prodotto', 'error' => 'IBAN non valido (formato IT + 25 caratteri), riprova.']),

                'datore_lavoro' => $text('Datore di lavoro / ente', 'Datore di lavoro, ente pensionistico o attività svolta:', ['required', 'string', 'max:120'], 'data_assunzione'),
                'data_assunzione' => $text('Inizio rapporto', 'Data di inizio rapporto o attività (gg/mm/aaaa):', ['required', 'date_format:d/m/Y'], 'doc_identita', ['error' => 'Data non valida: usa il formato gg/mm/aaaa.']),
                'ragione_sociale' => $text('Ragione sociale', 'Ragione sociale:', ['required', 'string', 'max:120'], 'partita_iva'),
                'partita_iva' => $text('Partita IVA', 'Partita IVA (11 cifre):', ['required', 'regex:/^\d{11}$/'], 'doc_identita', ['strip_spaces' => true, 'error' => 'La partita IVA deve avere 11 cifre.']),

                'doc_identita' => $file('documento_identita', 'Invia il documento d\'identità del cliente (foto o PDF).', 'doc_cf'),
                'doc_cf' => $file('codice_fiscale', 'Invia il codice fiscale del cliente (foto o PDF).', 'doc_reddito'),
                'doc_reddito' => $file('reddito', 'Invia il documento di reddito (busta paga, CUD, cedolino pensione, dichiarazione o bilancio).', 'riepilogo_p', ['optional' => true]),

                'riepilogo_p' => $summary(['docs' => [
                    'documento_identita' => 'Documento d\'identità', 'codice_fiscale' => 'Codice fiscale', 'reddito' => 'Documento di reddito',
                ]]),
            ],
        ],
    ],
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=FinanziamentoConfigTest`
Expected: PASS (3 tests). Se fallisce un messaggio tipo `richiesta: nodi irraggiungibili` o `titolo troppo lungo`, correggi il nodo indicato.

Verifica anche la compatibilità con la cache: `php artisan config:cache && php artisan config:clear` deve terminare senza errori.

- [ ] **Step 5: Commit**

```bash
git add config/finanziamento.php tests/Feature/FinanziamentoConfigTest.php
git commit -m "feat: albero delle conversazioni in config/finanziamento.php"
```

---

### Task 7: ConversationEngine — menu, comandi e fase 1 (richiesta)

**Files:**
- Create: `app/Services/Conversation/ConversationEngine.php`
- Create: `tests/Feature/Finanziamento/ConversationTestCase.php`
- Test: `tests/Feature/Finanziamento/RichiestaFlowTest.php`

**Interfaces:**
- Consumes: tutti i task 1-6. `WhatsAppClient::downloadMedia` (usato dal motore solo nel Task 8).
- Produces: `ConversationEngine::__construct(SensitiveDataGuard $guard, WhatsAppClient $client)`; `ConversationEngine::handle(IncomingMessage $m): array` → `Reply[]`.
- Stato in `Conversation`: `node` (nodo corrente), `data` (risposte in corso, chiavi = nome del nodo), `history` (nodi già risposti).

- [ ] **Step 1: Write the test helper and the failing tests**

`tests/Feature/Finanziamento/ConversationTestCase.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class ConversationTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $agent = '393331112222';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
        Http::fake([
            'graph.facebook.com/v20.0/FAIL' => Http::response([], 500),
            'graph.facebook.com/*' => Http::response(['url' => 'https://lookaside.fbsbx.com/f', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('BYTES'),
        ]);
    }

    /**
     * Invia in sequenza gli input e restituisce i Reply dell'ultimo.
     * Convenzioni: "#id" = scelta da pulsante/lista, "media:ID:mime" = allegato (l'id `FAIL` simula un download fallito), altro = testo.
     */
    protected function say(string ...$inputs): array
    {
        $engine = app(ConversationEngine::class);
        $replies = [];
        foreach ($inputs as $input) {
            $replies = $engine->handle($this->incoming($input));
        }

        return $replies;
    }

    protected function incoming(string $input): IncomingMessage
    {
        if (str_starts_with($input, '#')) {
            return new IncomingMessage($this->agent, 'interactive', $input, substr($input, 1));
        }
        if (str_starts_with($input, 'media:')) {
            [, $id, $mime] = explode(':', $input, 3);

            return new IncomingMessage($this->agent, 'media', null, null, $id, $mime);
        }

        return new IncomingMessage($this->agent, 'text', $input);
    }

    protected function bodies(array $replies): string
    {
        return implode("\n", array_map(fn ($r) => $r->body, $replies));
    }
}
```

`tests/Feature/Finanziamento/RichiestaFlowTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Services\Conversation\IncomingMessage;

class RichiestaFlowTest extends ConversationTestCase
{
    public function test_un_testo_qualunque_mostra_il_menu(): void
    {
        $replies = $this->say('ciao');

        $this->assertCount(1, $replies);
        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(['menu_richiedi', 'menu_perfeziona', 'menu_stato'], array_keys($replies[0]->options));
        $this->assertSame(0, Conversation::count());
    }

    public function test_il_menu_si_sceglie_anche_digitando(): void
    {
        foreach (['1', 'Richiedi finanziamento', '  RICHIEDI '] as $input) {
            Conversation::query()->delete();
            $replies = $this->say($input);

            $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        }
    }

    public function test_cessione_del_quinto_completa_fino_al_codice(): void
    {
        $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60', '#dip_pub', '#indet', '#anz_10', '#red_2000', '#oltre15', '#no', '#no', '#no');

        $replies = $this->say('#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('quinto', $loan->product);
        $this->assertSame('richiesta', $loan->status);
        $this->assertSame('red_2000', $loan->answers['reddito']);
        $this->assertSame($this->agent, $loan->agent_wa_number);
        $this->assertStringContainsString('FIN-'.now()->year.'-0001', $this->bodies($replies));
        $this->assertSame('completata', Conversation::first()->status);
    }

    public function test_il_riepilogo_elenca_le_risposte_in_chiaro(): void
    {
        $replies = $this->say('#menu_richiedi', '#quinto', '#imp_20k', '#m60', '#dip_pub', '#indet', '#anz_10', '#red_2000', '#oltre15', '#no', '#no', '#no');

        $this->assertCount(2, $replies);
        $this->assertStringContainsString('Importo: 10.000 - 20.000 €', $replies[0]->body);
        $this->assertStringContainsString('Situazione lavorativa: Dipendente pubblico', $replies[0]->body);
        $this->assertSame(['conferma', 'modifica', 'annulla'], array_keys($replies[1]->options));
    }

    public function test_mutuo(): void
    {
        $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('mutuo', $loan->product);
        $this->assertSame('m240', $loan->answers['durata_mutuo']);
        $this->assertSame('ltv_80', $loan->answers['mutuo_ltv']);
    }

    public function test_leasing_prosegue_con_i_dati_aziendali_anonimi(): void
    {
        $this->say('#menu_richiedi', '#leasing', '#auto', '#g_100k', '#m48', '#si', '#no', '#srl', '#anz_3', '#fat_500', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('leasing', $loan->product);
        $this->assertSame('srl', $loan->answers['az_forma']);
        $this->assertArrayNotHasKey('az_finalita', $loan->answers);
    }

    public function test_aziendale(): void
    {
        $this->say('#menu_richiedi', '#aziendale', '#srl', '#anz_10', '#fat_2m', '#g_200k', '#m60', '#investimenti', '#fondo_pmi', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('fondo_pmi', $loan->answers['az_garanzie']);
        $this->assertSame('g_200k', $loan->answers['az_importo']);
    }

    public function test_finalizzato_chiede_bene_e_anticipo(): void
    {
        $this->say('#menu_richiedi', '#finalizzato', '#imp_10k', '#m36', '#altro', '#no', '#no', '#auto_usata', '#imp_10k', '#si', '#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('auto_usata', $loan->answers['bene']);
        $this->assertSame('si', $loan->answers['anticipo']);
    }

    public function test_dati_identificativi_sono_rifiutati_e_la_domanda_si_ripete(): void
    {
        $this->say('#menu_richiedi', '#personale');

        foreach (['RSSMRA80A01H501U', 'rssmra80a01h501u', 'mario@example.com', '333 123 4567'] as $input) {
            $replies = $this->say($input);

            $this->assertStringContainsString('Non inserire dati identificativi', $this->bodies($replies));
            $this->assertStringContainsString('Quale importo', $this->bodies($replies));
            $this->assertSame('importo', Conversation::first()->node);
        }
        $this->assertArrayNotHasKey('importo', Conversation::first()->data);
    }

    public function test_risposta_non_valida_ripete_la_domanda(): void
    {
        $this->say('#menu_richiedi', '#personale');

        $replies = $this->say('boh');

        $this->assertStringContainsString('Scegli una delle opzioni', $this->bodies($replies));
        $this->assertStringContainsString('Quale importo', $this->bodies($replies));
        $this->assertSame('importo', Conversation::first()->node);
    }

    public function test_la_risposta_si_puo_digitare(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k', '#m24', '#dip_priv', '#det', '#anz_1', '#red_1000');

        foreach (['  SÌ ', 'sì', 'si', '1'] as $input) {
            Conversation::first()->update(['node' => 'impegni', 'data' => ['prodotto' => 'personale']]);
            $this->say($input);

            $this->assertSame('rata', Conversation::first()->node, "input: '$input'");
        }
    }

    public function test_indietro_torna_alla_domanda_precedente(): void
    {
        $this->say('#menu_richiedi', '#personale', '#imp_5k');

        $replies = $this->say('indietro');

        $this->assertStringContainsString('Quale importo', $this->bodies($replies));
        $this->assertSame('importo', Conversation::first()->node);
        $this->assertArrayNotHasKey('importo', Conversation::first()->data);
    }

    public function test_indietro_alla_prima_domanda_resta_fermo(): void
    {
        $this->say('#menu_richiedi');

        $replies = $this->say('indietro');

        $this->assertStringContainsString('prima domanda', $this->bodies($replies));
        $this->assertSame('prodotto', Conversation::first()->node);
    }

    public function test_annulla_e_menu_chiudono_la_conversazione(): void
    {
        $this->say('#menu_richiedi', '#mutuo');

        $this->say('annulla');
        $this->assertSame('annullata', Conversation::first()->status);

        $this->say('#menu_richiedi');
        $replies = $this->say('menu');
        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame(2, Conversation::where('status', 'annullata')->count());
    }

    public function test_modifica_ricomincia_il_ramo(): void
    {
        $this->say('#menu_richiedi', '#mutuo', '#prima', '#g_200k', '#ltv_80', '#m240', '#fam_3500', '#int_2', '#fisso');

        $replies = $this->say('#modifica');

        $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        $this->assertSame([], Conversation::first()->data);
        $this->assertSame(0, LoanRequest::count());
    }

    public function test_messaggio_non_supportato_non_rompe_la_conversazione(): void
    {
        $this->say('#menu_richiedi');
        $engine = app(\App\Services\Conversation\ConversationEngine::class);

        $replies = $engine->handle(new IncomingMessage($this->agent, 'unsupported'));

        $this->assertStringContainsString('non è supportato', $this->bodies($replies));
        $this->assertStringContainsString('Che tipo di finanziamento', $this->bodies($replies));
        $this->assertSame('prodotto', Conversation::first()->node);
    }

    public function test_agenti_diversi_hanno_conversazioni_separate(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $other = new IncomingMessage('393339998888', 'interactive', '#menu_richiedi', 'menu_richiedi');
        app(\App\Services\Conversation\ConversationEngine::class)->handle($other);

        $this->assertSame(2, Conversation::count());
        $this->assertSame('mutuo_scopo', Conversation::where('wa_number', $this->agent)->first()->node);
        $this->assertSame('prodotto', Conversation::where('wa_number', '393339998888')->first()->node);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=RichiestaFlowTest`
Expected: FAIL (`Class "App\Services\Conversation\ConversationEngine" not found`)

- [ ] **Step 3: Write the engine**

`app/Services/Conversation/ConversationEngine.php`:

```php
<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\LoanRequest;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ConversationEngine
{
    private const PRIVACY_WARNING = '⚠️ Non inserire dati identificativi del cliente (nome, codice fiscale, telefono, email, P.IVA). In questa fase servono solo dati di profilo.';

    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private SensitiveDataGuard $guard,
        private WhatsAppClient $client,
    ) {}

    /** @return Reply[] */
    public function handle(IncomingMessage $m): array
    {
        $conv = Conversation::with('loanRequest')
            ->where('wa_number', $m->from)->where('status', 'attiva')->latest('id')->first();
        $command = $m->type === 'text' ? $this->normalize($m->text) : null;

        if (in_array($command, ['annulla', 'menu'], true)) {
            $conv?->update(['status' => 'annullata']);

            return $command === 'annulla' ? [Reply::text('Operazione annullata.'), $this->menu()] : [$this->menu()];
        }
        if (! $conv) {
            return $this->fromMenu($m);
        }
        if ($command === 'indietro') {
            return $this->back($conv);
        }

        return $this->answer($conv, $m);
    }

    private function menu(): Reply
    {
        return Reply::choice(config('finanziamento.menu.body'), config('finanziamento.menu.options'));
    }

    private function fromMenu(IncomingMessage $m): array
    {
        $choice = $m->replyId ?? match ($this->normalize((string) $m->text)) {
            '1', 'richiedi', 'richiedi finanziamento' => 'menu_richiedi',
            '2', 'perfeziona', 'perfeziona finanziamento' => 'menu_perfeziona',
            '3', 'stato', 'stato pratiche' => 'menu_stato',
            default => null,
        };

        return match ($choice) {
            'menu_richiedi' => $this->start($m->from, 'richiesta'),
            'menu_perfeziona' => $this->start($m->from, 'perfezionamento'),
            default => [$this->menu()],
        };
    }

    private function start(string $from, string $flow): array
    {
        $conv = Conversation::create([
            'wa_number' => $from, 'flow' => $flow, 'data' => [], 'history' => [],
            'node' => config("finanziamento.flows.{$flow}.start"),
        ]);

        return $this->prompt($conv);
    }

    private function answer(Conversation $conv, IncomingMessage $m): array
    {
        $def = $this->def($conv->flow, $conv->node);

        if ($m->type === 'text' && $conv->flow === 'richiesta' && $this->guard->containsIdentifyingData($m->text)) {
            return [Reply::text(self::PRIVACY_WARNING), ...$this->prompt($conv)];
        }
        if ($m->type === 'unsupported') {
            return [Reply::text('Questo tipo di messaggio non è supportato: rispondi con un testo, una scelta o un file.'), ...$this->prompt($conv)];
        }

        [$value, $error] = $this->read($conv, $def, $m);
        if ($value === null) {
            return [Reply::text($error), ...$this->prompt($conv)];
        }
        if ($def['type'] === 'summary') {
            return $this->finish($conv, $value);
        }

        $data = $conv->data ?? [];
        if ($def['save'] ?? true) {
            $data[$conv->node] = $value;
        }
        $history = $conv->history ?? [];
        $history[] = $conv->node;

        $conv->update([
            'data' => $data,
            'history' => $history,
            'node' => $this->nextNode($def, $conv, $data, $value),
        ]);

        return $this->prompt($conv);
    }

    /** @return array{0: ?string, 1: ?string} [valore, errore] */
    private function read(Conversation $conv, array $def, IncomingMessage $m): array
    {
        return match ($def['type']) {
            'choice', 'summary' => $this->readChoice($def, $m),
            'text' => $this->readText($def, $m),
            'code' => $this->readCode($conv, $m),
            'file' => $this->readFile($conv, $def, $m),
        };
    }

    private function readChoice(array $def, IncomingMessage $m): array
    {
        $value = $m->replyId ?? $this->matchOption($def['options'], (string) $m->text);

        return $value !== null && isset($def['options'][$value])
            ? [$value, null]
            : [null, 'Scegli una delle opzioni proposte.'];
    }

    private function readText(array $def, IncomingMessage $m): array
    {
        if ($m->type !== 'text') {
            return [null, 'Rispondimi con un messaggio di testo.'];
        }

        $value = trim($m->text);
        if ($def['strip_spaces'] ?? false) {
            $value = preg_replace('/\s+/', '', $value);
        }
        if ($def['upper'] ?? false) {
            $value = Str::upper($value);
        }

        return Validator::make(['v' => $value], ['v' => $def['rules']])->passes()
            ? [$value, null]
            : [null, $def['error'] ?? 'Risposta non valida, riprova.'];
    }

    private function matchOption(array $options, string $text): ?string
    {
        $needle = $this->normalize($text);
        if ($needle === '') {
            return null;
        }
        $keys = array_keys($options);
        foreach ($options as $id => $title) {
            if ($needle === $this->normalize((string) $id) || $needle === $this->normalize($title)) {
                return (string) $id;
            }
        }

        return ctype_digit($needle) && isset($keys[(int) $needle - 1]) ? (string) $keys[(int) $needle - 1] : null;
    }

    private function nextNode(array $def, Conversation $conv, array $data, string $value): string
    {
        $next = $def['next'];
        if (is_string($next)) {
            return $this->skip($conv, $next);
        }

        $by = $def['next_by'] ?? 'answer';
        $source = $conv->flow === 'richiesta' ? $data : ($conv->loanRequest->answers ?? []);
        $key = $by === 'answer' ? $value : (string) ($source[$by] ?? '');

        return $this->skip($conv, $next[$key] ?? $next['*'] ?? throw new \LogicException("Salto non definito per '{$key}'"));
    }

    private function skip(Conversation $conv, string $node): string
    {
        $def = $this->def($conv->flow, $node);
        if (($def['skip_if'] ?? null) === 'privacy_received' && $conv->loanRequest?->privacy_received_at) {
            return $this->nextNode($def, $conv, [], '');
        }

        return $node;
    }

    private function back(Conversation $conv): array
    {
        $history = $conv->history ?? [];
        if (! $history) {
            return [Reply::text('Sei già alla prima domanda.'), ...$this->prompt($conv)];
        }

        $previous = array_pop($history);
        $data = $conv->data ?? [];
        unset($data[$previous]);
        $conv->update(['node' => $previous, 'history' => $history, 'data' => $data]);

        return $this->prompt($conv);
    }

    private function finish(Conversation $conv, string $value): array
    {
        return match ($value) {
            'conferma' => $this->complete($conv),
            'modifica' => $this->restart($conv),
            default => $this->cancel($conv),
        };
    }

    private function complete(Conversation $conv): array
    {
        $data = $conv->data ?? [];
        $loan = LoanRequest::create([
            'code' => LoanRequestCode::next(),
            'agent_wa_number' => $conv->wa_number,
            'product' => $data['prodotto'],
            'status' => 'richiesta',
            'answers' => $data,
        ]);
        $conv->update(['status' => 'completata', 'loan_request_id' => $loan->id]);

        return [Reply::text("✅ Richiesta registrata.\n\nCodice pratica: *{$loan->code}*\n\nConservalo: ti servirà per perfezionare il finanziamento con i dati del cliente.")];
    }

    private function restart(Conversation $conv): array
    {
        $conv->update([
            'data' => [], 'history' => [],
            'node' => config("finanziamento.flows.{$conv->flow}.restart"),
        ]);

        return [Reply::text('Ricominciamo.'), ...$this->prompt($conv)];
    }

    private function cancel(Conversation $conv): array
    {
        $conv->update(['status' => 'annullata']);

        return [Reply::text('Operazione annullata.'), $this->menu()];
    }

    /** @return Reply[] */
    private function prompt(Conversation $conv): array
    {
        $def = $this->def($conv->flow, $conv->node);
        $body = $def['prompt'];
        if ($def['prompt_summary'] ?? false) {
            $body = $this->describe($conv->loanRequest->answers, 'richiesta')."\n\n".$body;
        }

        return match ($def['type']) {
            'choice' => [Reply::choice($body, $def['options'])],
            'summary' => [Reply::text($this->summary($conv, $def)), Reply::choice($def['prompt'], $def['options'])],
            'file' => [Reply::text($body.(($def['optional'] ?? false) ? "\n\nScrivi «salta» per saltare." : ''))],
            default => [Reply::text($body)],
        };
    }

    private function summary(Conversation $conv, array $def): string
    {
        $text = "📋 *Riepilogo*\n\n".$this->describe($conv->data ?? [], $conv->flow);

        if (! empty($def['docs'])) {
            $have = $conv->loanRequest->attachments()->pluck('kind')->all();
            $text .= "\n\n*Documenti*\n";
            foreach ($def['docs'] as $kind => $label) {
                $text .= (in_array($kind, $have, true) ? '✅ ' : '➖ ').$label."\n";
            }
        }

        return rtrim($text);
    }

    private function describe(array $answers, string $flow): string
    {
        $lines = [];
        foreach ($answers as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $node = config("finanziamento.flows.{$flow}.nodes.{$key}") ?? [];
            $lines[] = '• '.($node['label'] ?? $key).': '.($node['options'][$value] ?? $value);
        }

        return implode("\n", $lines);
    }

    private function def(string $flow, string $node): array
    {
        return config("finanziamento.flows.{$flow}.nodes.{$node}") ?? throw new \LogicException("Nodo {$flow}.{$node} inesistente");
    }

    private function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim(preg_replace('/\s+/', ' ', $text))));
    }
}
```

Nota: `readCode`, `readFile` e le parti di perfezionamento arrivano nel Task 8; il test di questo task non le esegue.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=RichiestaFlowTest`
Expected: PASS (17 tests). Se `test_la_risposta_si_puo_digitare` fallisce su `'1'`, controlla che `matchOption` usi l'indice 1-based sulle opzioni di `impegni` (`si` è la prima).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Conversation/ConversationEngine.php tests/Feature/Finanziamento
git commit -m "feat: motore di conversazione e fase 1 (richiesta anonima)"
```

---

### Task 8: Fase 2 (perfezionamento), Stato Pratiche e ripresa dopo 24 ore

**Files:**
- Modify: `app/Services/Conversation/ConversationEngine.php`
- Test: `tests/Feature/Finanziamento/PerfezionamentoFlowTest.php`

**Interfaces:**
- Consumes: `WhatsAppClient::downloadMedia(string): ?array` (Task 5); `Attachment`, `LoanRequest` (Task 1).
- Produces: nel motore, `readCode`, `readFile`, `completePerfezionamento` (dentro `complete`), `stato`, `resume`/`isStale`. Stati pratica: `richiesta` → `in_attesa_informativa` → `informativa_ricevuta` → `perfezionata`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Finanziamento/PerfezionamentoFlowTest.php`:

```php
<?php

namespace Tests\Feature\Finanziamento;

use App\Models\Conversation;
use App\Models\LoanRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PerfezionamentoFlowTest extends ConversationTestCase
{
    private function loan(array $overrides = []): LoanRequest
    {
        return LoanRequest::create($overrides + [
            'code' => 'FIN-2026-0007', 'agent_wa_number' => $this->agent, 'product' => 'personale',
            'status' => 'richiesta', 'answers' => ['prodotto' => 'personale', 'importo' => 'imp_10k'],
        ]);
    }

    private function personal(): array
    {
        return ['Mario', 'Rossi', 'rssmra80a01h501u', '01/01/1980', 'Roma', 'Via Roma 1, 00100 Roma', '#coniugato', '#ci',
            'AB123456', '01/01/2030', '+39 333 1234567', 'mario@example.com', 'it60 x054 2811 1010 0000 0123 456'];
    }

    public function test_percorso_completo_con_informativa_e_dati_cifrati(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'fin-2026-0007');
        $replies = $this->say('#si');
        $this->assertStringContainsString('informativa privacy firmata', $this->bodies($replies));

        $this->say('media:M1:application/pdf', ...$this->personal());
        $this->say('ACME Srl', '01/03/2015', 'media:D1:image/jpeg', 'media:D2:image/jpeg');
        $summary = $this->say('salta');

        $this->assertStringContainsString('✅ Documento d\'identità', $summary[0]->body);
        $this->assertStringContainsString('➖ Documento di reddito', $summary[0]->body);

        $replies = $this->say('#conferma');

        $loan = LoanRequest::firstOrFail();
        $this->assertSame('perfezionata', $loan->status);
        $this->assertNotNull($loan->perfected_at);
        $this->assertNotNull($loan->privacy_received_at);
        $this->assertSame('Mario', $loan->personal['nome']);
        $this->assertSame('RSSMRA80A01H501U', $loan->personal['codice_fiscale']);
        $this->assertSame('IT60X0542811101000000123456', $loan->personal['iban']);
        $this->assertSame('+393331234567', $loan->personal['telefono']);
        $this->assertStringNotContainsString('Mario', DB::table('loan_requests')->value('personal'));
        $this->assertSame(3, $loan->attachments()->count());
        foreach ($loan->attachments as $a) {
            Storage::disk('local')->assertExists($a->path);
        }
        $this->assertStringContainsString('perfezionata', $this->bodies($replies));
    }

    public function test_nessun_dato_personale_prima_dell_informativa(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('Mario');

        $this->assertStringContainsString('Invia una foto o un PDF', $this->bodies($replies));
        $this->assertSame('informativa', Conversation::first()->node);
        $this->assertSame([], Conversation::first()->data);
        $this->assertSame('in_attesa_informativa', LoanRequest::first()->status);
        $this->assertNull(LoanRequest::first()->privacy_received_at);
    }

    public function test_dopo_l_informativa_si_sblocca_e_traccia_la_ricezione(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:M1:image/jpeg');

        $this->assertStringContainsString('Nome del cliente', $this->bodies($replies));
        $loan = LoanRequest::first();
        $this->assertSame('informativa_ricevuta', $loan->status);
        $this->assertNotNull($loan->privacy_received_at);
        $this->assertSame('informativa', $loan->attachments()->first()->kind);
    }

    public function test_informativa_gia_ricevuta_viene_saltata(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $this->assertStringContainsString('Nome del cliente', $this->bodies($replies));
    }

    public function test_formato_file_non_accettato(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:M1:video/mp4');

        $this->assertStringContainsString('Formato non accettato', $this->bodies($replies));
        $this->assertSame('informativa', Conversation::first()->node);
    }

    public function test_download_fallito(): void
    {
        $this->loan();
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');

        $replies = $this->say('media:FAIL:image/jpeg');

        $this->assertStringContainsString('Non sono riuscito a scaricare', $this->bodies($replies));
        $this->assertNull(LoanRequest::first()->privacy_received_at);
    }

    public function test_codici_non_validi(): void
    {
        $this->loan();
        $this->loan(['code' => 'FIN-2026-0008', 'agent_wa_number' => '393339998888']);
        $this->loan(['code' => 'FIN-2026-0009', 'status' => 'perfezionata']);
        $this->say('#menu_perfeziona');

        $this->assertStringContainsString('Codice non trovato', $this->bodies($this->say('FIN-2026-9999')));
        $this->assertStringContainsString('Codice non trovato', $this->bodies($this->say('FIN-2026-0008')));
        $this->assertStringContainsString('già stata perfezionata', $this->bodies($this->say('FIN-2026-0009')));
        $this->assertSame('codice', Conversation::first()->node);
    }

    public function test_la_conferma_mostra_il_riepilogo_anonimo_e_no_riparte(): void
    {
        $this->loan();
        $replies = $this->say('#menu_perfeziona', 'FIN-2026-0007');

        $this->assertStringContainsString('Importo: 5.000 - 10.000 €', $this->bodies($replies));
        $this->assertStringContainsString('È la pratica giusta?', $this->bodies($replies));
        $this->assertStringContainsString('Inserisci il codice', $this->bodies($this->say('#no')));
    }

    public function test_dati_non_validi_vengono_rifiutati(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', 'Mario', 'Rossi');

        $this->assertStringContainsString('Codice fiscale non valido', $this->bodies($this->say('ABC')));
        $this->say('RSSMRA80A01H501U');
        $this->assertStringContainsString('Data non valida', $this->bodies($this->say('31/02/1980')));
        $this->assertStringContainsString('Data non valida', $this->bodies($this->say('1980-01-01')));
        $this->say('01/01/1980', 'Roma', 'Via Roma 1', '#celibe', '#ci');
        $this->assertSame('documento_numero', Conversation::first()->node);
    }

    public function test_un_testo_dove_serve_un_file_viene_rifiutato_ma_il_reddito_si_salta(): void
    {
        $this->loan(['status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);
        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si');
        Conversation::first()->update(['node' => 'doc_identita', 'data' => []]);

        $this->assertStringContainsString('Invia una foto o un PDF', $this->bodies($this->say('ecco')));
        $this->say('media:D1:image/png', 'media:D2:image/png');
        $this->assertSame('doc_reddito', Conversation::first()->node);
        $this->say('salta');
        $this->assertSame('riepilogo_p', Conversation::first()->node);
    }

    public function test_prodotti_aziendali_chiedono_ragione_sociale_e_partita_iva(): void
    {
        $this->loan(['product' => 'aziendale', 'answers' => ['prodotto' => 'aziendale'],
            'status' => 'informativa_ricevuta', 'privacy_received_at' => now()]);

        $this->say('#menu_perfeziona', 'FIN-2026-0007', '#si', ...$this->personal());
        $this->assertSame('ragione_sociale', Conversation::first()->node);

        $this->assertStringContainsString('11 cifre', $this->bodies($this->say('Acme Srl', '123')));
        $this->say('123 4567 8901');
        $this->assertSame('doc_identita', Conversation::first()->node);
    }

    public function test_stato_pratiche_elenca_solo_quelle_dell_agente(): void
    {
        $this->assertStringContainsString('nessuna pratica', $this->bodies($this->say('#menu_stato')));

        $this->loan();
        $this->loan(['code' => 'FIN-2026-0008', 'agent_wa_number' => '393339998888']);

        $text = $this->bodies($this->say('3'));
        $this->assertStringContainsString('FIN-2026-0007 · Prestito personale · richiesta', $text);
        $this->assertStringNotContainsString('FIN-2026-0008', $text);
    }

    public function test_dopo_24_ore_chiede_se_continuare(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $conv = Conversation::first();
        $conv->updated_at = now()->subDays(2);
        $conv->saveQuietly();

        $replies = $this->say('#prima');
        $this->assertStringContainsString('più di 24 ore', $this->bodies($replies));
        $this->assertSame('mutuo_scopo', Conversation::first()->node);

        $replies = $this->say('#resume_si');
        $this->assertStringContainsString('scopo del mutuo', $this->bodies($replies));

        $this->say('#prima');
        $this->assertSame('mutuo_valore', Conversation::first()->node);
    }

    public function test_dopo_24_ore_si_puo_ricominciare(): void
    {
        $this->say('#menu_richiedi', '#mutuo');
        $conv = Conversation::first();
        $conv->updated_at = now()->subDays(2);
        $conv->saveQuietly();

        $this->say('ciao');
        $replies = $this->say('#resume_no');

        $this->assertSame('list', $replies[0]->kind);
        $this->assertSame('annullata', Conversation::first()->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PerfezionamentoFlowTest`
Expected: FAIL (`Call to undefined method ...::readCode()` e simili)

- [ ] **Step 3: Extend the engine**

In `app/Services/Conversation/ConversationEngine.php`:

(a) In `handle()`, sostituisci il blocco

```php
        if ($command === 'indietro') {
            return $this->back($conv);
        }

        return $this->answer($conv, $m);
```

con

```php
        if ($this->isStale($conv)) {
            return $this->resume($conv, $m);
        }
        if ($command === 'indietro') {
            return $this->back($conv);
        }

        return $this->answer($conv, $m);
```

(b) In `fromMenu()`, aggiungi nel `match ($choice)` la riga `'menu_stato' => $this->stato($m->from),` prima di `default`.

(c) Sostituisci `complete()` con una versione che distingue i flussi:

```php
    private function complete(Conversation $conv): array
    {
        return $conv->flow === 'richiesta' ? $this->completeRichiesta($conv) : $this->completePerfezionamento($conv);
    }

    private function completeRichiesta(Conversation $conv): array
    {
        $data = $conv->data ?? [];
        $loan = LoanRequest::create([
            'code' => LoanRequestCode::next(),
            'agent_wa_number' => $conv->wa_number,
            'product' => $data['prodotto'],
            'status' => 'richiesta',
            'answers' => $data,
        ]);
        $conv->update(['status' => 'completata', 'loan_request_id' => $loan->id]);

        return [Reply::text("✅ Richiesta registrata.\n\nCodice pratica: *{$loan->code}*\n\nConservalo: ti servirà per perfezionare il finanziamento con i dati del cliente.")];
    }

    private function completePerfezionamento(Conversation $conv): array
    {
        $loan = $conv->loanRequest;
        $loan->update(['personal' => $conv->data ?? [], 'status' => 'perfezionata', 'perfected_at' => now()]);
        $conv->update(['status' => 'completata']);

        return [Reply::text("✅ Pratica *{$loan->code}* perfezionata.")];
    }
```

(d) Aggiungi questi metodi prima della parentesi graffa finale della classe:

```php
    private function readCode(Conversation $conv, IncomingMessage $m): array
    {
        if ($m->type !== 'text') {
            return [null, 'Scrivi il codice della pratica.'];
        }

        $code = Str::upper(trim($m->text));
        $loan = LoanRequest::where('code', $code)->where('agent_wa_number', $conv->wa_number)->first();
        if (! $loan) {
            return [null, 'Codice non trovato. Controlla e riprova.'];
        }
        if ($loan->status === 'perfezionata') {
            return [null, 'Questa pratica è già stata perfezionata.'];
        }
        if ($loan->status === 'richiesta') {
            $loan->update(['status' => 'in_attesa_informativa']);
        }

        $conv->loan_request_id = $loan->id;
        $conv->setRelation('loanRequest', $loan);

        return [$code, null];
    }

    private function readFile(Conversation $conv, array $def, IncomingMessage $m): array
    {
        if ($m->type === 'text' && ($def['optional'] ?? false) && $this->normalize($m->text) === 'salta') {
            return ['salta', null];
        }
        if ($m->type !== 'media') {
            return [null, 'Invia una foto o un PDF.'];
        }
        if (! isset(self::ALLOWED_MIME[$m->mime])) {
            return [null, 'Formato non accettato: invia una foto (JPG, PNG) o un PDF.'];
        }

        $file = $this->client->downloadMedia($m->mediaId);
        if (! $file) {
            return [null, 'Non sono riuscito a scaricare il file. Riprova.'];
        }

        $loan = $conv->loanRequest;
        $path = "pratiche/{$loan->code}/{$def['kind']}-".Str::random(8).'.'.self::ALLOWED_MIME[$m->mime];
        Storage::disk('local')->put($path, $file['body']);
        $loan->attachments()->create([
            'kind' => $def['kind'], 'path' => $path, 'mime' => $m->mime,
            'wa_media_id' => $m->mediaId, 'received_at' => now(),
        ]);
        if ($def['kind'] === 'informativa') {
            $loan->update(['privacy_received_at' => now(), 'status' => 'informativa_ricevuta']);
        }

        return ['ricevuto', null];
    }

    private function stato(string $from): array
    {
        $loans = LoanRequest::where('agent_wa_number', $from)->latest('id')->limit(10)->get();
        if ($loans->isEmpty()) {
            return [Reply::text('Non hai ancora nessuna pratica.')];
        }

        $products = config('finanziamento.flows.richiesta.nodes.prodotto.options');
        $lines = $loans->map(fn ($l) => "• {$l->code} · ".($products[$l->product] ?? $l->product).' · '.str_replace('_', ' ', $l->status));

        return [Reply::text("📂 *Le tue pratiche*\n\n".$lines->implode("\n"))];
    }

    private function isStale(Conversation $conv): bool
    {
        return $conv->updated_at->lt(now()->subDay()) || ! empty($conv->data['_resume']);
    }

    private function resume(Conversation $conv, IncomingMessage $m): array
    {
        $data = $conv->data ?? [];

        if (! empty($data['_resume'])) {
            if ($m->replyId === 'resume_si') {
                unset($data['_resume']);
                $conv->update(['data' => $data]);

                return $this->prompt($conv);
            }
            if ($m->replyId === 'resume_no') {
                $conv->update(['status' => 'annullata']);

                return [$this->menu()];
            }
        } else {
            $data['_resume'] = true;
            $conv->update(['data' => $data]);
        }

        return [Reply::choice('La conversazione precedente è ferma da più di 24 ore. Vuoi continuare?', [
            'resume_si' => 'Continua', 'resume_no' => 'Ricomincia',
        ])];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="PerfezionamentoFlowTest|RichiestaFlowTest"`
Expected: PASS (tutti). Se `test_dati_non_validi_vengono_rifiutati` fallisce su `31/02/1980`, verifica che la regola sia `date_format:d/m/Y` (Laravel confronta il formato ricostruito, quindi 31/02 viene rifiutata).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Conversation/ConversationEngine.php tests/Feature/Finanziamento/PerfezionamentoFlowTest.php
git commit -m "feat: perfezionamento con informativa, stato pratiche e ripresa dopo 24 ore"
```

---

### Task 9: Collegare il controller

**Files:**
- Modify: `app/Http/Controllers/WhatsAppController.php`
- Test: `tests/Feature/WhatsAppWebhookTest.php`

**Interfaces:**
- Consumes: `IncomingMessage::fromWebhook`, `ConversationEngine::handle`, `WhatsAppClient::send`.
- Produces: `POST /api/whatsapp/webhook` risponde sempre 200 `{"status":"EVENT_RECEIVED"}`; i `Reply` vengono inviati in transazione e, se un invio fallisce, lo stato della conversazione torna indietro. `verifyWebhook` resta invariato.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.token' => 'TOK', 'services.whatsapp.phone_number_id' => '555']);
    }

    private function text(string $body): array
    {
        return ['entry' => [['changes' => [['value' => ['messages' => [
            ['from' => '393331112222', 'type' => 'text', 'text' => ['body' => $body]],
        ]]]]]]];
    }

    public function test_un_testo_riceve_il_menu_a_lista(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]])]);

        $this->postJson('/api/whatsapp/webhook', $this->text('ciao'))
            ->assertOk()->assertJson(['status' => 'EVENT_RECEIVED']);

        Http::assertSent(fn (Request $r) => $r['to'] === '393331112222'
            && $r['interactive']['type'] === 'list'
            && $r['interactive']['action']['sections'][0]['rows'][0]['title'] === 'Richiedi Finanziamento');
    }

    public function test_gli_eventi_di_stato_non_fanno_nulla(): void
    {
        Http::fake();
        $statuses = ['entry' => [['changes' => [['value' => ['statuses' => [['status' => 'delivered']]]]]]]];

        $this->postJson('/api/whatsapp/webhook', $statuses)->assertOk();
        $this->postJson('/api/whatsapp/webhook', [])->assertOk();

        Http::assertNothingSent();
    }

    public function test_la_conversazione_parte_e_invia_la_prima_domanda(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);

        $this->postJson('/api/whatsapp/webhook', $this->text('1'))->assertOk();

        $this->assertSame('prodotto', Conversation::first()->node);
        Http::assertSent(fn (Request $r) => str_contains($r['interactive']['body']['text'] ?? '', 'Che tipo di finanziamento'));
    }

    public function test_se_l_invio_fallisce_lo_stato_non_avanza(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no']], 500)]);

        $this->postJson('/api/whatsapp/webhook', $this->text('1'))->assertOk();

        $this->assertSame(0, Conversation::count());
    }

    public function test_un_errore_interno_non_fa_ripetere_i_retry_a_meta(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([])]);
        Conversation::create(['wa_number' => '393331112222', 'flow' => 'richiesta', 'node' => 'nodo_inesistente', 'data' => [], 'history' => []]);

        $this->postJson('/api/whatsapp/webhook', $this->text('ciao'))->assertOk();
    }

    public function test_la_verifica_del_webhook_resta_invariata(): void
    {
        config(['services.whatsapp.verify_token' => 'UnicoAgent']);

        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=UnicoAgent&hub.challenge=abc')
            ->assertOk()->assertSee('abc');
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=x&hub.challenge=abc')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WhatsAppWebhookTest`
Expected: FAIL (il controller attuale non usa il motore: `test_un_testo_riceve_il_menu_a_lista` e `test_la_conversazione_parte...` falliscono)

- [ ] **Step 3: Replace the controller**

`app/Http/Controllers/WhatsAppController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\Conversation\ConversationEngine;
use App\Services\Conversation\IncomingMessage;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Handshake GET per verifica iniziale del Webhook
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Token non valido', 403);
    }

    /**
     * Messaggi in arrivo: il motore decide cosa rispondere, qui si inviano i Reply.
     * Se un invio fallisce la transazione si annulla e lo stato non avanza.
     */
    public function handleWebhook(Request $request, ConversationEngine $engine, WhatsAppClient $client)
    {
        $message = IncomingMessage::fromWebhook($request->all());

        if ($message) {
            try {
                DB::transaction(function () use ($engine, $client, $message) {
                    foreach ($engine->handle($message) as $reply) {
                        if (! $client->send($message->from, $reply)) {
                            throw new \RuntimeException('Invio WhatsApp fallito');
                        }
                    }
                });
            } catch (\Throwable $e) {
                Log::error('Errore gestione webhook WhatsApp: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WhatsAppWebhookTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WhatsAppController.php tests/Feature/WhatsAppWebhookTest.php
git commit -m "feat: il webhook usa il motore di conversazione"
```

---

### Task 10: Verifica finale

**Files:**
- Modify: nessuno (solo formattazione automatica)

- [ ] **Step 1: Format**

Run: `vendor/bin/pint --dirty`
Expected: i file nuovi vengono formattati senza errori.

- [ ] **Step 2: Run the whole suite**

Run: `php artisan test`
Expected: tutti i test passano (inclusi `ExampleTest`).

- [ ] **Step 3: Verifica config cache**

Run: `php artisan config:cache && php artisan config:clear`
Expected: nessun errore (l'albero non contiene closure).

- [ ] **Step 4: Controlla le rotte**

Run: `php artisan route:list --path=whatsapp`
Expected: `GET|HEAD api/whatsapp/webhook` e `POST api/whatsapp/webhook`.

- [ ] **Step 5: Commit e promemoria di rilascio**

```bash
git add -A
git commit -m "chore: formattazione con pint"
```

Prima di usare il bot in produzione, **chiedi conferma all'utente** ed esegui sul server:
1. `php artisan migrate` (crea le tre tabelle sul database MySQL `twillio`);
2. `touch storage/logs/laravel.log && chmod 666 storage/logs/laravel.log` se il log non è scrivibile da Apache;
3. `php artisan config:clear`.
Poi prova dal telefono: menu → "Richiedi Finanziamento" → conferma → ricevi il codice → "Perfeziona Finanziamento".
