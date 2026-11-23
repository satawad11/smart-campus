<?php

namespace App\Services\Camera;

use App\Enums\AuditAction;
use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\AiNodeConfigRevision;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Versioned node runtime settings (COM-03). Only the keys in SCHEMA are
 * accepted, each within bounds; every save is a new numbered revision and
 * a rollback is a new revision that copies an older one, so history is
 * never rewritten. The node receives the latest revision with its camera
 * configuration and reports the revision it applied in its heartbeat.
 */
class AiNodeRuntimeConfigService
{
    /**
     * key => [min, max, default] (numbers). The default is what the node uses
     * when a revision leaves the key out.
     *
     * CAM-022: the quality_* keys tune the AI node's recognition quality gate
     * (the check that rejects a face before matching: BAD_POSE, IMAGE_BLUR,
     * IMAGE_TOO_DARK, …). Same keys and bounds as the node's
     * _QUALITY_SETTINGS. The bounds keep the gate on; they never touch
     * liveness/PAD, the match/review thresholds (FACE-021, config/face.php)
     * or enrollment quality.
     */
    public const SCHEMA = [
        'target_fps' => [1, 30, 5],
        'heartbeat_interval_seconds' => [5, 300, 15],
        'config_sync_interval_seconds' => [10, 600, 30],
        'camera_health_interval_seconds' => [5, 300, 15],
        'command_poll_interval_seconds' => [5, 300, 10],
        'quality_max_yaw_degrees' => [15, 60, 30],
        'quality_max_pitch_degrees' => [15, 60, 25],
        'quality_max_roll_degrees' => [15, 60, 25],
        'quality_min_pose_score' => [0.1, 0.9, 0.4],
        'quality_min_blur_score' => [0.05, 0.9, 0.35],
        'quality_min_face_size_score' => [0.1, 1.0, 0.3],
        'quality_min_luminance' => [0.05, 0.5, 0.2],
        'quality_max_luminance' => [0.6, 0.98, 0.85],
        'quality_min_quality_score' => [0.2, 0.9, 0.5],
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function latest(AiNode $node): ?AiNodeConfigRevision
    {
        return AiNodeConfigRevision::where('ai_node_id', $node->id)->orderByDesc('revision')->first();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function revise(AiNode $node, array $settings, ?string $note, User $actor, ?AuditRequestContext $context = null): AiNodeConfigRevision
    {
        $clean = $this->validate($settings);
        $revision = $this->append($node, $clean, $note, null, $actor);

        $this->audit->record(
            AuditAction::AI_NODE_CONFIG_REVISED, $actor, 'AI_NODE', $node->uuid,
            newValues: ['revision' => $revision->revision, 'settings' => $clean, 'note' => $revision->note],
            context: $context, module: 'ai_node',
        );

        return $revision;
    }

    public function rollback(AiNode $node, int $toRevision, User $actor, ?AuditRequestContext $context = null): AiNodeConfigRevision
    {
        $source = AiNodeConfigRevision::where('ai_node_id', $node->id)->where('revision', $toRevision)->first();
        if ($source === null) {
            throw new ApiException('AI_NODE_CONFIG_REVISION_NOT_FOUND', "Revision {$toRevision} does not exist for this node.", 404);
        }

        $revision = $this->append($node, $source->settings, "Rollback to revision {$toRevision}", $toRevision, $actor);

        $this->audit->record(
            AuditAction::AI_NODE_CONFIG_ROLLED_BACK, $actor, 'AI_NODE', $node->uuid,
            oldValues: ['revision' => $revision->revision - 1],
            newValues: ['revision' => $revision->revision, 'rolled_back_from_revision' => $toRevision, 'settings' => $source->settings],
            context: $context, module: 'ai_node',
        );

        return $revision;
    }

    /**
     * Runtime section of the node configuration (null until a revision exists).
     *
     * @return array{revision: int, settings: array<string, mixed>}|null
     */
    public function forNode(AiNode $node): ?array
    {
        $latest = $this->latest($node);

        return $latest === null ? null : ['revision' => $latest->revision, 'settings' => $latest->settings];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, int|float>
     */
    public function validate(array $settings): array
    {
        $errors = [];
        $clean = [];
        foreach ($settings as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                $errors[] = "{$key} is not a supported setting";

                continue;
            }
            [$min, $max] = self::SCHEMA[$key];
            if (! is_int($value) && ! is_float($value)) {
                $errors[] = "{$key} must be a number";
            } elseif ($value < $min || $value > $max) {
                $errors[] = "{$key} must be between {$min} and {$max}";
            } else {
                $clean[$key] = $value;
            }
        }
        if ($settings === []) {
            $errors[] = 'at least one setting is required';
        }
        if ($errors !== []) {
            throw ApiException::unprocessable('AI_NODE_CONFIG_INVALID', 'Invalid node settings: '.implode('; ', $errors).'.');
        }
        ksort($clean);

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function append(AiNode $node, array $settings, ?string $note, ?int $rolledBackFrom, User $actor): AiNodeConfigRevision
    {
        try {
            return DB::transaction(function () use ($node, $settings, $note, $rolledBackFrom, $actor) {
                $next = (int) AiNodeConfigRevision::where('ai_node_id', $node->id)->max('revision') + 1;

                return AiNodeConfigRevision::create([
                    'ai_node_id' => $node->id,
                    'revision' => $next,
                    'settings' => $settings,
                    'note' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
                    'rolled_back_from_revision' => $rolledBackFrom,
                    'created_by_user_id' => $actor->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('AI_NODE_CONFIG_CONFLICT', 'Another revision was saved at the same time; reload and retry.');
        }
    }
}
