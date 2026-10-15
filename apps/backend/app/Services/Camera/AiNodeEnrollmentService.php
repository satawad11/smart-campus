<?php

namespace App\Services\Camera;

use App\Enums\AuditAction;
use App\Enums\DeviceStatus;
use App\Models\AiNode;
use App\Models\AiNodeEnrollmentCode;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * One-time enrollment codes for AI nodes (COM-03e).
 *
 * An administrator issues a short code for one node (shown once, valid for a
 * few minutes). The installer on the node exchanges it exactly once for the
 * node UUID and a freshly issued machine credential, so nobody has to copy a
 * 64-character token by hand. Issuing a new code invalidates the node's
 * unused codes. Exchange goes through AiNodeCredentialService::issueCredential,
 * so it rotates (revokes) any earlier credential of that node, like a manual
 * issue does.
 *
 * Codes use the Crockford base32 alphabet (no I, L, O, U): 12 characters,
 * 60 bits, displayed as XXXX-XXXX-XXXX. Input is normalised (case, dashes,
 * spaces, and the usual O->0 / I,L->1 confusions) before hashing.
 */
class AiNodeEnrollmentService
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const LENGTH = 12;

    public function __construct(
        private readonly AiNodeCredentialService $credentials,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{0: AiNodeEnrollmentCode, 1: string} [row, display code]
     */
    public function issueCode(AiNode $node, ?User $actor, ?AuditRequestContext $context): array
    {
        return DB::transaction(function () use ($node, $actor, $context) {
            AiNodeEnrollmentCode::where('ai_node_id', $node->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $raw = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $row = AiNodeEnrollmentCode::create([
                'ai_node_id' => $node->id,
                'code_hash' => $this->hash($raw),
                'created_by_user_id' => $actor?->id,
                'expires_at' => now()->addMinutes((int) config('ai_node.enrollment.code_ttl_minutes', 30)),
            ]);

            $this->audit->record(
                AuditAction::AI_NODE_ENROLLMENT_CODE_ISSUED, $actor, 'AI_NODE', $node->uuid,
                newValues: [
                    'node_code' => $node->node_code,
                    'enrollment_code_uuid' => $row->uuid,
                    'expires_at' => $row->expires_at->toIso8601String(),
                ],
                context: $context, module: 'ai_node',
            );

            return [$row, implode('-', str_split($raw, 4))];
        });
    }

    /**
     * Exchanges a code for [node, plaintext token]. Fails closed with null for
     * an unknown, used, expired or invalidated code, or a node that is not
     * ACTIVE, without saying which (no enumeration signal).
     *
     * @return array{0: AiNode, 1: string}|null
     */
    public function exchange(string $presented, ?string $ip): ?array
    {
        $hash = $this->hash($this->normalise($presented));

        return DB::transaction(function () use ($hash, $ip) {
            $row = AiNodeEnrollmentCode::where('code_hash', $hash)->lockForUpdate()->first();
            if ($row === null || ! $row->isUsable()) {
                return null;
            }

            $node = $row->aiNode()->first();
            if ($node === null || $node->status !== DeviceStatus::ACTIVE) {
                return null;
            }

            $row->forceFill(['used_at' => now(), 'used_from_ip' => $ip])->save();

            [, $token] = $this->credentials->issueCredential($node, null, null);

            $this->audit->record(
                AuditAction::AI_NODE_ENROLLED, null, 'AI_NODE', $node->uuid,
                newValues: ['node_code' => $node->node_code, 'enrollment_code_uuid' => $row->uuid],
                metadata: ['source_ip' => $ip, 'code_issued_by_user_id' => $row->created_by_user_id],
                module: 'ai_node',
            );

            return [$node, $token];
        });
    }

    private function normalise(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
