<?php

namespace App\Services\Camera;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Enums\HealthStatus;
use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use App\Services\License\LicenseService;
use Illuminate\Support\Facades\DB;

/**
 * AI Node domain service (CAM-003).
 *
 * Two distinct trust boundaries:
 *  - Admin-facing (registerNode/updateNode/changeStatus): a human operator,
 *    over auth:web+permission, provisions the node row and owns its site
 *    assignment/status. This is how node identity and scope are established
 *    — "Backend remains authoritative" / "a node must never assign itself
 *    to another site/zone/camera".
 *  - Node-facing (checkIn/heartbeat): the running node process itself, over
 *    the temporary shared internal.token (CAM-004 replaces this with a
 *    per-node credential without changing this service's contract). A node
 *    identifies itself by node_code and may only update self-reported
 *    facts about itself (hostname/ip/api_url/software_version/capabilities/
 *    telemetry) — never site_id, status, or any other node's row. checkIn()
 *    rejects an unknown node_code rather than creating one: the backend,
 *    not the node, decides whether a node identity may exist.
 *
 * Heartbeat writes raw telemetry to ai_node_health_logs and classifies
 * ONLINE/DEGRADED from configured thresholds (CAM-014, config/ai_node.php).
 * A live heartbeat is never classified OFFLINE — the call arriving IS the
 * observation of aliveness at that instant; stale-heartbeat -> OFFLINE
 * detection is the separate ai-nodes:detect-stale scheduled command.
 */
