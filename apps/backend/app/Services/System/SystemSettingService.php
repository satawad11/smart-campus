<?php

namespace App\Services\System;

use App\Enums\AuditAction;
use App\Models\SystemSetting;
use App\Models\SystemSettingHistory;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Service for managing central typed system settings and change histories (SEC-001).
 */
class SystemSettingService
{
    private const CACHE_PREFIX = 'system_setting:';

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Get a setting's typed value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX.$key;

        return Cache::remember($cacheKey, 3600, function () use ($key, $default) {
            $setting = SystemSetting::where('key', $key)->first();
            if (! $setting) {
                return $default;
            }

            return $setting->getTypedValue();
        });
    }

    /**
     * Get all settings grouped by group name.
     *
     * @return array<string, list<array{key: string, type: string, value: mixed, description: ?string, is_public: bool, updated_at: ?string}>>
     */
    public function getAllGrouped(): array
    {
        $settings = SystemSetting::orderBy('group')->orderBy('key')->get();

        $grouped = [];
        foreach ($settings as $setting) {
            $grouped[$setting->group][] = [
                'key' => $setting->key,
                'type' => $setting->type,
                'value' => $setting->getTypedValue(),
                'description' => $setting->description,
                'is_public' => $setting->is_public,
                'updated_at' => $setting->updated_at?->toIso8601String(),
            ];
        }

        return $grouped;
    }

    /**
     * Get settings for a specific group.
     *
     * @return list<array{key: string, type: string, value: mixed, description: ?string, is_public: bool, updated_at: ?string}>
     */
    public function getByGroup(string $group): array
    {
        $settings = SystemSetting::where('group', strtoupper($group))->orderBy('key')->get();

        return $settings->map(fn (SystemSetting $s) => [
            'key' => $s->key,
            'type' => $s->type,
            'value' => $s->getTypedValue(),
            'description' => $s->description,
            'is_public' => $s->is_public,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ])->all();
    }

    /**
     * Get all public settings (accessible without authentication).
     *
     * @return array<string, mixed>
     */
    public function getPublicSettings(): array
    {
        return Cache::remember('system_settings:public', 3600, function () {
            return SystemSetting::where('is_public', true)
                ->get()
                ->mapWithKeys(fn (SystemSetting $s) => [$s->key => $s->getTypedValue()])
                ->all();
        });
    }

    /**
     * Update settings in a group.
     *
     * @param  array<string, mixed>  $keyValues
     * @return list<array{key: string, type: string, value: mixed, description: ?string, is_public: bool, updated_at: ?string}>
     */
    public function updateGroup(string $group, array $keyValues, ?User $user = null, ?AuditRequestContext $context = null): array
    {
        $groupUpper = strtoupper($group);
        $settings = SystemSetting::where('group', $groupUpper)->get()->keyBy('key');

        $updatedKeys = [];
        $auditOld = [];
        $auditNew = [];

        foreach ($keyValues as $key => $rawNewValue) {
            $setting = $settings->get($key);
            if (! $setting) {
                continue;
            }

            $validatedValue = $this->validateAndNormalize($setting->type, $rawNewValue, $key);
            $oldSerialized = $setting->value;

            $setting->setTypedValue($validatedValue);
            $newSerialized = $setting->value;

            if ($oldSerialized !== $newSerialized) {
                $setting->save();

                // Record history
                SystemSettingHistory::create([
                    'system_setting_id' => $setting->id,
                    'key' => $setting->key,
                    'old_value' => $oldSerialized,
                    'new_value' => $newSerialized,
                    'user_id' => $user?->id,
                ]);

                // Clear cache
                Cache::forget(self::CACHE_PREFIX.$setting->key);

                $updatedKeys[] = $setting->key;
                $auditOld[$setting->key] = $oldSerialized;
                $auditNew[$setting->key] = $newSerialized;
            }
        }

        Cache::forget('system_settings:public');
        Cache::forget('feature_flags:all');

        if ($updatedKeys !== []) {
            $this->auditService->record(
                AuditAction::SETTING_UPDATED,
                $user,
                'SYSTEM_SETTINGS',
                $groupUpper,
                $auditOld,
                $auditNew,
                ['updated_keys' => $updatedKeys],
                $context ?? AuditRequestContext::empty(),
                'system'
            );
        }

        return $this->getByGroup($groupUpper);
    }

    /**
     * Validate and normalize a value against the expected type.
     */
    private function validateAndNormalize(string $type, mixed $value, string $key): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'integer':
                if (! is_numeric($value) || (int) $value != $value) {
                    throw ValidationException::withMessages([$key => "The setting '{$key}' must be an integer."]);
                }

                return (int) $value;

            case 'float':
                if (! is_numeric($value)) {
                    throw ValidationException::withMessages([$key => "The setting '{$key}' must be a numeric float."]);
                }

                return (float) $value;

            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);

            case 'json':
                if (is_array($value)) {
                    return $value;
                }
                json_decode((string) $value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw ValidationException::withMessages([$key => "The setting '{$key}' must be valid JSON."]);
                }

                return json_decode((string) $value, true);

            default:
                return (string) $value;
        }
    }
}
