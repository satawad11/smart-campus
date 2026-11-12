<?php

namespace App\Services\Integration;

use App\Enums\AuditAction;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Integration\Rms\RmsApiException;
use App\Services\Integration\Rms\RmsClient;
use App\Services\Integration\Rms\RmsConnectionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * External API connections set from the web app (ACR-INT-02). RMS is the
 * only connector in V1. The API key is write-only: it is stored encrypted,
 * never returned, never written to audit logs (only "key changed" and the
 * non-secret hint).
 */
class IntegrationConnectionService
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return [$this->presentRms()];
    }

    /** @return array<string, mixed> */
    public function presentRms(): array
    {
        $s = RmsConnectionSettings::current();
        $row = $s->row;

        return [
            'code' => IntegrationConnection::RMS,
            'name' => 'RMS API Center',
            'description' => 'ระบบบริหารจัดการสถานศึกษา (RMS): ข้อมูลนักเรียน บุคลากร และกลุ่มเรียน (อ่านอย่างเดียว)',
            'base_url' => $s->url,
            'base_url_source' => $row?->base_url ? RmsConnectionSettings::SOURCE_DATABASE : RmsConnectionSettings::SOURCE_ENV,
            'api_key_set' => $s->configured(),
            'api_key_hint' => $s->keyHint,
            'api_key_source' => $s->keySource,
            'api_key_unreadable' => $s->keyUnreadable,
            'timeout_seconds' => $s->timeoutSeconds,
            'sync_enabled' => $s->syncEnabled,
            'sync_time' => $s->syncTime,
            'sync_cron' => $s->syncCron,
            'last_test' => $row?->last_tested_at ? [
                'ok' => (bool) $row->last_test_ok,
                'message' => $row->last_test_message,
                'tested_at' => $row->last_tested_at->toIso8601String(),
            ] : null,
            'updated_by' => $row?->updatedBy?->username,
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{base_url: string, api_key?: string|null, clear_api_key?: bool, timeout_seconds: int, sync_enabled: bool, sync_time: string}  $data
     * @return array<string, mixed>
     */
    public function updateRms(array $data, User $user, AuditRequestContext $context): array
    {
        $current = RmsConnectionSettings::current();
        $newKey = isset($data['api_key']) && $data['api_key'] !== '' ? (string) $data['api_key'] : null;
        $clearKey = (bool) ($data['clear_api_key'] ?? false);
        $url = rtrim((string) $data['base_url']);

        $keyAfter = $clearKey ? (config('rms.key') ?: null) : ($newKey ?? $current->key);

        // A key must never follow a changed address without being entered
        // again (stored or .env fallback): otherwise anyone who can edit this
        // page could send the key to a host of their choice.
        if ($url !== $current->url && $newKey === null && $keyAfter !== null) {
            throw ValidationException::withMessages([
                'api_key' => 'เมื่อเปลี่ยนที่อยู่ API ต้องกรอกกุญแจ API อีกครั้ง',
            ]);
        }

        if ((bool) $data['sync_enabled'] && $keyAfter === null) {
            throw ValidationException::withMessages([
                'sync_enabled' => 'ต้องตั้งค่ากุญแจ API ก่อนเปิดการซิงก์อัตโนมัติ',
            ]);
        }

        $before = $this->auditView($current);

        DB::transaction(function () use ($data, $user, $url, $newKey, $clearKey) {
            $row = IntegrationConnection::lockForUpdate()->firstOrNew(['code' => IntegrationConnection::RMS]);
            $row->base_url = $url;
            $row->settings = [
                'timeout_seconds' => (int) $data['timeout_seconds'],
                'sync_enabled' => (bool) $data['sync_enabled'],
                'sync_time' => (string) $data['sync_time'],
            ];
            if ($clearKey) {
                $row->setApiKey(null);
            } elseif ($newKey !== null) {
                $row->setApiKey($newKey);
            }
            if ($clearKey || $newKey !== null) {
                // An old test result says nothing about the new key.
                $row->last_tested_at = null;
                $row->last_test_ok = null;
                $row->last_test_message = null;
            }
            $row->updated_by_user_id = $user->id;
            $row->save();
        });

        $after = RmsConnectionSettings::current();

        $this->auditService->record(
            AuditAction::INTEGRATION_UPDATED,
            $user,
            'INTEGRATION_CONNECTION',
            IntegrationConnection::RMS,
            $before,
            $this->auditView($after),
            ['api_key_changed' => $newKey !== null, 'api_key_cleared' => $clearKey],
            $context,
            'integration',
        );

        return $this->presentRms();
    }

    /**
     * Calls RMS (res=school) with the saved connection and records the result.
     *
     * @return array<string, mixed>
     */
    public function testRms(User $user, AuditRequestContext $context): array
    {
        $settings = RmsConnectionSettings::current();
        $client = RmsClient::fromConfig();

        $started = microtime(true);
        $result = ['ok' => false, 'error' => null, 'message' => '', 'school' => null, 'term' => null];
        try {
            $json = $client->get('school');
            $result['ok'] = true;
            $result['school'] = isset($json['school']['name']) ? (string) $json['school']['name'] : null;
            $result['term'] = isset($json['term']['id']) ? (string) $json['term']['id'] : null;
            $result['message'] = trim('เชื่อมต่อสำเร็จ '.($result['school'] ?? '').($result['term'] ? ' ภาคเรียน '.$result['term'] : ''));
        } catch (RmsApiException $e) {
            $result['error'] = $e->error;
            $result['message'] = $this->errorText($e);
        }
        $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        $row = $settings->row ?? new IntegrationConnection(['code' => IntegrationConnection::RMS]);
        $row->last_tested_at = now();
        $row->last_test_ok = $result['ok'];
        $row->last_test_message = mb_substr($result['message'], 0, 500);
        $row->save();

        $this->auditService->record(
            AuditAction::INTEGRATION_TESTED,
            $user,
            'INTEGRATION_CONNECTION',
            IntegrationConnection::RMS,
            null,
            null,
            ['ok' => $result['ok'], 'error' => $result['error'], 'duration_ms' => $result['duration_ms'], 'key_source' => $settings->keySource],
            $context,
            'integration',
        );

        return $result;
    }

    /** Thai explanation of an RMS error code (vendor document "RMS API Center v1"). */
    private function errorText(RmsApiException $e): string
    {
        $text = match ($e->error) {
            'no_key' => 'ยังไม่ได้ตั้งค่ากุญแจ API',
            'https_required' => 'ที่อยู่ API ต้องขึ้นต้นด้วย https://',
            'connection' => 'ติดต่อเซิร์ฟเวอร์ RMS ไม่ได้ (ตรวจสอบที่อยู่ เครือข่าย หรือไฟร์วอลล์)',
            'invalid_response' => 'RMS ตอบกลับไม่ใช่ JSON (ตรวจสอบที่อยู่ API)',
            'bad_key' => 'กุญแจ API ไม่ถูกต้อง หรือถูกระงับ/หมดอายุ',
            'ip' => 'IP ของเซิร์ฟเวอร์นี้ไม่อยู่ในรายการ IP ที่อนุญาตของกุญแจใน RMS',
            'scope' => 'กุญแจนี้ไม่มีสิทธิ์อ่านข้อมูลที่ขอ (ตรวจสอบ "ข้อมูลที่แอปนี้อ่านได้" ใน RMS)',
            'rate' => 'เรียก RMS ถี่เกินกำหนด กรุณารอสักครู่แล้วลองใหม่',
            'off' => 'API Center ของ RMS ถูกปิดอยู่',
            default => 'RMS ปฏิเสธการเชื่อมต่อ',
        };

        return $text.' ['.$e->error.($e->httpStatus ? ', HTTP '.$e->httpStatus : '').']';
    }

    /** @return array<string, mixed> Audit values: never the key, only its hint and source. */
    private function auditView(RmsConnectionSettings $s): array
    {
        return [
            'base_url' => $s->url,
            'api_key_hint' => $s->keyHint,
            'api_key_source' => $s->keySource,
            'timeout_seconds' => $s->timeoutSeconds,
            'sync_enabled' => $s->syncEnabled,
            'sync_time' => $s->syncTime,
        ];
    }
}
