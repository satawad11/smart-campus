<?php

namespace App\Services\Camera;

use App\Enums\AiNodeCommandType;
use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Models\AiNode;
use App\Models\AiNodeCommand;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Authorised remote operations on AI nodes (COM-03): allowlisted command
 * types only, audited request and result, bounded by a timeout. Nodes pull
 * their commands over the per-node credential; nothing is pushed into a node.
 */
class AiNodeCommandService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function request(AiNode $node, AiNodeCommandType $type, ?string $reason, User $actor, ?AuditRequestContext $context = null): AiNodeCommand
    {
        if ($node->status !== DeviceStatus::ACTIVE) {
            throw ApiException::conflict('AI_NODE_NOT_ACTIVE', 'Commands can only be sent to an ACTIVE AI node.');
        }

        return DB::transaction(function () use ($node, $type, $reason, $actor, $context) {
            $this->expireStale($node);

            $open = AiNodeCommand::where('ai_node_id', $node->id)
                ->where('command', $type->value)
                ->whereIn('status', AiNodeCommand::OPEN_STATUSES)
                ->lockForUpdate()
                ->first();
            if ($open !== null) {
                throw ApiException::conflict('AI_NODE_COMMAND_PENDING', 'The same command is already waiting for this node.');
            }

            $command = AiNodeCommand::create([
                'ai_node_id' => $node->id,
                'command' => $type,
                'status' => AiNodeCommand::STATUS_PENDING,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 255) : null,
                'requested_by_user_id' => $actor->id,
                'expires_at' => now()->addSeconds((int) config('ai_node.commands.ttl_seconds', 300)),
            ]);

            $this->audit->record(
                AuditAction::AI_NODE_COMMAND_REQUESTED, $actor, 'AI_NODE', $node->uuid,
                newValues: ['command_uuid' => $command->uuid, 'command' => $type->value, 'reason' => $command->reason],
                context: $context, module: 'ai_node',
            );

            return $command;
        });
    }

    /**
     * Hand the node its pending commands, marking them DELIVERED.
     *
     * @return Collection<int, AiNodeCommand>
     */
    public function claim(AiNode $node): Collection
    {
        return DB::transaction(function () use ($node) {
            $this->expireStale($node);

            $pending = AiNodeCommand::where('ai_node_id', $node->id)
                ->where('status', AiNodeCommand::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $command) {
                $command->update(['status' => AiNodeCommand::STATUS_DELIVERED, 'delivered_at' => now()]);
            }

            return $pending;
        });
    }

    public function reportResult(AiNode $node, string $commandUuid, bool $succeeded, ?string $message): AiNodeCommand
    {
        return DB::transaction(function () use ($node, $commandUuid, $succeeded, $message) {
            $command = AiNodeCommand::where('uuid', $commandUuid)->where('ai_node_id', $node->id)->lockForUpdate()->first();
            if ($command === null) {
                // Another node's command is indistinguishable from a missing one.
                throw new ApiException('AI_NODE_COMMAND_NOT_FOUND', 'Command not found.', 404);
            }
            if ($command->status !== AiNodeCommand::STATUS_DELIVERED) {
                throw ApiException::conflict('AI_NODE_COMMAND_NOT_DELIVERED', "Command is {$command->status}, not awaiting a result.");
            }

            $command->update([
                'status' => $succeeded ? AiNodeCommand::STATUS_SUCCEEDED : AiNodeCommand::STATUS_FAILED,
                'completed_at' => now(),
                'result_message' => $message !== null ? mb_substr($message, 0, 500) : null,
            ]);

            $this->audit->record(
                AuditAction::AI_NODE_COMMAND_COMPLETED, null, 'AI_NODE', $node->uuid,
                newValues: ['command_uuid' => $command->uuid, 'command' => $command->command->value, 'status' => $command->status],
                metadata: ['result_message' => $command->result_message],
                module: 'ai_node',
            );

            return $command;
        });
    }

    /**
     * Open commands past their deadline become EXPIRED.
     *
     * @return int number expired
     */
    public function expireStale(?AiNode $node = null): int
    {
        return AiNodeCommand::query()
            ->when($node !== null, fn ($q) => $q->where('ai_node_id', $node->id))
            ->whereIn('status', AiNodeCommand::OPEN_STATUSES)
            ->where('expires_at', '<', now())
            ->update(['status' => AiNodeCommand::STATUS_EXPIRED, 'updated_at' => now()]);
    }
}
