<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Enums\IdDocumentType;
use Database\Factories\VisitorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visitor or contractor profile (Module 09, PPL-002). Only the last 4 characters of
 * an identity document are kept (PDPA minimisation).
 */
class VisitorProfile extends Model
{
    /** @use HasFactory<VisitorProfileFactory> */
    use HasFactory, HasPublicUuid;

    protected $table = 'visitor_profiles';

    /** @var list<string> */
    protected $fillable = [
        'person_id',
        'organization',
        'phone',
        'id_document_type',
        'id_document_last4',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id_document_type' => IdDocumentType::class,
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
