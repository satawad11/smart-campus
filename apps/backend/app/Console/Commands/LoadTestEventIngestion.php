<?php

namespace App\Console\Commands;

use App\Enums\StudentAcademicStatus;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\Camera;
use App\Models\Person;
use App\Models\Site;
use App\Models\StudentProfile;
use App\Models\Zone;
use App\Services\Camera\AiNodeCredentialService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * EVT-021 — load test recognition-event ingestion + realtime broadcast.
 *
 * REAL, not synthetic: unlike CAM-020's fully-mocked AI-node pipeline
 * benchmark, this fires genuine HTTP requests at the actually-running
 * backend (through the real nginx gateway, not an in-process test double),
 * exercising the real idempotency/cooldown/policy/decision-persistence/
 * broadcast pipeline end to end — including a real publish to the real
 * Reverb server. Never run against a production database: refuses outside
 * local/testing environments.
 */
class LoadTestEventIngestion extends Command
{
    protected $signature = 'events:load-test
        {--base-url=http://nginx : Base URL of the gateway to hit}
        {--total=200 : Total recognition-event requests to fire}
        {--concurrency=20 : Requests in flight at once}
        {--persons=15 : Distinct known persons to recognize against (repeats exercise cooldown suppression)}
        {--unknown-ratio=0.2 : Fraction of requests that are UNKNOWN classification}';

    protected $description = 'EVT-021: load test recognition-event ingestion throughput/latency and realtime broadcast delivery';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run a load test against a production environment.');

            return self::FAILURE;
        }

        $total = (int) $this->option('total');
        $concurrency = (int) $this->option('concurrency');
        $personCount = (int) $this->option('persons');
        $unknownRatio = (float) $this->option('unknown-ratio');
        $baseUrl = rtrim($this->option('base-url'), '/');

        $this->info("EVT-021 load test: total={$total} concurrency={$concurrency} persons={$personCount} unknown_ratio={$unknownRatio} target={$baseUrl}");

        [$node, $camera, $bearer] = $this->provisionFixtures();
        $persons = $this->provisionPersons($personCount);

        $payloads = $this->buildPayloads($total, $camera, $node, $persons, $unknownRatio);

        $eventCountBefore = AccessEvent::count();
        $decisionCountBefore = AccessDecision::count();

        $results = [];
        $wallStart = microtime(true);

        foreach (array_chunk($payloads, $concurrency) as $chunk) {
            $chunkStart = microtime(true);

            $responses = Http::pool(fn (Pool $pool) => collect($chunk)->map(
                fn (array $payload, int $i) => $pool->as((string) $i)
                    ->withHeaders($bearer)
                    ->timeout(15)
                    ->post("{$baseUrl}/internal/v1/events/recognition", $payload)
            )->all());

            $chunkElapsedMs = (microtime(true) - $chunkStart) * 1000;

            foreach ($responses as $response) {
                $results[] = [
                    'status' => $response instanceof \Throwable ? 0 : $response->status(),
                    'ok' => ! ($response instanceof \Throwable) && $response->successful(),
                ];
            }

            $this->line(sprintf('  chunk of %d requests: %.1f ms wall time', count($chunk), $chunkElapsedMs));
        }

        $wallElapsed = microtime(true) - $wallStart;

        $eventCountAfter = AccessEvent::count();
        $decisionCountAfter = AccessDecision::count();

        $succeeded = collect($results)->where('ok', true)->count();
        $failed = count($results) - $succeeded;
        $created = $eventCountAfter - $eventCountBefore;
        $suppressedOrIdempotent = $succeeded - $created;

        $this->newLine();
        $this->info('--- EVT-021 Load Test Results ---');
        $this->table(['Metric', 'Value'], [
            ['Total requests', count($results)],
            ['Succeeded (2xx)', $succeeded],
            ['Failed (non-2xx / exception)', $failed],
            ['Wall time (s)', round($wallElapsed, 2)],
            ['Throughput (req/s)', round(count($results) / max($wallElapsed, 0.001), 1)],
            ['AccessEvent rows created', $created],
            ['AccessDecision rows created', $decisionCountAfter - $decisionCountBefore],
            ['Cooldown-suppressed / idempotent replays', max($suppressedOrIdempotent, 0)],
        ]);

        if ($failed > 0) {
            $this->warn("{$failed} request(s) did not succeed — see status codes above for detail.");
        }

        $this->info('Realtime broadcast verification: run scripts/verify-realtime-load.js (Node, pusher-js) '.
            'against the same window to confirm WebSocket delivery — see docs/EVT-021-LOAD-TEST.md.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: AiNode, 1: Camera, 2: array<string, string>}
     */
    private function provisionFixtures(): array
    {
        $site = Site::firstOrCreate(['name' => 'EVT-021 Load Test Site'], ['code' => 'EVT021-SITE']);
        $zone = Zone::firstOrCreate(['name' => 'EVT-021 Zone', 'site_id' => $site->id], ['code' => 'EVT021-ZONE']);
        $node = AiNode::firstOrCreate(['name' => 'EVT-021 Load Test Node'], ['node_code' => 'EVT021-NODE']);
        $camera = Camera::firstOrCreate(
            ['name' => 'EVT-021 Load Test Camera'],
            [
                'camera_code' => 'EVT021-CAM',
                'site_id' => $site->id,
                'zone_id' => $zone->id,
                'ai_node_id' => $node->id,
                'status' => 'ACTIVE',
                'rtsp_url_encrypted' => encrypt('rtsp://evt021-load-test'),
            ],
        );

        [, $token] = app(AiNodeCredentialService::class)->issueCredential($node, null, null);

        return [$node, $camera, ['Authorization' => "Bearer {$token}"]];
    }

    /**
     * @return list<Person>
     */
    private function provisionPersons(int $count): array
    {
        return collect(range(1, $count))->map(function () {
            $person = Person::factory()->create();
            StudentProfile::factory()->for($person)->create([
                'academic_status' => StudentAcademicStatus::ACTIVE,
            ]);

            return $person;
        })->all();
    }

    /**
     * @param  list<Person>  $persons
     * @return list<array<string, mixed>>
     */
    private function buildPayloads(int $total, Camera $camera, AiNode $node, array $persons, float $unknownRatio): array
    {
        $payloads = [];

        for ($i = 0; $i < $total; $i++) {
            $isUnknown = (mt_rand() / mt_getrandmax()) < $unknownRatio;
            $person = $persons[$i % count($persons)];

            $payloads[] = [
                'event_uuid' => (string) Str::uuid(),
                'camera_uuid' => $camera->uuid,
                'ai_node_uuid' => $node->uuid,
                // Distinct track_id per request: a real AI node assigns a
                // fresh track per re-acquisition, so idempotency never
                // collapses genuinely distinct sightings — only the
                // semantic-cooldown layer (camera+person window) does that,
                // which this load intentionally exercises via person reuse.
                'track_id' => (string) Str::uuid(),
                'occurred_at' => now()->toISOString(),
                'classification' => $isUnknown ? 'UNKNOWN' : 'MATCH',
                'candidate_person_uuid' => $isUnknown ? null : $person->uuid,
                'similarity' => $isUnknown ? null : 0.92,
                'distance' => $isUnknown ? null : 0.08,
                'model_code' => 'arcface-r50-v1',
                'model_version' => '1.0.0',
                'quality_score' => 0.9,
                'liveness_status' => 'REAL',
                'rejected_locally' => false,
                'reasons' => [],
            ];
        }

        return $payloads;
    }
}
