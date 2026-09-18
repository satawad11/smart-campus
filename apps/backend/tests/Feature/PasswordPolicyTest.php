<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\PasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected PasswordService $passwordService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->passwordService = app(PasswordService::class);
    }

    public function test_password_shorter_than_configured_minimum_is_rejected(): void
    {
        Config::set('auth_security.password_min_length', 12);

        // 11 characters (below min 12)
        $short = 'Abcdef1234!';
        $this->assertFalse($this->passwordService->isValidPassword($short));

        $this->expectException(ValidationException::class);
        $this->passwordService->validatePassword($short);
    }

    public function test_password_exactly_at_minimum_is_accepted(): void
    {
        Config::set('auth_security.password_min_length', 12);

        // Exactly 12 characters
        $exact = '123456789012';
        $this->assertTrue($this->passwordService->isValidPassword($exact));
    }

    public function test_password_exceeding_configured_maximum_is_rejected(): void
    {
        Config::set('auth_security.password_max_length', 128);

        // 129 characters (above max 128)
        $tooLong = str_repeat('a', 129);
        $this->assertFalse($this->passwordService->isValidPassword($tooLong));

        $this->expectException(ValidationException::class);
        $this->passwordService->validatePassword($tooLong);
    }

    public function test_policy_reads_minimum_and_maximum_from_configuration(): void
    {
        // Custom override min=16, max=32
        Config::set('auth_security.password_min_length', 16);
        Config::set('auth_security.password_max_length', 32);

        $len15 = str_repeat('a', 15);
        $len16 = str_repeat('a', 16);
        $len32 = str_repeat('a', 32);
        $len33 = str_repeat('a', 33);

        $this->assertFalse($this->passwordService->isValidPassword($len15));
        $this->assertTrue($this->passwordService->isValidPassword($len16));
        $this->assertTrue($this->passwordService->isValidPassword($len32));
        $this->assertFalse($this->passwordService->isValidPassword($len33));
    }

    public function test_policy_does_not_silently_truncate_passwords(): void
    {
        // Max 20 for this test
        Config::set('auth_security.password_min_length', 8);
        Config::set('auth_security.password_max_length', 20);

        $password = '123456789012345678901'; // 21 chars

        // Must reject rather than silently truncating to 20
        $this->assertFalse($this->passwordService->isValidPassword($password));
    }

    public function test_accepted_password_is_stored_using_laravel_secure_hashing(): void
    {
        $user = User::factory()->create([
            'password' => 'old_secret_pass_123',
        ]);

        $newPassword = 'new_secure_passphrase_456';
        $this->passwordService->updatePassword($user, $newPassword);

        $user->refresh();

        // Stored password must be hashed, not plaintext
        $this->assertNotSame($newPassword, $user->password);
        $this->assertTrue(Hash::check($newPassword, $user->password));
    }

    public function test_raw_password_never_appears_in_serialized_user_output(): void
    {
        $user = User::factory()->create([
            'password' => 'super_secret_password_123',
        ]);

        $array = $user->toArray();
        $json = $user->toJson();
        $decoded = json_decode($json, true);

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('password', $decoded);
        $this->assertStringNotContainsString('super_secret_password_123', $json);
    }

    public function test_password_mutation_service_changes_password_correctly(): void
    {
        $user = User::factory()->create([
            'password' => 'original_password_123',
        ]);

        $updated = $this->passwordService->updatePassword($user, 'brand_new_password_456');

        $this->assertSame($user->id, $updated->id);
        $this->assertTrue(Hash::check('brand_new_password_456', $updated->password));
    }

    public function test_old_password_no_longer_verifies_after_mutation(): void
    {
        $user = User::factory()->create([
            'password' => 'previous_password_123',
        ]);

        $this->assertTrue(Hash::check('previous_password_123', $user->password));

        $this->passwordService->updatePassword($user, 'subsequent_password_456');
        $user->refresh();

        $this->assertFalse(Hash::check('previous_password_123', $user->password));
        $this->assertTrue(Hash::check('subsequent_password_456', $user->password));
    }

    public function test_password_changed_at_is_updated_on_mutation(): void
    {
        $user = User::factory()->create([
            'password' => 'initial_password_123',
            'password_changed_at' => null,
        ]);

        $this->assertNull($user->password_changed_at);

        $this->passwordService->updatePassword($user, 'updated_password_456');
        $user->refresh();

        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue($user->password_changed_at->isToday());
    }

    public function test_normal_password_change_clears_must_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'temp_password_123',
            'must_change_password' => true,
        ]);

        $this->assertTrue($user->must_change_password);

        // Normal password change sets must_change_password = false
        $this->passwordService->updatePassword($user, 'permanent_password_456', false);
        $user->refresh();

        $this->assertFalse($user->must_change_password);
    }

    public function test_administrative_temporary_password_mode_can_set_or_preserve_must_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old_pass_12345678',
            'must_change_password' => false,
        ]);

        // Admin issues temporary reset password requiring user change on login
        $this->passwordService->updatePassword($user, 'temp_assigned_pass_123', true);
        $user->refresh();

        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('temp_assigned_pass_123', $user->password));
    }

    public function test_invalid_password_causes_no_partial_user_mutation(): void
    {
        $originalChangedAt = now()->subDays(5)->startOfSecond();
        $user = User::factory()->create([
            'password' => 'valid_original_password_123',
            'password_changed_at' => $originalChangedAt,
            'must_change_password' => true,
        ]);

        $invalidShortPassword = 'short';

        try {
            $this->passwordService->updatePassword($user, $invalidShortPassword);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            // Expected
        }

        $user->refresh();

        // User attributes must remain unmodified
        $this->assertTrue(Hash::check('valid_original_password_123', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertEquals($originalChangedAt->timestamp, $user->password_changed_at->timestamp);
    }
}
