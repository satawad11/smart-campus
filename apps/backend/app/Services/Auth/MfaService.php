<?php

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use DomainException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * MFA TOTP Service (AUTH-007, AUTH-014).
 *
 * Responsibilities:
 * - Generate standard RFC 6238 TOTP secrets and QR provisioning URIs.
 * - Manage pending enrollment state with expiration.
 * - Encrypt confirmed TOTP secrets at rest in mfa_secret_encrypted.
 * - Verify TOTP login challenges against encrypted stored secrets.
 * - Manage MFA disable flow requiring password and TOTP validation.
 * - Audit log MFA enablement and disabling.
 */
class MfaService
{
    protected ?Google2FA $google2fa;

    protected AuditService $auditService;

    public function __construct(?Google2FA $google2fa = null, ?AuditService $auditService = null)
    {
        $this->google2fa = $google2fa ?? new Google2FA;
        $this->auditService = $auditService ?? app(AuditService::class);
    }

    /**
     * Start MFA TOTP enrollment for an authenticated user.
     *
     * @return array{secret: string, qr_code_url: string, issuer: string, account_name: string, expires_in_seconds: int}
     *
     * @throws DomainException If MFA is already enabled on the account
     */
    public function startSetup(User $user, Session|SessionManager $session): array
    {
        if ($user->mfa_enabled) {
            throw new DomainException('MFA is already enabled on this account.');
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $issuer = (string) config('auth_security.mfa_issuer', 'SmartCampus');
        $accountName = (string) ($user->email ?: $user->username);
        $qrCodeUrl = $this->google2fa->getQRCodeUrl($issuer, $accountName, $secret);

        $setupMinutes = (int) config('auth_security.mfa_setup_minutes', 10);

        // Store pending enrollment state in session encrypted at rest
        $session->put('auth.pending_mfa_setup', [
            'user_uuid' => $user->uuid,
            'secret_encrypted' => Crypt::encryptString($secret),
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addMinutes($setupMinutes)->timestamp,
        ]);

        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'issuer' => $issuer,
            'account_name' => $accountName,
            'expires_in_seconds' => $setupMinutes * 60,
        ];
    }

    /**
     * Confirm MFA TOTP setup by verifying the first code.
     *
     * On success: encrypts and saves the secret, sets mfa_enabled=true, and clears pending setup.
     */
    public function confirmSetup(User $user, Session|SessionManager $session, string $code, ?AuditRequestContext $context = null): bool
    {
        $pending = $session->get('auth.pending_mfa_setup');

        if (! is_array($pending) || empty($pending['secret_encrypted']) || empty($pending['expires_at'])) {
            return false;
        }

        if (($pending['user_uuid'] ?? null) !== $user->uuid) {
            return false;
        }

        if (now()->timestamp > (int) $pending['expires_at']) {
            $session->forget('auth.pending_mfa_setup');

            return false;
        }

        $secret = Crypt::decryptString($pending['secret_encrypted']);
        $window = (int) config('auth_security.mfa_window', 1);

        $valid = (bool) $this->google2fa->verifyKey($secret, $code, $window);

        if (! $valid) {
            return false;
        }

        DB::transaction(function () use ($user, $secret, $context) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $fresh->mfa_secret_encrypted = Crypt::encryptString($secret);
            $fresh->mfa_enabled = true;
            $fresh->save();

            // Sync in-memory model
            $user->mfa_secret_encrypted = $fresh->mfa_secret_encrypted;
            $user->mfa_enabled = $fresh->mfa_enabled;

            $this->auditService->record(
                AuditAction::MFA_ENABLED,
                $fresh,
                'USER',
                $fresh->uuid,
                newValues: ['mfa_enabled' => true],
                context: $context
            );
        });

        $session->forget('auth.pending_mfa_setup');

        return true;
    }

    /**
     * Verify a login MFA challenge code against the user's stored encrypted secret.
     *
     * Consumes the challenge upon success.
     */
    public function verifyLoginChallenge(Session|SessionManager $session, string $code): ?User
    {
        $pending = $session->get('auth.pending_mfa');

        if (! is_array($pending) || empty($pending['user_uuid']) || empty($pending['expires_at'])) {
            return null;
        }

        if (now()->timestamp > (int) $pending['expires_at']) {
            $session->forget('auth.pending_mfa');

            return null;
        }

        $user = User::where('uuid', $pending['user_uuid'])->first();

        if (! $user || ! $user->mfa_enabled || empty($user->mfa_secret_encrypted)) {
            return null;
        }

        $secret = Crypt::decryptString($user->mfa_secret_encrypted);
        $window = (int) config('auth_security.mfa_window', 1);

        $valid = (bool) $this->google2fa->verifyKey($secret, $code, $window);

        if (! $valid) {
            return null;
        }

        // Consume pending challenge immediately upon verification
        $session->forget('auth.pending_mfa');

        return $user;
    }

    /**
     * Disable MFA for an authenticated user requiring current password and valid TOTP code.
     */
    public function disableMfa(User $user, Session|SessionManager $session, string $password, string $code, ?AuditRequestContext $context = null): bool
    {
        if (! $user->mfa_enabled || empty($user->mfa_secret_encrypted)) {
            return false;
        }

        if (! Hash::check($password, $user->password)) {
            return false;
        }

        $secret = Crypt::decryptString($user->mfa_secret_encrypted);
        $window = (int) config('auth_security.mfa_window', 1);

        $valid = (bool) $this->google2fa->verifyKey($secret, $code, $window);

        if (! $valid) {
            return false;
        }

        DB::transaction(function () use ($user, $context) {
            $fresh = User::where('id', $user->id)->lockForUpdate()->firstOrFail();

            $fresh->mfa_enabled = false;
            $fresh->mfa_secret_encrypted = null;
            $fresh->save();

            // Sync in-memory model
            $user->mfa_enabled = false;
            $user->mfa_secret_encrypted = null;

            $this->auditService->record(
                AuditAction::MFA_DISABLED,
                $fresh,
                'USER',
                $fresh->uuid,
                newValues: ['mfa_enabled' => false],
                context: $context
            );
        });

        $session->forget(['auth.pending_mfa', 'auth.pending_mfa_setup']);

        return true;
    }

    /**
     * Clear pending MFA setup state.
     */
    public function clearPendingSetup(Session|SessionManager $session): void
    {
        $session->forget('auth.pending_mfa_setup');
    }
}
