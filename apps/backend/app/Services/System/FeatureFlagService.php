<?php

namespace App\Services\System;

use App\Exceptions\FeatureDisabledException;
use Illuminate\Support\Facades\Cache;

/**
 * Authoritative Backend Feature Flags Service (SEC-002).
 *
 * Enforces feature gate checks at the backend level.
 * Features default to FAIL-CLOSED (disabled) unless explicitly enabled in approved system settings.
 * Production V1 strictly locks: door, visitor, ai_assistant.
 * vehicle and alpr were unlocked by ACR-VEH-01 (approved 2026-11-12): they stay disabled by
 * default but an administrator may enable them; their environment kill switches still apply.
 */
class FeatureFlagService
{
    /**
     * Canonical locked features for Production V1.
     */
    public const LOCKED_V1_FEATURES = [
        'door',
        'visitor',
        'ai_assistant',
    ];

    /**
     * Approved optional modules (ACR-VEH-01): off by default, may be enabled by an
     * administrator, and can always be forced off with their FEATURE_*_ENABLED variable.
     */
    public const OPTIONAL_FEATURES = [
        'vehicle',
        'alpr',
    ];

    /**
     * Every feature that has an environment kill switch.
     *
     * @return list<string>
     */
    public static function killSwitchFeatures(): array
    {
        return [...self::LOCKED_V1_FEATURES, ...self::OPTIONAL_FEATURES];
    }

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

            // Ensure locked and optional features are always present
            foreach (self::killSwitchFeatures() as $locked) {
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
     * Whether a locked or optional feature is hard-disabled by its FEATURE_*_ENABLED variable.
     * Only an explicitly true value releases the switch; an unset variable is null (no switch),
     * and any other value, including an unparseable one, engages it (fail closed).
     */
    public function isKillSwitchEngaged(string $feature): bool
    {
        if (! in_array($feature, self::killSwitchFeatures(), true)) {
            return false;
        }

        $value = config('features.locked_kill_switches.'.$feature);
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true;
    }

    /**
     * Whether the FEATURE_*_ENABLED variable of a kill-switch feature is set at all.
     */
    public function isKillSwitchConfigured(string $feature): bool
    {
        return in_array($feature, self::killSwitchFeatures(), true)
            && config('features.locked_kill_switches.'.$feature) !== null;
    }
}
