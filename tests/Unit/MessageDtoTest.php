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
