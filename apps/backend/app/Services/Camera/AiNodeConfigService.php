<?php

namespace App\Services\Camera;

use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\Camera;
use Illuminate\Support\Facades\Crypt;

/**
 * Camera configuration sync (CAM-015). The AI node's own runtime process is
 * the one legitimate consumer that must ever see a decrypted RTSP
 * URL/credential — this is the entire reason those columns are encrypted
 * at rest rather than simply hidden: protect against a DB leak while still
 * letting the node that needs to actually open the stream do so. Never
 * logged, never audited with values, and never exposed to any
 * admin-session-facing endpoint (CameraResource keeps has_rtsp_url/
 * has_credentials booleans only).
 */
class AiNodeConfigService
{
    public function getConfig(AiNode $node): array
    {
        $cameras = Camera::where('ai_node_id', $node->id)->get();

        return [
            'ai_node_uuid' => $node->uuid,
            'generated_at' => now()->toISOString(),
            'cameras' => $cameras->map(fn (Camera $camera) => $this->cameraConfig($camera))->all(),
        ];
    }

    private function cameraConfig(Camera $camera): array
    {
        return [
            'camera_uuid' => $camera->uuid,
            'camera_code' => $camera->camera_code,
            'name' => $camera->name,
            'camera_type' => $camera->camera_type,
            'stream_protocol' => $camera->stream_protocol,
            'rtsp_url' => $this->decryptOrNull($camera->rtsp_url_encrypted),
            'username' => $this->decryptOrNull($camera->username_encrypted),
            'password' => $this->decryptOrNull($camera->password_encrypted),
            'face_detection_enabled' => $camera->face_detection_enabled,
            'face_recognition_enabled' => $camera->face_recognition_enabled,
            'liveness_enabled' => $camera->liveness_enabled,
            'snapshot_enabled' => $camera->snapshot_enabled,
            'roi' => $camera->roi_polygon,
            'status' => $camera->status?->value ?? $camera->status,
        ];
    }

    private function decryptOrNull(?string $encrypted): ?string
    {
        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function assertOwnConfig(AiNode $resolvedNode, string $requestedUuid): void
    {
        if ($resolvedNode->uuid !== $requestedUuid) {
            throw ApiException::unprocessable(
                'AI_NODE_CONFIG_FORBIDDEN',
                'A node may only fetch its own configuration.',
            );
        }
    }
}
