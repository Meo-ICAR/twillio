<?php

namespace App\Services\Documents;

use App\Models\PraticaDocument;
use App\Services\Conversation\Reply;
use App\Services\Whatsapp\WhatsAppClient;
use Illuminate\Support\Facades\Log;

/**
 * Avvisa l'agente su WhatsApp quando l'istruttore rifiuta un documento o ne chiede uno in più.
 * Best effort: WhatsApp accetta testi liberi solo entro 24 ore dall'ultimo messaggio dell'agente;
 * se l'invio non riesce l'agente trova comunque la richiesta in Stato Pratiche.
 */
class AgentNotifier
{
    public function __construct(private WhatsAppClient $client) {}

    public function notify(PraticaDocument $slot): bool
    {
        $loan = $slot->loanRequest;
        $note = $slot->lastAnnotation();
        $action = "\n\nPuoi caricarlo da Stato Pratiche → Carica documenti.";

        $text = match ($slot->status) {
            'rejected' => "⚠️ {$loan->code} · {$slot->name}: l'istruttore ha rifiutato il documento.".($note ? "\n{$note}" : '').$action,
            'integrazione_richiesta' => "📝 {$loan->code}: l'istruttore chiede un'integrazione — {$slot->name}.".($note ? "\n{$note}" : '').$action,
            default => null,
        };

        if ($text === null) {
            return false;
        }

        try {
            return $this->client->send($loan->agent_wa_number, Reply::text($text));
        } catch (\Throwable $e) {
            Log::error('Avviso all\'agente non inviato', ['pratica' => $loan->code, 'exception' => $e::class]);

            return false;
        }
    }
}
