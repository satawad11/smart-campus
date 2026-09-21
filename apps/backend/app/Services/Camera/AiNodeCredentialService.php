<?php

namespace App\Services\Camera;

use App\Enums\AiNodeCredentialStatus;
use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Models\AiNode;
use App\Models\AiNodeCredential;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-node credential lifecycle (CAM-004).
 *
 * Design: at most one ACTIVE credential per node at a time. Issuing a new
 * credential atomically revokes any prior active one for that node — this
 * is "rotation": there is no separate rotate() operation, issuing IS
 * rotating. This is the minimum-reversible secure default (no standing
 * multi-credential overlap window in V1); a future task can add a brief
 * overlap window if pilot operations need it.
 *
 * Only a one-way hash (sha256) of the plaintext token is ever stored. The
 * plaintext is returned to the caller exactly once, in the issuance
 * response, and is never recoverable afterward — matching how the rest of
 * this app treats credentials it can't decrypt back out (password hashes).
 *
 * A node credential authenticates a MACHINE identity only: it is resolved
 * to an AiNode, never a User, and grants no human-user permission.
 */
class AiNodeCredentialService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{0: AiNodeCredential, 1: string} [credential row, plaintext token]
     */
    public function issueCredential(AiNode $node, ?User $actor, ?AuditRequestContext $context): array
    {
        return DB::transaction(function () use ($node, $actor, $context) {
            // Rotation: revoke every currently active credential for this node.
            // Each revocation is fully audited (same as a standalone revoke) so
            // the trail shows exactly which credential was replaced and when.
            $node->credentials()
                ->where('status', AiNodeCredentialStatus::ACTIVE->value)
                ->get()
                ->each(fn (AiNodeCredential $old) => $this->revokeCredential($old, $actor, $context));

            $plaintext = Str::random(64);

            $credential = AiNodeCredential::create([
                'ai_node_id' => $node->id,
                'token_hash' => $this->hash($plaintext),
                'status' => AiNodeCredentialStatus::ACTIVE->value,
            ]);

            $this->audit->record(
                AuditAction::AI_NODE_CREDENTIAL_ISSUED, $actor, 'AI_NODE', $node->uuid,
                newValues: ['node_code' => $node->node_code, 'credential_uuid' => $credential->uuid],
                context: $context, module: 'ai_node',
            );

            return [$credential, $plaintext];
        });
    }

    public function revokeCredential(AiNodeCredential $credential, ?User $actor, ?AuditRequestContext $context): AiNodeCredential
    {
        if ($credential->status === AiNodeCredentialStatus::REVOKED) {
            return $credential;
        }

        return DB::transaction(function () use ($credential, $actor, $context) {
            $credential->status = AiNodeCredentialStatus::REVOKED;
            $credential->revoked_at = now();
            $credential->save();

            $node = $credential->aiNode()->firstOrFail();
            $this->audit->record(
                AuditAction::AI_NODE_CREDENTIAL_REVOKED, $actor, 'AI_NODE', $node->uuid,
                newValues: ['node_code' => $node->node_code, 'credential_uuid' => $credential->uuid],
                context: $context, module: 'ai_node',
            );

            return $credential;
        });
    }

    /**
     * Authenticates a presented bearer token. Fails closed: unknown, revoked,
     * or a token whose node has been administratively disabled all return
     * null (never a distinguishable reason — no enumeration signal). On
     * success, records last_used_at and returns the resolved node.
     */
    public function authenticate(string $plaintextToken): ?AiNode
    {
        $credential = AiNodeCredential::where('token_hash', $this->hash($plaintextToken))
            ->where('status', AiNodeCredentialStatus::ACTIVE->value)
            ->first();

        if ($credential === null) {
            return null;
        }

        $node = $credential->aiNode()->first();

        if ($node === null || $node->status !== DeviceStatus::ACTIVE) {
            return null;
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        return $node;
    }

    private function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
