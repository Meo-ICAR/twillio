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
