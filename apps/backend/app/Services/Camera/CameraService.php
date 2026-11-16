<?php

namespace App\Services\Camera;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Enums\LocationStatus;
use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Site;
use App\Models\User;
use App\Models\Zone;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\Camera\Connectivity\CameraConnectivityProberInterface;
use App\Services\Camera\Connectivity\CameraConnectivityResult;
use App\Services\Camera\Connectivity\TcpConnectivityProber;
use App\Services\Camera\Snapshot\CameraSnapshotProviderInterface;
use App\Services\Camera\Snapshot\CameraSnapshotResult;
use App\Services\Camera\Snapshot\FfmpegCameraSnapshotProvider;
use App\Services\Camera\Snapshot\FfmpegFrameGrabber;
use App\Services\Camera\Snapshot\MockCameraSnapshotProvider;
use App\Services\License\LicenseService;
use App\Services\System\FeatureFlagService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Camera domain service (CAM-002).
 *
 * Owns UUID resolution, credential encryption, cross-entity consistency
 * (zone belongs to site; assigned AI node compatible with site), the
 * connection-test/snapshot operations, and audit. Controllers stay thin.
 *
 * Credentials: rtsp_url/username/password are encrypted with
 * Crypt::encryptString() before storage (same pattern as
 * users.mfa_secret_encrypted) and are never logged, audited, or returned in
 * plaintext. Update distinguishes "unchanged" (key absent from payload),
 * "replacement" (non-null value), and "cleared" (explicit null) per field.
 *
 * Connection test / snapshot are intentionally minimal in V1: a bounded TCP
 * reachability probe and a mock (non-fabricated) placeholder frame. Full
 * RTSP video acquisition is CAM-005/006/007 — this service's public
 * contract (request/response shape, auth, timeouts, privacy) will not need
 * to change when a real provider is wired in.
 */
class CameraService
{
    private CameraConnectivityProberInterface $prober;

    private CameraSnapshotProviderInterface $snapshotProvider;

    public function __construct(
        private readonly AuditService $audit,
        private readonly LicenseService $license,
        ?CameraConnectivityProberInterface $prober = null,
        ?CameraSnapshotProviderInterface $snapshotProvider = null,
    ) {
        // Config/DI-resolved with safe defaults, mirroring FaceLivenessService's
        // provider-resolution pattern — a real snapshot/connectivity provider
        // can be wired in later (CAM-005/006/007) without changing callers.
        $this->prober = $prober ?? new TcpConnectivityProber;
        $this->snapshotProvider = $snapshotProvider ?? self::configuredSnapshotProvider();
    }

    private static function ffmpegBinary(): string
    {
        return (string) config('camera.snapshot.ffmpeg_binary', 'ffmpeg');
    }

    /** @var array<string, bool> ffmpeg presence per binary, probed once per process. */
    private static array $ffmpegAvailable = [];

    private static function ffmpegEnabled(): bool
    {
        return match ((string) config('camera.snapshot.provider', 'auto')) {
            'ffmpeg' => true,
            'mock' => false,
            default => self::$ffmpegAvailable[self::ffmpegBinary()] ??= FfmpegFrameGrabber::isAvailable(self::ffmpegBinary()),
        };
    }

    private static function configuredSnapshotProvider(): CameraSnapshotProviderInterface
    {
        return self::ffmpegEnabled()
            ? new FfmpegCameraSnapshotProvider(new FfmpegFrameGrabber(self::ffmpegBinary()))
            : new MockCameraSnapshotProvider;
    }

    /**
     * Test-only hook to swap the connectivity prober without a container rebind.
     */
    public function setConnectivityProber(CameraConnectivityProberInterface $prober): void
    {
        $this->prober = $prober;
    }

