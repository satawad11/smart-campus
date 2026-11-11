<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AIModelStatus;
use App\Enums\AIModelType;
use Database\Factories\AIModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AI Model Registry model (FACE-001, database blueprint §5).
 *
 * Stores metadata and artifact references for perception and biometric AI models.
 * Model binaries are strictly stored in S3-compatible object storage, never in PostgreSQL.
 *
 * Invariants:
 * - Public identity via UUID only.
 * - (code, version) unique pair identifies an exact immutable artifact.
 * - No person_id or student_profile_id foreign keys.
 * - No access decisions or door actuator properties.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property AIModelType $model_type
 * @property string $version
 * @property string|null $provider
 * @property string|null $artifact_path
 * @property string|null $model_checksum
 * @property int|null $embedding_dimension
 * @property string|null $distance_metric
 * @property float|null $threshold_high
 * @property float|null $threshold_low
 * @property AIModelStatus $status
 * @property bool $is_default
 * @property array<string, mixed>|null $config
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $deployed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AIModel extends Model
{
    /** @use HasFactory<AIModelFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'ai_models';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'model_type',
        'version',
        'provider',
        'artifact_path',
        'model_checksum',
        'embedding_dimension',
        'distance_metric',
        'threshold_high',
        'threshold_low',
        'status',
        'is_default',
        'config',
        'metadata',
        'deployed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_type' => AIModelType::class,
            'status' => AIModelStatus::class,
            'embedding_dimension' => 'integer',
            'threshold_high' => 'float',
            'threshold_low' => 'float',
            'is_default' => 'boolean',
            'config' => 'array',
            'metadata' => 'array',
            'deployed_at' => 'datetime',
        ];
    }
}
