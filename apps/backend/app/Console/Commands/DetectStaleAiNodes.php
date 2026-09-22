<?php

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Enums\HealthStatus;
use App\Models\AiNode;
use App\Models\AiNodeHealthLog;
use Illuminate\Console\Command;

/**
 * Stale-heartbeat -> OFFLINE detection (CAM-014). A node's heartbeat call
 * can only ever report ONLINE/DEGRADED (see AiNodeService::heartbeat) —
 * OFFLINE means the opposite of a call arriving, so it can only be
 * observed by this separate sweep: any administratively ACTIVE node whose
 * last_seen_at is older than config('ai_node.health.stale_after_seconds')
 * gets one OFFLINE AiNodeHealthLog row, and only one per stale period (a
 * node already logged OFFLINE since its last heartbeat is not re-logged
 * every run).
 */
class DetectStaleAiNodes extends Command
{
    protected $signature = 'ai-nodes:detect-stale';

    protected $description = 'Flag AI nodes with no recent heartbeat as OFFLINE';

    public function handle(): int
    {
        $staleAfter = (int) config('ai_node.health.stale_after_seconds');
        $cutoff = now()->subSeconds($staleAfter);

        $staleNodes = AiNode::query()
            ->where('status', DeviceStatus::ACTIVE)
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff);
            })
            ->get();

        $flagged = 0;

        foreach ($staleNodes as $node) {
            $latestLog = AiNodeHealthLog::where('ai_node_id', $node->id)
                ->orderByDesc('recorded_at')
                ->first();

            if ($latestLog !== null && $latestLog->status === HealthStatus::OFFLINE) {
                continue; // already flagged since the last heartbeat — don't spam
            }

            AiNodeHealthLog::create([
                'ai_node_id' => $node->id,
                'status' => HealthStatus::OFFLINE->value,
                'recorded_at' => now(),
            ]);
            $flagged++;
        }

        $this->info("Flagged {$flagged} stale AI node(s) OFFLINE.");

        return self::SUCCESS;
    }
}
