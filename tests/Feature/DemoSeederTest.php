<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_il_seeder_crea_l_admin_demo_che_entra_nel_pannello(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('Admin', $admin->name);
        $this->assertTrue(Hash::check('demo1234', $admin->password));
        $this->assertNotSame('demo1234', $admin->password);
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_il_seeder_e_ripetibile_e_non_sovrascrive_l_admin_esistente(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@example.com')->first()->update(['name' => 'Cambiato', 'password' => 'nuova-password-sicura']);
        Company::first()->update(['name' => 'Cliente Reale Spa']);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $this->assertSame('Cambiato', $admin->name);
        $this->assertTrue(Hash::check('nuova-password-sicura', $admin->password));
        $this->assertSame(1, Company::count());
        $this->assertSame('Cliente Reale Spa', Company::first()->name);
    }

    public function test_il_seeder_crea_un_azienda_demo_evidentemente_fittizia(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::firstOrFail();
        $this->assertStringContainsString('Demo', $company->name);
        $this->assertStringEndsWith('.example', $company->email);
        $this->get('/privacy')->assertOk()->assertSee($company->name)->assertDontSee('da completare');
    }
}
