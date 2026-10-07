<?php

namespace Tests\Unit;

use App\Services\Flows\LegacyJumps;
use PHPUnit\Framework\TestCase;

class LegacyJumpsTest extends TestCase
{
    public function test_un_salto_fisso_diventa_il_salto_predefinito(): void
    {
        $this->assertSame([['when' => '*', 'go_to' => 'durata']], LegacyJumps::fromColumns('durata', null));
    }

    public function test_una_mappa_mantiene_ordine_e_chiavi(): void
    {
        $this->assertSame(
            [['when' => 'si', 'go_to' => 'rata'], ['when' => 'no', 'go_to' => 'crif'], ['when' => '*', 'go_to' => 'fine']],
            LegacyJumps::fromColumns(null, ['si' => 'rata', 'no' => 'crif', '*' => 'fine'])
        );
    }

    public function test_senza_salti_non_ci_sono_righe(): void
    {
        $this->assertSame([], LegacyJumps::fromColumns(null, null));
        $this->assertSame([], LegacyJumps::fromColumns('', []));
    }

    public function test_le_chiavi_numeriche_restano_testo(): void
    {
        $this->assertSame([['when' => '1', 'go_to' => 'a']], LegacyJumps::fromColumns(null, [1 => 'a']));
    }
}
