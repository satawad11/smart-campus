<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\AcademicStatus;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Academic department (STD-001 schema). Management APIs are STD-005.
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => AcademicStatus::class];
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }
}