    /**
     * Test-only hook to swap the snapshot provider without a container rebind.
     */
    public function setSnapshotProvider(CameraSnapshotProviderInterface $provider): void
    {
        $this->snapshotProvider = $provider;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCamera(array $data, ?User $actor, ?AuditRequestContext $context): Camera
    {
        $site = $this->resolveSite($data['site_uuid']);
        $zone = $this->resolveZoneForSite($data['zone_uuid'], $site);
        $data['camera_code'] = ($data['camera_code'] ?? '') !== '' ? $data['camera_code'] : $this->nextCameraCode();
        $aiNode = $this->resolveAiNodeForSite($data['ai_node_uuid'] ?? null, $site);
        $this->assertPlateRecognitionAllowed($data);

        // COM-02: checked before the write transaction so that a refusal's
        // audit record isn't rolled back with it.
        if (($data['status'] ?? DeviceStatus::ACTIVE->value) === DeviceStatus::ACTIVE->value) {
            $this->license->assertCanGrow(LicenseService::RESOURCE_CAMERA, $actor, $context);
        }

        return DB::transaction(function () use ($data, $site, $zone, $aiNode, $actor, $context) {
            $this->assertCameraCodeAvailable($data['camera_code']);

            $camera = Camera::create([
                'site_id' => $site->id,
                'zone_id' => $zone->id,
                'ai_node_id' => $aiNode?->id,
                'camera_code' => $data['camera_code'],
                'name' => $data['name'],
                'camera_type' => $data['camera_type'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'stream_protocol' => $data['stream_protocol'] ?? null,
                'rtsp_url_encrypted' => $this->encryptOrNull($data['rtsp_url'] ?? null),
                'username_encrypted' => $this->encryptOrNull($data['username'] ?? null),
                'password_encrypted' => $this->encryptOrNull($data['password'] ?? null),
                'onvif_url' => $data['onvif_url'] ?? null,
                'face_detection_enabled' => $data['face_detection_enabled'] ?? false,
                'face_recognition_enabled' => $data['face_recognition_enabled'] ?? false,
                'liveness_enabled' => $data['liveness_enabled'] ?? false,
                'snapshot_enabled' => $data['snapshot_enabled'] ?? false,
                'plate_recognition_enabled' => $data['plate_recognition_enabled'] ?? false,
                'status' => $data['status'] ?? DeviceStatus::ACTIVE->value,
            ]);

            $this->audit->record(
                AuditAction::CAMERA_CREATED, $actor, 'CAMERA', $camera->uuid,
                newValues: [
                    'camera_code' => $camera->camera_code,
                    'name' => $camera->name,
                    'status' => $camera->status->value,
                    'site_uuid' => $site->uuid,
                    'zone_uuid' => $zone->uuid,
                    'ai_node_uuid' => $aiNode?->uuid,
                    'has_rtsp_url' => $camera->rtsp_url_encrypted !== null,
                    'has_credentials' => $camera->username_encrypted !== null,
                ],
                context: $context, module: 'camera',
            );

            return $camera;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCamera(Camera $camera, array $data, ?User $actor, ?AuditRequestContext $context): Camera
    {
        $this->assertPlateRecognitionAllowed($data);

        return DB::transaction(function () use ($camera, $data, $actor, $context) {
            $old = [];
            $new = [];

            foreach (['name', 'camera_type', 'ip_address', 'stream_protocol', 'onvif_url',
                'face_detection_enabled', 'face_recognition_enabled', 'liveness_enabled', 'snapshot_enabled',
                'plate_recognition_enabled'] as $field) {
                if (array_key_exists($field, $data) && $camera->{$field} !== $data[$field]) {
                    $old[$field] = $camera->{$field};
                    $new[$field] = $data[$field];
                    $camera->{$field} = $data[$field];
                }
            }

            if (array_key_exists('ai_node_uuid', $data)) {
                $site = $camera->site()->firstOrFail();
                $aiNode = $this->resolveAiNodeForSite($data['ai_node_uuid'], $site);
                if ($camera->ai_node_id !== $aiNode?->id) {
                    $old['ai_node_uuid'] = $camera->aiNode?->uuid;
                    $new['ai_node_uuid'] = $aiNode?->uuid;
                    $camera->ai_node_id = $aiNode?->id;
                }
            }

            // Credentials: presence in payload = intent to change (to a value,
            // or explicitly to null = clear). Absence = leave untouched. Only
            // a boolean "did this field change" is ever recorded in audit —
            // never the values themselves.
            $credentialFlags = [];
            foreach ([
                'rtsp_url' => 'rtsp_url_encrypted',
                'username' => 'username_encrypted',
                'password' => 'password_encrypted',
            ] as $inputKey => $column) {
                if (array_key_exists($inputKey, $data)) {
                    $camera->{$column} = $this->encryptOrNull($data[$inputKey]);
                    $credentialFlags[$inputKey.'_changed'] = true;
                }
            }

            $changed = $new !== [] || $credentialFlags !== [];

            if ($changed) {
                $camera->save();

                $this->audit->record(
                    AuditAction::CAMERA_UPDATED, $actor, 'CAMERA', $camera->uuid,
                    oldValues: $old,
                    newValues: $new + $credentialFlags + ['camera_code' => $camera->camera_code],
                    context: $context, module: 'camera',
                );
            }

            return $camera;
        });
    }

    public function changeStatus(Camera $camera, DeviceStatus $status, ?User $actor, ?AuditRequestContext $context): Camera
    {
        if ($status === DeviceStatus::ACTIVE && $camera->status !== DeviceStatus::ACTIVE) {
            $this->license->assertCanGrow(LicenseService::RESOURCE_CAMERA, $actor, $context);
        }

        return DB::transaction(function () use ($camera, $status, $actor, $context) {
            $old = $camera->status;

            if ($old === $status) {
                return $camera;
            }

            $camera->status = $status;
            $camera->save();

            $this->audit->record(
                AuditAction::CAMERA_STATUS_CHANGED, $actor, 'CAMERA', $camera->uuid,
                oldValues: ['status' => $old->value],
                newValues: ['status' => $status->value, 'camera_code' => $camera->camera_code],
                context: $context, module: 'camera',
            );

            return $camera;
        });
    }

    /**
     * Sets or clears the camera's region-of-interest polygon (CAM-016).
     * $points is either null/empty (clear the ROI — process the whole
     * frame) or an already-validated list of >= 3 {x, y} vertices
     * (UpdateCameraRoiRequest). Storage is a plain array of points; the
     * ai-service is the one that interprets it as a polygon
     * (app.roi.schemas.RegionOfInterest) when filtering detections.
     */
    public function updateRoi(Camera $camera, ?array $points, ?User $actor, ?AuditRequestContext $context): Camera
    {
        return DB::transaction(function () use ($camera, $points, $actor, $context) {
            $old = $camera->roi_polygon;
            $new = $points === [] ? null : $points;

            $camera->roi_polygon = $new;
            $camera->save();

            $this->audit->record(
                AuditAction::CAMERA_ROI_UPDATED, $actor, 'CAMERA', $camera->uuid,
                oldValues: ['roi_configured' => $old !== null],
                newValues: ['roi_configured' => $new !== null, 'camera_code' => $camera->camera_code],
                context: $context, module: 'camera',
            );

            return $camera;
        });
    }

    /**
     * Bounded-timeout TCP reachability check against the camera's stream
     * host/port. Never performs a full RTSP handshake (CAM-006) and never
     * logs/audits the raw RTSP URL or credentials — only host/port/outcome.
     */
    public function testConnection(Camera $camera, ?User $actor, ?AuditRequestContext $context): CameraConnectivityResult
    {
        $timeout = (int) config('camera.connection_test.timeout_seconds', 3);
        [$host, $port] = $this->resolveHostPort($camera);

        if ($host === null) {
            $result = CameraConnectivityResult::failure('CAMERA_NO_STREAM_CONFIGURED');
        } else {
            $result = $this->prober->probe($host, $port, $timeout);
        }

        $this->audit->record(
            AuditAction::CAMERA_CONNECTION_TESTED, $actor, 'CAMERA', $camera->uuid,
            newValues: [
                'camera_code' => $camera->camera_code,
                'reachable' => $result->reachable,
                'error_code' => $result->errorCode,
            ],
            context: $context, module: 'camera',
        );

        return $result;
    }

    /**
     * Captures a single controlled frame. No enrollment, no recognition, no
     * permanent biometric persistence — this is an operational preview only.
     */
    public function captureSnapshot(Camera $camera, ?User $actor, ?AuditRequestContext $context): CameraSnapshotResult
    {
        $timeout = (int) config('camera.snapshot.timeout_seconds', 5);
        $result = $this->snapshotProvider->capture($camera, $timeout);

        $this->audit->record(
            AuditAction::CAMERA_SNAPSHOT_CAPTURED, $actor, 'CAMERA', $camera->uuid,
            newValues: [
                'camera_code' => $camera->camera_code,
                'success' => $result->success,
                'source' => $result->source,
                'error_code' => $result->errorCode,
            ],
            context: $context, module: 'camera',
        );

        return $result;
    }

    /**
     * Site/zone used when the add-camera form leaves them blank: the first
     * active site and its first active zone. When the install has none yet
     * (fresh server, nothing provisioned) a default site "MAIN" and zone
     * "GENERAL" are created and audited, so a camera can always be added.
     *
     * $allowedSiteIds: null = GLOBAL caller (may provision defaults); a list
     * restricts the choice to those sites and never provisions a new site.
     *
     * @param  list<int>|null  $allowedSiteIds
     * @return array{0: Site, 1: Zone}
     */
    public function defaultLocation(?User $actor, ?AuditRequestContext $context, ?array $allowedSiteIds = null): array
    {
        return DB::transaction(function () use ($actor, $context, $allowedSiteIds) {
            $site = Site::where('status', LocationStatus::ACTIVE->value)
                ->when($allowedSiteIds !== null, fn ($q) => $q->whereIn('id', $allowedSiteIds))
                ->orderBy('id')->first();
            if ($site === null && $allowedSiteIds !== null) {
                throw ApiException::unprocessable('SITE_NOT_FOUND', 'No site is available for this account.');
            }
            if ($site === null) {
                $site = Site::create([
                    'code' => 'MAIN',
                    'name' => 'พื้นที่หลัก',
                    'timezone' => config('app.timezone', 'Asia/Bangkok') === 'UTC' ? 'Asia/Bangkok' : config('app.timezone'),
                    'status' => LocationStatus::ACTIVE,
                ]);
                $this->audit->record(
                    AuditAction::LOCATION_DEFAULT_PROVISIONED, $actor, 'SITE', $site->uuid,
                    newValues: ['code' => $site->code, 'name' => $site->name],
                    context: $context, module: 'camera',
                );
            }

            $zone = $this->defaultZoneFor($site, $actor, $context);

            return [$site, $zone];
        });
    }

    public function defaultZoneFor(Site $site, ?User $actor, ?AuditRequestContext $context): Zone
    {
        $zone = Zone::where('site_id', $site->id)->where('status', LocationStatus::ACTIVE->value)->orderBy('id')->first();
        if ($zone !== null) {
            return $zone;
        }

        $zone = Zone::create([
            'site_id' => $site->id,
            'code' => 'GENERAL',
            'name' => 'โซนทั่วไป',
            'zone_type' => 'GENERAL',
            'status' => LocationStatus::ACTIVE,
        ]);
        $this->audit->record(
            AuditAction::LOCATION_DEFAULT_PROVISIONED, $actor, 'ZONE', $zone->uuid,
            newValues: ['site_code' => $site->code, 'code' => $zone->code, 'name' => $zone->name],
            context: $context, module: 'camera',
        );

        return $zone;
    }

    /**
     * Next free code in the CAM-0001 series. Advisory only: the unique
     * check inside createCamera() still guards against a race.
     */
    public function nextCameraCode(): string
    {
        $max = 0;
        foreach (Camera::where('camera_code', 'like', 'CAM-%')->pluck('camera_code') as $code) {
            if (preg_match('/^CAM-(\d+)$/', $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        do {
            $max++;
            $candidate = sprintf('CAM-%04d', $max);
        } while (Camera::where('camera_code', $candidate)->exists());

        return $candidate;
    }

    /**
     * Grabs one live frame from a stream that is not saved yet, so the
     * operator can confirm the URL/credentials before creating the camera.
     * Nothing is persisted except an audit entry (host only, never the URL
     * or credentials). No enrollment or recognition is triggered.
     *
     * @param  array<string, mixed>  $data
     */
    public function previewStream(array $data, ?User $actor, ?AuditRequestContext $context): CameraSnapshotResult
    {
        $timeout = (int) config('camera.snapshot.timeout_seconds', 5);
        $url = FfmpegFrameGrabber::buildStreamUrl($data['rtsp_url'] ?? null, $data['username'] ?? null, $data['password'] ?? null);

        $result = self::ffmpegEnabled()
            ? (new FfmpegFrameGrabber(self::ffmpegBinary()))->grab($url, max($timeout, 8))
            : CameraSnapshotResult::failure('CAMERA_PREVIEW_UNAVAILABLE');

        $this->audit->record(
            AuditAction::CAMERA_STREAM_PREVIEWED, $actor, 'CAMERA', null,
            newValues: [
                'host' => $url !== null ? parse_url($url, PHP_URL_HOST) : null,
                'success' => $result->success,
                'error_code' => $result->errorCode,
            ],
            context: $context, module: 'camera',
        );

        return $result;
    }

    // ---- Resolution + consistency helpers ----

    /**
     * VEH-005 (ACR-VEH-01 D1): plate reading can only be switched ON while the ALPR module is
     * enabled. Switching it off is always allowed.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertPlateRecognitionAllowed(array $data): void
    {
        $on = filter_var($data['plate_recognition_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($on && ! app(FeatureFlagService::class)->isEnabled('alpr')) {
            throw ApiException::unprocessable('ALPR_DISABLED', 'Plate recognition is not enabled for this installation.');
        }
    }

    private function resolveSite(string $uuid): Site
    {
        return Site::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('SITE_NOT_FOUND', 'The specified site does not exist.');
    }

    private function resolveZoneForSite(string $uuid, Site $site): Zone
    {
        $zone = Zone::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('ZONE_NOT_FOUND', 'The specified zone does not exist.');

        if ($zone->site_id !== $site->id) {
            throw ApiException::unprocessable('ACADEMIC_RELATIONSHIP_MISMATCH', 'The zone does not belong to the specified site.');
        }

        return $zone;
    }

    private function resolveAiNodeForSite(?string $uuid, Site $site): ?AiNode
    {
        if ($uuid === null) {
            return null;
        }

        $node = AiNode::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('AI_NODE_NOT_FOUND', 'The specified AI node does not exist.');

        // A node with a fixed site assignment must match; a node with no
        // fixed site (site_id null) may serve multiple sites (CAM-001).
        if ($node->site_id !== null && $node->site_id !== $site->id) {
            throw ApiException::unprocessable('AI_NODE_SITE_MISMATCH', 'The AI node is assigned to a different site.');
        }

        return $node;
    }

    private function assertCameraCodeAvailable(string $cameraCode): void
    {
        if (Camera::where('camera_code', $cameraCode)->exists()) {
            throw ApiException::conflict('CAMERA_CODE_ALREADY_EXISTS', 'A camera with this camera_code already exists.');
        }
    }

    private function encryptOrNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString($value);
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private function resolveHostPort(Camera $camera): array
    {
        if ($camera->ip_address !== null) {
            return [$camera->ip_address, 554];
        }

        if ($camera->rtsp_url_encrypted === null) {
            return [null, 554];
        }

        try {
            $decrypted = Crypt::decryptString($camera->rtsp_url_encrypted);
        } catch (\Throwable) {
            return [null, 554];
        }

        $parts = parse_url($decrypted);

        return [$parts['host'] ?? null, $parts['port'] ?? 554];
    }
}
