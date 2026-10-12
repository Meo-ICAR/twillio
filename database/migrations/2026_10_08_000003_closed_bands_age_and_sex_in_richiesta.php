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
