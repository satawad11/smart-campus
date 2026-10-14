<?php

namespace Tests\Feature;

use App\Console\Commands\SupportDiagnosticsCommand;
use App\Models\AiNode;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * COM-05: the support bundle carries diagnostics but no personal or
 * biometric data, and redacts log lines.
 */
class SupportDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = storage_path('app/diagnostics/test-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        File::delete($this->out);
        parent::tearDown();
    }

    public function test_bundle_has_diagnostics_and_no_personal_data(): void
    {
        Person::factory()->create(['first_name' => 'Unique-Name-XYZ']);
        AiNode::factory()->create(['node_code' => 'NODE-A', 'model_code' => 'm', 'model_version' => '1']);

        $this->artisan('support:diagnostics', ['--out' => $this->out])->assertSuccessful();

        $json = File::get($this->out);
        $bundle = json_decode($json, true);
        foreach (['app', 'health', 'preflight', 'license', 'counts', 'ai_nodes', 'open_alerts', 'backups', 'recent_errors'] as $key) {
            $this->assertArrayHasKey($key, $bundle);
        }
        $this->assertSame(1, $bundle['counts']['persons']);
        $this->assertSame('NODE-A', $bundle['ai_nodes'][0]['node_code']);
        $this->assertStringNotContainsString('Unique-Name-XYZ', $json);
        $this->assertArrayNotHasKey('licensee_name', $bundle['license']['license'] ?? []);
    }

    public function test_log_lines_are_redacted(): void
    {
        $line = '[2026-10-14 10:00:00] local.ERROR: login failed for somchai@school.ac.th from 10.1.2.3 '
            .'person 0b8f2c1e-5a3d-4c7e-9f10-2b3c4d5e6f70 student 6512345 password=Hunter2 Bearer abc.def '
            .'rtsp://admin:pw@192.168.1.10/stream embedding [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0]';

        $redacted = SupportDiagnosticsCommand::redact($line);

        foreach (['somchai@school.ac.th', '10.1.2.3', '0b8f2c1e-5a3d', '6512345', 'Hunter2', 'abc.def', 'admin:pw', '0.5, 0.6'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }
        $this->assertStringContainsString('login failed', $redacted);
    }
}
