<?php

namespace Tests\Feature;

use App\Services\License\LicenseService;
use App\Services\License\LicenseState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * COM-02: the vendor-side issuer (tools/license-issuer/issue.php) and the
 * in-product verifier agree on the signed format, and the issuer refuses to
 * keep private keys inside the repository.
 */
class LicenseIssuerToolTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    /** @var list<string> */
    private array $php;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('ext-sodium is required (present in the backend image and CI).');
        }

        // The child PHP must have sodium too: load it explicitly when the
        // binary's own ini doesn't (e.g. a local XAMPP install).
        $probe = new Process([PHP_BINARY, '-r', 'echo extension_loaded("sodium") ? 1 : 0;']);
        $probe->run();
        $this->php = trim($probe->getOutput()) === '1' ? [PHP_BINARY] : [PHP_BINARY, '-d', 'extension=sodium'];

        $this->work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sc-issuer-'.uniqid();
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    /** @param  list<string>  $args */
    private function issuer(array $args): Process
    {
        $process = new Process([...$this->php, base_path('../../tools/license-issuer/issue.php'), ...$args]);
        $process->run();

        return $process;
    }

    public function test_issued_license_installs_in_the_product(): void
    {
        $secret = $this->work.DIRECTORY_SEPARATOR.'vendor-k1.secret';
        $keygen = $this->issuer(['keygen', '--key-id=vendor-k1', "--out={$secret}"]);
        $this->assertSame(0, $keygen->getExitCode(), $keygen->getErrorOutput());
        $this->assertMatchesRegularExpression('/public key \(vendor-k1\): (\S+)/', $keygen->getOutput());
        preg_match('/public key \(vendor-k1\): (\S+)/', $keygen->getOutput(), $m);
        config(['license.trusted_keys' => ['vendor-k1' => $m[1]]]);

        $licenses = app(LicenseService::class);
        $spec = $this->work.DIRECTORY_SEPARATOR.'spec.json';
        File::put($spec, json_encode([
            'installation_id' => $licenses->installationId(),
            'licensee' => ['name' => 'Pilot College'],
            'edition' => 'COMMERCIAL',
            'plan' => 'STANDARD',
            'features' => ['face', 'monitor', 'report'],
            'limits' => ['max_cameras' => 8, 'max_ai_nodes' => 2],
            'not_before' => now()->subHour()->toIso8601String(),
            'expires_at' => now()->addYear()->toIso8601String(),
            'grace_days' => 14,
        ]));
        $out = $this->work.DIRECTORY_SEPARATOR.'pilot.lic';

        $sign = $this->issuer(['sign', "--key={$secret}", '--key-id=vendor-k1', "--spec={$spec}", "--out={$out}"]);
        $this->assertSame(0, $sign->getExitCode(), $sign->getErrorOutput());

        $licenses->install(File::get($out), null);
        $state = $licenses->state();
        $this->assertSame(LicenseState::VALID, $state->state);
        $this->assertSame(8, $state->limit('max_cameras'));

        $verify = $this->issuer(['verify', "--public-key={$m[1]}", "--in={$out}"]);
        $this->assertSame(0, $verify->getExitCode());
        $this->assertStringContainsString('SIGNATURE OK', $verify->getOutput());
    }

    public function test_issued_revocation_list_imports_in_the_product(): void
    {
        $secret = $this->work.DIRECTORY_SEPARATOR.'k.secret';
        preg_match('/: (\S+)$/m', $this->issuer(['keygen', '--key-id=k', "--out={$secret}"])->getOutput(), $m);
        config(['license.trusted_keys' => ['k' => $m[1]]]);
        $out = $this->work.DIRECTORY_SEPARATOR.'rev.lic';
        $id = '0b8f2c1e-5a3d-4c7e-9f10-2b3c4d5e6f70';

        $revoke = $this->issuer(['revoke', "--key={$secret}", '--key-id=k', '--sequence=1', "--ids={$id}", "--out={$out}"]);
        $this->assertSame(0, $revoke->getExitCode(), $revoke->getErrorOutput());

        $this->assertSame(1, app(LicenseService::class)->importRevocationList(File::get($out), null));
        $this->assertDatabaseHas('license_revocations', ['license_id' => $id]);
    }

    public function test_issuer_refuses_private_keys_inside_the_repository(): void
    {
        $inRepo = base_path('storage/framework/testing-should-not-exist.secret');

        $keygen = $this->issuer(['keygen', '--key-id=k', "--out={$inRepo}"]);

        $this->assertSame(1, $keygen->getExitCode());
        $this->assertStringContainsString('outside the repository', $keygen->getErrorOutput());
        $this->assertFileDoesNotExist($inRepo);
    }
}
