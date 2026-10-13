<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The single identity of this platform installation (COM-02). A signed
 * license names the installation UUID it was issued for.
 */
class InstallationIdentity extends Model
{
    use HasUuids;

    protected $table = 'installation_identities';

    protected $fillable = ['uuid'];

    /** @var list<string> */
    protected $hidden = ['id'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }
}
