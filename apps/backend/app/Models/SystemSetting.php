<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SystemSetting model (SEC-001).
 *
 * @property int $id
 * @property string $key
 * @property string $group
 * @property string $type
 * @property string|null $value
 * @property string|null $description
 * @property bool $is_public
 */
class SystemSetting extends Model
{
    use HasFactory;

    protected $table = 'system_settings';

    protected $fillable = [
        'key',
        'group',
        'type',
        'value',
        'description',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function histories(): HasMany
    {
        return $this->hasMany(SystemSettingHistory::class, 'system_setting_id');
    }

    /**
     * Get value cast to its declared PHP type.
     */
    public function getTypedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => (string) $this->value,
        };
    }

    /**
     * Serialize a typed value for persistence.
     */
    public function setTypedValue(mixed $val): void
    {
        if ($val === null) {
            $this->value = null;

            return;
        }

        $this->value = match ($this->type) {
            'boolean' => $val ? 'true' : 'false',
            'json' => is_string($val) ? $val : json_encode($val),
            default => (string) $val,
        };
    }
}
