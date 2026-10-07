<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L'informativa firmata diventa un documento della pratica come gli altri (letto dall'AI: nostro modulo, firmato).
     * Aggiunge la voce al catalogo di ogni prodotto già presente e, per le pratiche che l'hanno già ricevuta quando bastava
     * riceverla, la dà per accettata. Ripetibile.
     */
    public function up(): void
    {
        $name = 'Informativa firmata';
        $description = 'Il nostro modulo di informativa privacy, scaricato dal link, stampato e firmato dal cliente';

        foreach (DB::table('finanziamento_documents')->distinct()->pluck('product') as $product) {
            if (! DB::table('finanziamento_documents')->where('product', $product)->where('code', 'informativa')->exists()) {
                DB::table('finanziamento_documents')->insert([
                    'product' => $product, 'code' => 'informativa', 'name' => $name, 'description' => $description,
                    'requirement' => 'obbligatorio', 'ai_kind' => 'informativa', 'sort_order' => 0, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $note = Crypt::encryptString(json_encode([[
            'by' => 'operatore', 'user_id' => null, 'at' => now()->toIso8601String(),
            'text' => 'Accettata prima che l\'informativa venisse controllata in automatico.',
        ]]));

        foreach (DB::table('loan_requests')->whereNotNull('privacy_received_at')->whereNull('privacy_verified_at')->get() as $loan) {
            DB::table('loan_requests')->where('id', $loan->id)->update(['privacy_verified_at' => $loan->privacy_received_at]);

            $slot = DB::table('pratica_documents')->where('loan_request_id', $loan->id)->where('code', 'informativa')->first();
            if (! $slot) {
                $slotId = DB::table('pratica_documents')->insertGetId([
                    'loan_request_id' => $loan->id,
                    'finanziamento_document_id' => DB::table('finanziamento_documents')->where('product', $loan->product)->where('code', 'informativa')->value('id'),
                    'code' => 'informativa', 'name' => $name, 'requirement' => 'obbligatorio', 'status' => 'ok',
                    'annotations' => $note, 'sort_order' => 0, 'received_at' => $loan->privacy_received_at, 'reviewed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('attachments')->where('loan_request_id', $loan->id)->where('kind', 'informativa')->whereNull('pratica_document_id')
                ->update(['pratica_document_id' => $slot->id ?? $slotId]);
        }
    }

    public function down(): void
    {
        // Non si toglie: le pratiche potrebbero già usarlo.
    }
};
