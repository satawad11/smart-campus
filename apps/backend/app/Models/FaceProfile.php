<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\FaceProfileStatus;
use Database\Factories\FaceProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * FaceProfile model (FACE-002, database blueprint §5).
 *
 * Represents the biometric enrollment identity and lifecycle of a Person.
 *
 * Invariants:
 * - Biometric identity belongs to Person (person_id), NOT StudentProfile or User.
 * - Public identity via UUID only.
 * - No direct access decision or door actuator properties.
 *
 * @property int $id
 * @property string $uuid
 * @property int $person_id
 * @property int|null $recognition_model_id
 * @property int|null $embedding_dimension
 * @property float|null $quality_score
 * @property FaceProfileStatus $status
 * @property int|null $enrolled_by_user_id
 * @property Carbon|null $enrolled_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FaceProfile extends Model
{
    /** @use HasFactory<FaceProfileFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'face_profiles';

    /** @var list<string> */
    protected $fillable = [
        'person_id',
        'recognition_model_id',
        'embedding',
        'embedding_dimension',
        'quality_score',
        'status',
        'enrolled_by_user_id',
        'enrolled_at',
        'revoked_at',
        'revocation_reason',
    ];

    /** @var list<string> */
    protected $hidden = [
        'embedding',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FaceProfileStatus::class,
            'embedding_dimension' => 'integer',
            'quality_score' => 'float',
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function recognitionModel(): BelongsTo
    {
        return $this->belongsTo(AIModel::class, 'recognition_model_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(FaceSample::class);
    }
}
