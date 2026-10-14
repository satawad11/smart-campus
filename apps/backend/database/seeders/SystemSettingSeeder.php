<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Services\System\FeatureFlagService;
use Illuminate\Database\Seeder;

/**
 * Seeds default canonical system settings and feature flags (SEC-001, SEC-002).
 * Idempotent: uses updateOrCreate on setting key.
 */
class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = $this->getDefaultSettings();

        foreach ($settings as $setting) {
            $existing = SystemSetting::where('key', $setting['key'])->first();

            // Re-seeding (every deployment/upgrade) must not overwrite values an
            // administrator changed in System Settings. Only V1-locked feature
            // flags are forced back to their seeded (disabled) value.
            $forceValue = $existing === null || $this->isLockedFeature($setting['key']);

            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                array_merge([
                    'group' => $setting['group'],
                    'type' => $setting['type'],
                    'description' => $setting['description'] ?? null,
                    'is_public' => $setting['is_public'] ?? false,
                ], $forceValue ? ['value' => $setting['value']] : [])
            );
        }
    }

    private function isLockedFeature(string $key): bool
    {
        return str_starts_with($key, 'feature.')
            && in_array(substr($key, 8), FeatureFlagService::LOCKED_V1_FEATURES, true);
    }

    /**
     * @return list<array{key: string, group: string, type: string, value: string|null, description?: string, is_public?: bool}>
     */
    public function getDefaultSettings(): array
    {
        return [
            // GENERAL
            [
                'key' => 'general.site_name',
                'group' => 'GENERAL',
                'type' => 'string',
                'value' => 'AI Smart Campus Security Platform',
                'description' => 'System display name for campus installation',
                'is_public' => true,
            ],
            [
                'key' => 'general.timezone',
                'group' => 'GENERAL',
                'type' => 'string',
                'value' => 'Asia/Bangkok',
                'description' => 'Default system operational timezone',
                'is_public' => true,
            ],
            [
                'key' => 'general.locale',
                'group' => 'GENERAL',
                'type' => 'string',
                'value' => 'th',
                'description' => 'Default user interface and notification locale',
                'is_public' => true,
            ],

            // SECURITY
            [
                'key' => 'security.admin_mfa_enforced',
                'group' => 'SECURITY',
                'type' => 'boolean',
                // Off by default (owner decision 2026-10-14); an administrator
                // turns it on in System Settings -> Security.
                'value' => 'false',
                'description' => 'Require MFA enrollment for administrator roles (AUTH_MFA_REQUIRED_ROLES) before they can use the system',
                'is_public' => false,
            ],
            [
                'key' => 'security.session_timeout_minutes',
                'group' => 'SECURITY',
                'type' => 'integer',
                'value' => '120',
                'description' => 'Idle session expiration timeout in minutes',
                'is_public' => false,
            ],
            [
                'key' => 'security.max_login_attempts',
                'group' => 'SECURITY',
                'type' => 'integer',
                'value' => '5',
                'description' => 'Consecutive failed login threshold before account lockout',
                'is_public' => false,
            ],
            [
                'key' => 'security.lockout_duration_minutes',
                'group' => 'SECURITY',
                'type' => 'integer',
                'value' => '15',
                'description' => 'Account lockout duration in minutes',
                'is_public' => false,
            ],
            [
                'key' => 'security.password_min_length',
                'group' => 'SECURITY',
                'type' => 'integer',
                'value' => '8',
                'description' => 'Minimum required password character length',
                'is_public' => false,
            ],

            // FACE
            [
                'key' => 'face.match_threshold',
                'group' => 'FACE',
                'type' => 'float',
                'value' => '0.80',
                'description' => 'Approved MATCH cosine similarity threshold (FACE-021)',
                'is_public' => false,
            ],
            [
                'key' => 'face.review_threshold',
                'group' => 'FACE',
                'type' => 'float',
                'value' => '0.60',
                'description' => 'Approved REVIEW cosine similarity threshold (FACE-021)',
                'is_public' => false,
            ],
            [
                'key' => 'face.duplicate_review_threshold',
                'group' => 'FACE',
                'type' => 'float',
                'value' => '0.65',
                'description' => 'Approved duplicate face enrollment review threshold (FACE-021)',
                'is_public' => false,
            ],
            [
                'key' => 'face.pad_real_threshold',
                'group' => 'FACE',
                'type' => 'float',
                'value' => '0.70',
                'description' => 'Approved Presentation Attack Detection REAL score threshold',
                'is_public' => false,
            ],
            [
                'key' => 'face.pad_spoof_threshold',
                'group' => 'FACE',
                'type' => 'float',
                'value' => '0.40',
                'description' => 'Approved Presentation Attack Detection SPOOF score threshold',
                'is_public' => false,
            ],
            [
                'key' => 'face.liveness_enforced',
                'group' => 'FACE',
                'type' => 'boolean',
                'value' => 'true',
                'description' => 'Enforce presentation attack / liveness verification during recognition',
                'is_public' => false,
            ],

            // EVENT
            [
                'key' => 'event.semantic_cooldown_seconds',
                'group' => 'EVENT',
                'type' => 'integer',
                'value' => '30',
                'description' => 'Semantic deduplication cooldown window per camera/person in seconds',
                'is_public' => false,
            ],
            [
                'key' => 'event.auto_review_unknown',
                'group' => 'EVENT',
                'type' => 'boolean',
                'value' => 'true',
                'description' => 'Automatically place unknown face detections into review queue',
                'is_public' => false,
            ],

            // CAMERA
            [
                'key' => 'camera.health_check_interval_seconds',
                'group' => 'CAMERA',
                'type' => 'integer',
                'value' => '60',
                'description' => 'Heartbeat interval for camera stream health collection',
                'is_public' => false,
            ],
            [
                'key' => 'camera.offline_threshold_seconds',
                'group' => 'CAMERA',
                'type' => 'integer',
                'value' => '180',
                'description' => 'Seconds without heartbeat before camera is marked OFFLINE',
                'is_public' => false,
            ],

            // STORAGE
            [
                'key' => 'storage.provider',
                'group' => 'STORAGE',
                'type' => 'string',
                'value' => 'local',
                'description' => 'Storage disk driver (local, s3)',
                'is_public' => false,
            ],
            [
                'key' => 'storage.max_upload_size_mb',
                'group' => 'STORAGE',
                'type' => 'integer',
                'value' => '10',
                'description' => 'Maximum allowed file upload size in megabytes',
                'is_public' => false,
            ],

            // REPORT
            [
                'key' => 'report.export_expiration_hours',
                'group' => 'REPORT',
                'type' => 'integer',
                'value' => '24',
                'description' => 'Number of hours before queued report export files expire and are purged',
                'is_public' => false,
            ],

            // NOTIFICATION
            [
                'key' => 'notification.email_enabled',
                'group' => 'NOTIFICATION',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Enable outbound email dispatch for critical alerts',
                'is_public' => false,
            ],
            [
                'key' => 'notification.webhook_enabled',
                'group' => 'NOTIFICATION',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Enable outbound webhook integration for security events',
                'is_public' => false,
            ],

            // RETENTION
            [
                'key' => 'retention.events_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '90',
                'description' => 'Data retention period for raw access events in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.snapshots_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '30',
                'description' => 'Data retention period for captured camera event snapshots in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.camera_health_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '14',
                'description' => 'Data retention period for camera health telemetry in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.ai_node_health_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '14',
                'description' => 'Data retention period for AI edge node telemetry in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.report_exports_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '7',
                'description' => 'Data retention period for exported report files in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.login_logs_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '365',
                'description' => 'Data retention period for user login audit logs in days',
                'is_public' => false,
            ],
            [
                'key' => 'retention.audit_logs_days',
                'group' => 'RETENTION',
                'type' => 'integer',
                'value' => '730',
                'description' => 'Data retention period for system security audit logs in days',
                'is_public' => false,
            ],

            // FEATURE (SEC-002: Production V1 strictly locks future features to false)
            [
                'key' => 'feature.door',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Door access control actuator integration (LOCKED in Production V1)',
                'is_public' => true,
            ],
            [
                'key' => 'feature.visitor',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Visitor and parent registration module (LOCKED in Production V1)',
                'is_public' => true,
            ],
            [
                'key' => 'feature.vehicle',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Vehicle access control integration (LOCKED in Production V1)',
                'is_public' => true,
            ],
            [
                'key' => 'feature.alpr',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'Automated License Plate Recognition (LOCKED in Production V1)',
                'is_public' => true,
            ],
            [
                'key' => 'feature.ai_assistant',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'false',
                'description' => 'AI Assistant & advanced natural language query (LOCKED in Production V1)',
                'is_public' => true,
            ],
            [
                'key' => 'feature.realtime_monitor',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'true',
                'description' => 'Realtime security monitoring live feed',
                'is_public' => true,
            ],
            [
                'key' => 'feature.duplicate_review',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'true',
                'description' => 'Duplicate face enrollment candidate review workflow',
                'is_public' => true,
            ],
            [
                'key' => 'feature.scheduled_reports',
                'group' => 'FEATURE',
                'type' => 'boolean',
                'value' => 'true',
                'description' => 'Automated periodic report generation and email distribution',
                'is_public' => true,
            ],
        ];
    }
}
