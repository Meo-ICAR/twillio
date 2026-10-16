<?php

namespace Tests\Feature;

use App\Models\User;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SocialiteLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function providerOauth(): array
    {
        return [
            'microsoft' => ['microsoft'],
            'google' => ['google'],
        ];
    }

    #[DataProvider('providerOauth')]
    public function test_il_login_offre_il_provider(string $provider): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertSee(ucfirst($provider), false);
        $response->assertSee('/admin/oauth/'.$provider, false);
    }

    #[DataProvider('providerOauth')]
    public function test_il_redirect_oauth_va_al_provider(string $provider): void
    {
        Socialite::fake($provider);

        $response = $this->get('/admin/oauth/'.$provider);

        $response->assertRedirect('https://socialite.fake/'.$provider.'/authorize');
    }

    #[DataProvider('providerOauth')]
    public function test_un_utente_esistente_entra_e_collega_il_provider(string $provider): void
    {
        $user = User::factory()->create([
            'email' => $provider.'.'.uniqid().'@example.test',
        ]);
        Socialite::fake($provider, OAuthUser::fake([
            'id' => $provider.'-account-1',
            'email' => $user->email,
            'name' => $user->name,
        ]));

        $response = $this->get('/admin/oauth/callback/'.$provider);

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(SocialiteUser::query()
            ->where('provider', $provider)
            ->where('provider_id', $provider.'-account-1')
            ->where('user_id', $user->getKey())
            ->first());
    }

    public function test_un_email_sconosciuta_non_crea_un_utente(): void
    {
        $email = 'sconosciuto.'.uniqid().'@example.test';
        Socialite::fake('google', OAuthUser::fake([
            'id' => 'google-unknown',
            'email' => $email,
        ]));

        $response = $this->get('/admin/oauth/callback/google');

        $response->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest();
        $this->assertNull(SocialiteUser::query()->where('provider_id', 'google-unknown')->first());
        $this->assertNull(User::query()->where('email', $email)->first());
    }
}
