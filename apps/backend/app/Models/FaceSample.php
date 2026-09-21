<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\FaceSamplePose;
use App\Enums\FaceSampleSource;
use Database\Factories\FaceSampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FaceSample model (FACE-002, database blueprint §5).
 *
 * Stores raw/processed biometric enrollment sample metadata associated with a FaceProfile.
 * Images are stored in private object storage, never in PostgreSQL.
 *
 * Invariants:
 * - Public identity via UUID only.
 * - Stored image_path is a relative object-storage key.
 * - Zero pgvector embedding columns.
 *
 * @property int $id
 * @property string $uuid
 * @property int $face_profile_id
 * @property string $image_path
 * @property FaceSamplePose $pose
 * @property FaceSampleSource $source
 * @property float|null $quality_score
 * @property float|null $blur_score
 * @property float|null $brightness_score
 * @property float|null $face_size_score
 * @property float|null $yaw
 * @property float|null $pitch
 * @property float|null $roll
 * @property bool $accepted
 * @property string|null $rejection_reason
 * @property string|null $checksum
 * @property int|null $width
 * @property int|null $height
 * @property string|null $mime_type
 * @property Carbon|null $captured_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FaceSample extends Model
{
    /** @use HasFactory<FaceSampleFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'face_samples';

    /** @var list<string> */
    protected $fillable = [
        'face_profile_id',
        'image_path',
        'pose',
        'source',
        'quality_score',
        'blur_score',
        'brightness_score',
        'face_size_score',
        'yaw',
        'pitch',
        'roll',
        'accepted',
        'rejection_reason',
        'checksum',
        'width',
        'height',
        'mime_type',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pose' => FaceSamplePose::class,
            'source' => FaceSampleSource::class,
            'quality_score' => 'float',
            'blur_score' => 'float',
            'brightness_score' => 'float',
            'face_size_score' => 'float',
            'yaw' => 'float',
            'pitch' => 'float',
            'roll' => 'float',
            'accepted' => 'boolean',
            'width' => 'integer',
            'height' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function faceProfile(): BelongsTo
    {
        return $this->belongsTo(FaceProfile::class);
    }
}
