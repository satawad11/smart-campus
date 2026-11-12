<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\RoleStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Integration\Rms\RmsClient;
use App\Services\Integration\Rms\RmsConnectionSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * External API connection settings from the web app (ACR-INT-02): the RMS
 * key is write-only, encrypted at rest, never returned or audited, and the
 * saved values win over .env.
 */
class IntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const ENV_KEY = 'rmsk_envkey01_00000000000000000000000000000000';

    private const NEW_KEY = 'rmsk_9bcdb909_secretsecretsecretsecretsecret1234';

    /** @var list<array{url: string, auth: string}> */
    private array $calls = [];

    private ?string $rmsError = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(PermissionSeeder::class);
        config([
            'rms.url' => 'https://rms.example.test/api_center.php',
            'rms.key' => self::ENV_KEY,
            'rms.sync.enabled' => false,
            'rms.sync.cron' => '30 5 * * *',
        ]);

        Http::fake(function (Request $request) {
            $this->calls[] = ['url' => $request->url(), 'auth' => $request->header('Authorization')[0] ?? ''];
            if ($this->rmsError !== null) {
                return Http::response(['ok' => false, 'error' => $this->rmsError, 'error_msg' => 'x'], 403);
            }

            return Http::response(['ok' => true, 'school' => ['id' => 1, 'name' => 'วิทยาลัยทดสอบ'], 'term' => ['id' => '2/2569']]);
        });
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'base_url' => 'https://rms.example.test/api_center.php',
            'timeout_seconds' => 20,
            'sync_enabled' => false,
            'sync_time' => '06:15',
        ], $overrides);
    }

    public function test_env_values_are_shown_when_nothing_is_saved(): void
    {
        $this->actingAs($this->userWith(['system.view']), 'web')
            ->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.code', 'RMS')
            ->assertJsonPath('data.0.base_url', 'https://rms.example.test/api_center.php')
            ->assertJsonPath('data.0.api_key_set', true)
            ->assertJsonPath('data.0.api_key_source', 'env')
            ->assertJsonPath('data.0.api_key_hint', 'rmsk_envkey01_…')
            ->assertJsonPath('data.0.sync_enabled', false)
            ->assertJsonPath('data.0.sync_time', '05:30')
            ->assertJsonMissing(['api_key' => self::ENV_KEY]);
    }

    public function test_saving_a_key_encrypts_it_and_never_returns_or_audits_it(): void
    {
        $admin = $this->userWith(['system.view', 'integration.manage']);

        $response = $this->actingAs($admin, 'web')
            ->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY, 'sync_enabled' => true]))
            ->assertOk()
            ->assertJsonPath('data.api_key_source', 'database')
            ->assertJsonPath('data.api_key_hint', 'rmsk_9bcdb909_…')
            ->assertJsonPath('data.sync_enabled', true)
            ->assertJsonPath('data.sync_time', '06:15')
            ->assertJsonPath('data.sync_cron', '15 6 * * *')
            ->assertJsonPath('data.timeout_seconds', 20)
            ->assertJsonPath('data.updated_by', $admin->username);

        $this->assertStringNotContainsString('secretsecret', $response->getContent());

        $row = IntegrationConnection::where('code', 'RMS')->firstOrFail();
        $this->assertNotSame(self::NEW_KEY, $row->api_key_encrypted);
        $this->assertStringNotContainsString('secretsecret', (string) $row->api_key_encrypted);
        $this->assertSame(self::NEW_KEY, $row->apiKey());
        $this->assertArrayNotHasKey('api_key_encrypted', $row->toArray());

        $audit = AuditLog::where('action', AuditAction::INTEGRATION_UPDATED->value)->firstOrFail();
        $this->assertStringNotContainsString('secretsecret', json_encode($audit->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertTrue($audit->metadata['api_key_changed']);

        // The saved values are what the client and the scheduler use.
        $settings = RmsConnectionSettings::current();
        $this->assertSame(self::NEW_KEY, $settings->key);
        $this->assertTrue($settings->syncEnabled);
        $this->assertSame('15 6 * * *', $settings->syncCron);
        $this->assertSame(20, $settings->timeoutSeconds);
    }

    public function test_leaving_the_key_empty_keeps_the_stored_key(): void
    {
        $admin = $this->userWith(['integration.manage']);
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY]))->assertOk();
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => '', 'timeout_seconds' => 45]))->assertOk();

        $settings = RmsConnectionSettings::current();
        $this->assertSame(self::NEW_KEY, $settings->key);
        $this->assertSame(45, $settings->timeoutSeconds);
    }

    public function test_clearing_the_key_falls_back_to_env(): void
    {
        $admin = $this->userWith(['integration.manage']);
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY]))->assertOk();

        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['clear_api_key' => true]))
            ->assertOk()
            ->assertJsonPath('data.api_key_source', 'env');

        $this->assertNull(IntegrationConnection::where('code', 'RMS')->value('api_key_encrypted'));
        $this->assertSame(self::ENV_KEY, RmsConnectionSettings::current()->key);
    }

    public function test_changing_the_url_requires_the_key_again(): void
    {
        $admin = $this->userWith(['integration.manage']);
        $other = 'https://attacker.example.test/collect';

        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['base_url' => $other]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame(0, IntegrationConnection::count());

        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['base_url' => $other, 'api_key' => self::NEW_KEY]))
            ->assertOk()
            ->assertJsonPath('data.base_url', $other);

        // Clearing the stored key must not let the .env key follow a new address either.
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['base_url' => 'https://elsewhere.example.test/x', 'clear_api_key' => true]))
            ->assertStatus(422);
        $this->assertSame($other, RmsConnectionSettings::current()->url);
    }

    public function test_validation(): void
    {
        $admin = $this->userWith(['integration.manage']);

        foreach ([
            ['base_url' => 'http://rms.example.test/api_center.php'],
            ['base_url' => 'not a url'],
            ['timeout_seconds' => 1],
            ['timeout_seconds' => 500],
            ['sync_time' => '25:00'],
            ['sync_time' => '5:30'],
            ['api_key' => 'short'],
            ['api_key' => 'rmsk_has space_000000000000000000'],
        ] as $bad) {
            $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload($bad))
                ->assertStatus(422);
        }

        config(['rms.key' => null]);
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['sync_enabled' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('sync_enabled', 'error.fields');
        $this->assertSame(0, IntegrationConnection::count());
    }

    public function test_permissions(): void
    {
        $this->getJson('/api/v1/integrations')->assertUnauthorized();

        $nobody = $this->userWith(['student.view']);
        $this->actingAs($nobody, 'web')->getJson('/api/v1/integrations')->assertForbidden();

        $viewer = $this->userWith(['system.view', 'system.manage', 'student.sync']);
        $this->actingAs($viewer, 'web')->getJson('/api/v1/integrations')->assertOk();
        $this->actingAs($viewer, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY]))->assertForbidden();
        $this->actingAs($viewer, 'web')->postJson('/api/v1/integrations/rms/test')->assertForbidden();

        $this->assertSame(0, IntegrationConnection::count());
        $this->assertSame([], $this->calls);
    }

    public function test_connection_test_uses_saved_key_and_records_the_result(): void
    {
        $admin = $this->userWith(['integration.manage']);
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY]))->assertOk();

        $this->actingAs($admin, 'web')->postJson('/api/v1/integrations/rms/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.school', 'วิทยาลัยทดสอบ')
            ->assertJsonPath('data.term', '2/2569')
            ->assertJsonPath('meta.connection.last_test.ok', true);

        $this->assertCount(1, $this->calls);
        $this->assertSame('Bearer '.self::NEW_KEY, $this->calls[0]['auth']);
        $this->assertStringNotContainsString('secretsecret', $this->calls[0]['url']);
        $this->assertStringContainsString('res=school', $this->calls[0]['url']);
        $this->assertSame(1, AuditLog::where('action', AuditAction::INTEGRATION_TESTED->value)->count());
    }

    public function test_connection_test_failure_is_explained_and_recorded(): void
    {
        $this->rmsError = 'ip';
        $admin = $this->userWith(['integration.manage']);

        $this->actingAs($admin, 'web')->postJson('/api/v1/integrations/rms/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', 'ip');

        $row = IntegrationConnection::where('code', 'RMS')->firstOrFail();
        $this->assertFalse($row->last_test_ok);
        $this->assertStringContainsString('IP', $row->last_test_message);
        // A test result alone does not override .env.
        $this->assertNull($row->base_url);
        $this->assertSame(RmsConnectionSettings::SOURCE_ENV, RmsConnectionSettings::current()->keySource);
    }

    public function test_connection_test_without_any_key_does_not_call_rms(): void
    {
        config(['rms.key' => null]);

        $this->actingAs($this->userWith(['integration.manage']), 'web')->postJson('/api/v1/integrations/rms/test')
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', 'no_key');
        $this->assertSame([], $this->calls);
    }

    public function test_rms_client_and_sync_meta_follow_saved_settings(): void
    {
        $admin = $this->userWith(['integration.manage', 'student.sync']);
        $this->actingAs($admin, 'web')->putJson('/api/v1/integrations/rms', $this->payload(['api_key' => self::NEW_KEY, 'sync_enabled' => true]))->assertOk();

        RmsClient::fromConfig()->get('school');
        $this->assertSame('Bearer '.self::NEW_KEY, $this->calls[0]['auth']);

        $this->actingAs($admin, 'web')->getJson('/api/v1/integrations/rms/sync-runs')
            ->assertOk()
            ->assertJsonPath('meta.configured', true)
            ->assertJsonPath('meta.schedule_enabled', true)
            ->assertJsonPath('meta.schedule_cron', '15 6 * * *');
    }

    public function test_unreadable_key_after_app_key_change_is_reported(): void
    {
        IntegrationConnection::create(['code' => 'RMS'])->forceFill(['api_key_encrypted' => 'not-a-valid-payload', 'api_key_hint' => 'rmsk_x_…'])->save();
        config(['rms.key' => null]);

        $this->actingAs($this->userWith(['system.view']), 'web')->getJson('/api/v1/integrations')
            ->assertOk()
            ->assertJsonPath('data.0.api_key_set', false)
            ->assertJsonPath('data.0.api_key_unreadable', true);
    }

    public function test_cron_time_conversion(): void
    {
        $this->assertSame('30 5 * * *', RmsConnectionSettings::cronForTime('05:30'));
        $this->assertSame('05:30', RmsConnectionSettings::timeForCron('30 5 * * *'));
        $this->assertNull(RmsConnectionSettings::timeForCron('*/5 * * * *'));
    }

    /** @param  list<string>  $codes */
    private function userWith(array $codes): User
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $role = Role::create(['code' => 'INT_'.uniqid(), 'name' => 'Integration test', 'system_role' => false, 'status' => RoleStatus::ACTIVE]);
        $role->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user;
    }
}
