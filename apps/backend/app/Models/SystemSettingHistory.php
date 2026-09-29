<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Change log for system settings (SEC-001).
 */
class SystemSettingHistory extends Model
{
    public $timestamps = false;

    protected $table = 'system_setting_histories';

    protected $fillable = [
        'system_setting_id',
        'key',
        'old_value',
        'new_value',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(SystemSetting::class, 'system_setting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
