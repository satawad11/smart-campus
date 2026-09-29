<?php

namespace App\Services\System;

use App\Exceptions\FeatureDisabledException;
use Illuminate\Support\Facades\Cache;

/**
 * Authoritative Backend Feature Flags Service (SEC-002).
 *
 * Enforces feature gate checks at the backend level.
 * Features default to FAIL-CLOSED (disabled) unless explicitly enabled in approved system settings.
 * Production V1 strictly locks: door, visitor, vehicle, alpr, ai_assistant.
 */
class FeatureFlagService
{
    /**
     * Canonical locked features for Production V1.
     */
    public const LOCKED_V1_FEATURES = [
        'door',
        'visitor',
        'vehicle',
        'alpr',
        'ai_assistant',
    ];

    public function __construct(
        private readonly SystemSettingService $settingService,
    ) {}

    /**
     * Check if a feature flag is enabled.
     */
    public function isEnabled(string $feature): bool
    {
        $normalized = strtolower(str_replace('-', '_', trim($feature)));

        // Read from cached system settings
        $key = 'feature.'.$normalized;
        $val = $this->settingService->get($key, null);

        if ($val === null) {
            // Fail closed by default
            return false;
        }

        return (bool) $val;
    }

    /**
     * Assert that a feature is enabled, otherwise fail-closed with 403.
     *
     * @throws FeatureDisabledException
     */
    public function assertEnabled(string $feature): void
    {
        if (! $this->isEnabled($feature)) {
            throw new FeatureDisabledException($feature);
        }
    }

    /**
     * Get all active feature flags.
     *
     * @return array<string, bool>
     */
    public function getAll(): array
    {
        return Cache::remember('feature_flags:all', 3600, function () {
            $settings = $this->settingService->getByGroup('FEATURE');
            $flags = [];

            foreach ($settings as $setting) {
                $featureName = str_replace('feature.', '', $setting['key']);
                $flags[$featureName] = (bool) $setting['value'];
            }

            // Ensure locked V1 features are always present
            foreach (self::LOCKED_V1_FEATURES as $locked) {
                if (! isset($flags[$locked])) {
                    $flags[$locked] = false;
                }
            }

            return $flags;
        });
    }
}
