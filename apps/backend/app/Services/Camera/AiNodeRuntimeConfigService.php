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
    /** key => [min, max] (numbers). */
    public const SCHEMA = [
        'target_fps' => [1, 30],
        'heartbeat_interval_seconds' => [5, 300],
        'config_sync_interval_seconds' => [10, 600],
        'camera_health_interval_seconds' => [5, 300],
        'command_poll_interval_seconds' => [5, 300],
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
