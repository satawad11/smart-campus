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

        // Environment kill switch (SEC-024): an explicit false ceiling wins over settings
        if ($this->isKillSwitchEngaged($normalized)) {
            return false;
        }

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
        $flags = Cache::remember('feature_flags:all', 3600, function () {
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

        // Apply environment kill switches after the cache so config changes take effect
        foreach (array_keys($flags) as $name) {
            if ($this->isKillSwitchEngaged($name)) {
                $flags[$name] = false;
            }
        }

        return $flags;
    }

    /**
     * Whether a locked V1 feature is hard-disabled by its FEATURE_*_ENABLED variable.
     * Only an explicitly false value engages the switch; an unset variable is null.
     */
    public function isKillSwitchEngaged(string $feature): bool
    {
        if (! in_array($feature, self::LOCKED_V1_FEATURES, true)) {
            return false;
        }

        $value = config('features.locked_kill_switches.'.$feature);
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true;
    }
}
