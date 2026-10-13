<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permanent revocation of one license_id, imported from a signed
 * revocation list (COM-02).
 */
class LicenseRevocation extends Model
{
    protected $table = 'license_revocations';

    /** @var list<string> */
    protected $hidden = ['id'];

    protected $fillable = ['license_id', 'list_sequence', 'list_issued_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list_sequence' => 'integer',
            'list_issued_at' => 'immutable_datetime',
        ];
    }
}
