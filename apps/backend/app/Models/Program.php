<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Academic program (STD-001 schema). Minimal canonical model (STD-003).
 */
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['department_id', 'code', 'name', 'education_level', 'status'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