class AiNodeService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly LicenseService $license,
    ) {}

    // ---- Admin-facing ----

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerNode(array $data, ?User $actor, ?AuditRequestContext $context): AiNode
    {
        $site = isset($data['site_uuid']) ? $this->resolveSite($data['site_uuid']) : null;

        // COM-02: checked before the write transaction so that a refusal's
        // audit record isn't rolled back with it.
        if (($data['status'] ?? DeviceStatus::ACTIVE->value) === DeviceStatus::ACTIVE->value) {
            $this->license->assertCanGrow(LicenseService::RESOURCE_AI_NODE, $actor, $context);
        }

        return DB::transaction(function () use ($data, $site, $actor, $context) {
            $this->assertNodeCodeAvailable($data['node_code']);

            $node = AiNode::create([
                'site_id' => $site?->id,
                'node_code' => $data['node_code'],
                'name' => $data['name'],
                'hostname' => $data['hostname'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'api_url' => $data['api_url'] ?? null,
                'hardware_type' => $data['hardware_type'] ?? null,
                'cpu_model' => $data['cpu_model'] ?? null,
                'gpu_name' => $data['gpu_name'] ?? null,
                'gpu_memory_mb' => $data['gpu_memory_mb'] ?? null,
                'max_camera' => $data['max_camera'] ?? null,
                'status' => $data['status'] ?? DeviceStatus::ACTIVE->value,
            ]);

            $this->audit->record(
                AuditAction::AI_NODE_REGISTERED, $actor, 'AI_NODE', $node->uuid,
                newValues: [
                    'node_code' => $node->node_code,
                    'name' => $node->name,
                    'status' => $node->status->value,
                    'site_uuid' => $site?->uuid,
                ],
                context: $context, module: 'ai_node',
            );

            return $node;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateNode(AiNode $node, array $data, ?User $actor, ?AuditRequestContext $context): AiNode
    {
        return DB::transaction(function () use ($node, $data, $actor, $context) {
            $old = [];
            $new = [];

            foreach (['name', 'hostname', 'ip_address', 'api_url', 'hardware_type', 'cpu_model', 'gpu_name', 'gpu_memory_mb', 'max_camera'] as $field) {
                if (array_key_exists($field, $data) && $node->{$field} !== $data[$field]) {
                    $old[$field] = $node->{$field};
                    $new[$field] = $data[$field];
                    $node->{$field} = $data[$field];
                }
            }

            if (array_key_exists('site_uuid', $data)) {
                $site = $data['site_uuid'] === null ? null : $this->resolveSite($data['site_uuid']);
                if ($node->site_id !== $site?->id) {
                    $old['site_uuid'] = $node->site()->first()?->uuid;
                    $new['site_uuid'] = $site?->uuid;
                    $node->site_id = $site?->id;
                }
            }

            if ($new !== []) {
                $node->save();
                $this->audit->record(
                    AuditAction::AI_NODE_UPDATED, $actor, 'AI_NODE', $node->uuid,
                    oldValues: $old, newValues: $new + ['node_code' => $node->node_code],
                    context: $context, module: 'ai_node',
                );
            }

            return $node;
        });
    }

    public function changeStatus(AiNode $node, DeviceStatus $status, ?User $actor, ?AuditRequestContext $context): AiNode
    {
        if ($status === DeviceStatus::ACTIVE && $node->status !== DeviceStatus::ACTIVE) {
            $this->license->assertCanGrow(LicenseService::RESOURCE_AI_NODE, $actor, $context);
        }

        return DB::transaction(function () use ($node, $status, $actor, $context) {
            $old = $node->status;
            if ($old === $status) {
                return $node;
            }

            $node->status = $status;
            $node->save();

            $this->audit->record(
                AuditAction::AI_NODE_STATUS_CHANGED, $actor, 'AI_NODE', $node->uuid,
                oldValues: ['status' => $old->value],
                newValues: ['status' => $status->value, 'node_code' => $node->node_code],
                context: $context, module: 'ai_node',
            );

            return $node;
        });
    }

    // ---- Node-facing (per-node credential, CAM-004) ----

    /**
     * A node checks in / confirms identity. $node is already resolved and
     * authenticated by the ai_node.token middleware from its own credential
     * — never looked up from client-supplied input, so a credential can
     * never be used to check in as a different node. Updates only
     * self-reported facts; never site_id or status.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkIn(AiNode $node, array $data, ?AuditRequestContext $context): AiNode
    {
        return DB::transaction(function () use ($node, $data, $context) {
            foreach (['hostname', 'ip_address', 'api_url', 'software_version', 'capabilities'] as $field) {
                if (array_key_exists($field, $data)) {
                    $node->{$field} = $data[$field];
                }
            }
            $node->last_seen_at = now();
            $node->save();

            $this->audit->record(
                AuditAction::AI_NODE_CHECKED_IN, null, 'AI_NODE', $node->uuid,
                newValues: [
                    'node_code' => $node->node_code,
                    'software_version' => $node->software_version,
                ],
                context: $context, module: 'ai_node',
            );

            return $node;
        });
    }

    /**
     * Records one raw telemetry snapshot and refreshes last_seen_at. $node
     * is already resolved/authenticated by the ai_node.token middleware —
     * a node can only ever report telemetry for itself. Classifies
     * ONLINE/DEGRADED from the reported usage figures (CAM-014) but never
     * OFFLINE here: the call arriving is itself the observation of the
     * node being alive right now. Stale-heartbeat -> OFFLINE detection is
     * a separate sweep (ai-nodes:detect-stale), since genuine silence — no
     * call at all — cannot be observed synchronously from inside a call.
     *
     * @param  array<string, mixed>  $data
     */
    public function heartbeat(AiNode $node, array $data): AiNode
    {
        return DB::transaction(function () use ($node, $data) {
            $node->last_seen_at = now();
            $node->save();

            AiNodeHealthLog::create([
                'ai_node_id' => $node->id,
                'cpu_usage' => $data['cpu_usage'] ?? null,
                'ram_usage' => $data['ram_usage'] ?? null,
                'gpu_usage' => $data['gpu_usage'] ?? null,
                'gpu_memory_usage' => $data['gpu_memory_usage'] ?? null,
                'temperature' => $data['temperature'] ?? null,
                'active_camera_count' => $data['active_camera_count'] ?? null,
                'queue_depth' => $data['queue_depth'] ?? null,
                'status' => $this->classifyHeartbeat($data)->value,
                'recorded_at' => now(),
            ]);

            return $node;
        });
    }

    private function classifyHeartbeat(array $data): HealthStatus
    {
        $thresholds = config('ai_node.health');

        $checks = [
            [$data['cpu_usage'] ?? null, $thresholds['degraded_cpu_usage']],
            [$data['ram_usage'] ?? null, $thresholds['degraded_ram_usage']],
            [$data['gpu_usage'] ?? null, $thresholds['degraded_gpu_usage']],
            [$data['temperature'] ?? null, $thresholds['degraded_temperature']],
            [$data['queue_depth'] ?? null, $thresholds['degraded_queue_depth']],
        ];

        foreach ($checks as [$value, $limit]) {
            if ($value !== null && $value >= $limit) {
                return HealthStatus::DEGRADED;
            }
        }

        return HealthStatus::ONLINE;
    }

    // ---- Helpers ----

    private function resolveSite(string $uuid): Site
    {
        return Site::where('uuid', $uuid)->first()
            ?? throw ApiException::unprocessable('SITE_NOT_FOUND', 'The specified site does not exist.');
    }

    private function assertNodeCodeAvailable(string $nodeCode): void
    {
        if (AiNode::where('node_code', $nodeCode)->exists()) {
            throw ApiException::conflict('AI_NODE_CODE_ALREADY_EXISTS', 'An AI node with this node_code already exists.');
        }
    }
}
