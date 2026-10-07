<?php

namespace Tests\Feature\Flows;

use Database\Seeders\FlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FlowSeeder::class);
        $this->dir = sys_get_temp_dir().'/export-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_stampa_il_file_a_video(): void
    {
        $this->artisan('flows:export', ['--stdout' => true])->expectsOutputToContain('return [')->assertSuccessful();
    }

    public function test_scrive_il_file_nel_percorso_indicato(): void
    {
        $path = $this->dir.'/finanziamento.php';

        $this->artisan('flows:export', ['--path' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $this->assertSame(['checks', 'menu', 'flows'], array_keys(require $path));
    }

    public function test_senza_opzioni_scrive_in_storage_con_data_nel_nome(): void
    {
        $this->artisan('flows:export')->assertSuccessful();

        $files = glob(storage_path('app/exports/finanziamento-*.php'));
        $this->assertNotEmpty($files);
        array_map('unlink', $files);
    }

    public function test_to_config_sostituisce_il_file_di_configurazione_dopo_aver_fatto_una_copia(): void
    {
        $target = $this->dir.'/finanziamento.php';
        file_put_contents($target, "<?php\n\nreturn ['vecchio' => true];\n");

        $this->artisan('flows:export', ['--to-config' => true, '--config-path' => $target])->assertSuccessful();

        $this->assertSame(['checks', 'menu', 'flows'], array_keys(require $target));
        $backups = glob($target.'.bak-*');
        $this->assertCount(1, $backups);
        $this->assertStringContainsString("'vecchio' => true", file_get_contents($backups[0]));
    }

    public function test_to_config_crea_il_file_se_non_esiste_senza_copia(): void
    {
        $target = $this->dir.'/nuovo.php';

        $this->artisan('flows:export', ['--to-config' => true, '--config-path' => $target])->assertSuccessful();

        $this->assertFileExists($target);
        $this->assertSame([], glob($target.'.bak-*'));
    }
}
