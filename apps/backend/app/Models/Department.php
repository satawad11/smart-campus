<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Academic department (STD-001 schema). Minimal canonical model introduced in
 * STD-003 to support StudentProfile relationships; management APIs are STD-005.
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'status'];
}
