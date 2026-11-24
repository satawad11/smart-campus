<?php

namespace App\Services\Event;

use App\Enums\AccessDecisionOutcome;
use App\Enums\AuditAction;
use App\Exceptions\ApiException;
use App\Models\AccessDecision;
use App\Models\AccessEvent;
use App\Models\AiNode;
use App\Models\User;
use App\Services\Audit\AuditRequestContext;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Event face crop (EVT-025, ACR-EVT-24 approved 2026-11-20).
 *
 * - store(): the AI node that delivered an UNKNOWN / REVIEW / DENY event may
 *   attach ONE bounded JPEG face crop, soon after the event, to the private
 *   event-snapshots disk. The key goes into the existing
 *   AccessEvent.snapshot_path, which no ordinary response exposes.
 * - read(): returns the bytes to an already authorized and scope-checked
 *   caller, and audits every successful view.
 * - purge(): retention removes the object and clears the reference.
 *
 * The crop is evidence for an operator. It never changes the event, its
 * decision, or any identity, and is never used for matching.
 */
class EventSnapshotService
{
    private const ELIGIBLE = [
        AccessDecisionOutcome::REVIEW,
        AccessDecisionOutcome::DENY,
        AccessDecisionOutcome::UNKNOWN,
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('event.snapshot.disk', 'event_snapshots'));
    }

    public function store(AiNode $node, string $eventUuid, string $bytes): AccessEvent
    {
        $event = Str::isUuid($eventUuid) ? AccessEvent::where('uuid', $eventUuid)->first() : null;

        // Another node's event answers exactly like a missing one, so a node
        // credential cannot probe which event uuids exist.
        if ($event === null || $event->ai_node_id !== $node->id) {
            throw new ApiException('EVENT_NOT_FOUND', 'The specified event does not exist.', 404);
        }

        if ($event->camera === null || $event->camera->ai_node_id !== $node->id) {
            throw ApiException::unprocessable('CAMERA_NOT_ASSIGNED_TO_NODE', 'This AI node is not assigned to the event camera.');
        }

        $systemDecision = AccessDecision::where('event_id', $event->id)->orderBy('id')->first();
        if ($systemDecision === null || ! in_array($systemDecision->decision, self::ELIGIBLE, true)) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_NOT_ELIGIBLE', 'A face image is kept only for UNKNOWN, REVIEW and DENY events.');
        }

        $window = (int) config('event.snapshot.upload_window_seconds', 600);
        if ($event->received_at === null || $event->received_at->lt(now()->subSeconds($window))) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_WINDOW_EXPIRED', 'The face image must be sent soon after the event.');
        }

        if ($event->snapshot_path !== null) {
            throw ApiException::conflict('EVENT_SNAPSHOT_ALREADY_STORED', 'This event already has a face image.');
        }

        [$width, $height] = $this->assertFaceCrop($bytes);

        $path = sprintf('%s/%s-%s.jpg', $event->occurred_at->format('Y/m/d'), $event->uuid, Str::lower(Str::random(8)));

        try {
            $stored = $this->disk()->put($path, $bytes);
        } catch (Throwable) {
            $stored = false;
        }
        if (! $stored) {
            throw new ApiException('EVENT_SNAPSHOT_STORAGE_UNAVAILABLE', 'The face image could not be stored. Try again later.', 503);
        }

        // Conditional update: two concurrent uploads cannot both attach.
        $attached = AccessEvent::whereKey($event->id)->whereNull('snapshot_path')->update(['snapshot_path' => $path]);
        if ($attached === 0) {
            $this->deleteObject($path);
            throw ApiException::conflict('EVENT_SNAPSHOT_ALREADY_STORED', 'This event already has a face image.');
        }

        $this->audit->record(
            AuditAction::EVENT_SNAPSHOT_STORED, null, 'ACCESS_EVENT', $event->uuid,
            newValues: ['bytes' => strlen($bytes), 'width' => $width, 'height' => $height],
            metadata: ['ai_node_uuid' => $node->uuid],
            module: 'event',
        );

        return $event->refresh();
    }

    /**
     * Bytes of the event's face crop. The caller has already checked the
     * permission and the event's site/zone scope. Every successful read is audited.
     */
    public function read(AccessEvent $event, User $viewer, ?AuditRequestContext $context): string
    {
        if ($event->snapshot_path === null) {
            throw new ApiException('EVENT_SNAPSHOT_NOT_AVAILABLE', 'This event has no face image.', 404);
        }

        try {
            $bytes = $this->disk()->get($event->snapshot_path);
        } catch (Throwable) {
            $bytes = null;
        }
        if ($bytes === null || $bytes === '') {
            throw new ApiException('EVENT_SNAPSHOT_NOT_AVAILABLE', 'This event has no face image.', 404);
        }

        $this->audit->record(
            AuditAction::EVENT_SNAPSHOT_VIEWED, $viewer, 'ACCESS_EVENT', $event->uuid,
            metadata: ['camera_uuid' => $event->camera?->uuid],
            context: $context,
            module: 'event',
        );

        return $bytes;
    }

    /**
     * Retention: delete the object (if still present) and clear the reference.
     */
    public function purge(AccessEvent $event): void
    {
        if ($event->snapshot_path === null) {
            return;
        }
        $this->deleteObject($event->snapshot_path);
        AccessEvent::whereKey($event->id)->update(['snapshot_path' => null]);
    }

    public function deleteObject(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable) {
            // A missing object is already the desired end state.
        }
    }

    /**
     * A real JPEG within the configured byte and dimension bounds: a face
     * crop, never a full camera frame.
     *
     * @return array{0:int,1:int}
     */
    private function assertFaceCrop(string $bytes): array
    {
        $max = (int) config('event.snapshot.max_bytes', 262144);
        if ($bytes === '' || strlen($bytes) > $max) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_TOO_LARGE', "The face image must be at most {$max} bytes.");
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_INVALID_IMAGE', 'The face image must be a JPEG.');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];
        $maxSide = (int) config('event.snapshot.max_dimension', 640);
        $minSide = (int) config('event.snapshot.min_dimension', 32);
        if ($width > $maxSide || $height > $maxSide) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_TOO_LARGE', "A face crop may be at most {$maxSide}x{$maxSide} pixels; full frames are not accepted.");
        }
        if ($width < $minSide || $height < $minSide) {
            throw ApiException::unprocessable('EVENT_SNAPSHOT_INVALID_IMAGE', "The face image must be at least {$minSide}x{$minSide} pixels.");
        }

        return [$width, $height];
    }
}
