<?php

namespace App\Services\Auth;

use App\Enums\AuthStatus;
use App\Models\User;

/**
 * Authentication Result DTO (AUTH-004).
 *
 * Encapsulates the structured outcome of credential evaluation, including
 * domain status, authenticating user (if valid), public API error code,
 * and internal diagnostic reason for audit/logging.
 */
class AuthenticationResult
{
    public function __construct(
        public readonly AuthStatus $status,
        public readonly ?User $user = null,
        public readonly string $errorCode = '',
        public readonly ?string $internalReason = null,
    ) {}

    public static function success(User $user): self
    {
        return new self(
            status: AuthStatus::SUCCESS,
            user: $user,
            errorCode: '',
            internalReason: null,
        );
    }

    public static function mfaRequired(User $user): self
    {
        return new self(
            status: AuthStatus::MFA_REQUIRED,
            user: $user,
            errorCode: '',
            internalReason: 'MFA_REQUIRED',
        );
    }

    public static function invalidCredentials(?string $internalReason = null, ?User $user = null): self
    {
        return new self(
            status: AuthStatus::INVALID_CREDENTIALS,
            user: $user,
            errorCode: 'AUTH_INVALID_CREDENTIALS',
            internalReason: $internalReason,
        );
    }

    public static function accountLocked(?User $user = null, ?string $internalReason = null): self
    {
        return new self(
            status: AuthStatus::ACCOUNT_LOCKED,
            user: $user,
            errorCode: 'AUTH_ACCOUNT_LOCKED',
            internalReason: $internalReason,
        );
    }

    public static function accountDisabled(?User $user = null, ?string $internalReason = null): self
    {
        return new self(
            status: AuthStatus::ACCOUNT_DISABLED,
            user: $user,
            errorCode: 'AUTH_ACCOUNT_DISABLED',
            internalReason: $internalReason,
        );
    }

    public static function accountExpired(?User $user = null, ?string $internalReason = null): self
    {
        return new self(
            status: AuthStatus::ACCOUNT_EXPIRED,
            user: $user,
            errorCode: 'AUTH_ACCOUNT_EXPIRED',
            internalReason: $internalReason,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === AuthStatus::SUCCESS;
    }

    public function requiresMfa(): bool
    {
        return $this->status === AuthStatus::MFA_REQUIRED;
    }

    public function failed(): bool
    {
        return ! $this->isSuccessful() && ! $this->requiresMfa();
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getStatus(): AuthStatus
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getInternalReason(): ?string
    {
        return $this->internalReason;
    }
}
