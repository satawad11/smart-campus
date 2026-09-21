<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\DuplicateReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FaceDuplicateReview model (FACE-011).
 *
 * Represents a human review workflow item for potential duplicate face enrollments.
 *
 * @property int $id
 * @property string $uuid
 * @property int $source_face_profile_id
 * @property int $candidate_face_profile_id
 * @property int $recognition_model_id
 * @property float $distance
 * @property float $similarity
 * @property DuplicateReviewStatus $review_status
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FaceDuplicateReview extends Model
{
    use HasFactory, HasPublicUuid;

    protected $table = 'face_duplicate_reviews';

    /** @var list<string> */
    protected $fillable = [
        'source_face_profile_id',
        'candidate_face_profile_id',
        'recognition_model_id',
        'distance',
        'similarity',
        'review_status',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'distance' => 'float',
            'similarity' => 'float',
            'review_status' => DuplicateReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(FaceProfile::class, 'source_face_profile_id');
    }

    public function candidateProfile(): BelongsTo
    {
        return $this->belongsTo(FaceProfile::class, 'candidate_face_profile_id');
    }

    public function recognitionModel(): BelongsTo
    {
        return $this->belongsTo(AIModel::class, 'recognition_model_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
