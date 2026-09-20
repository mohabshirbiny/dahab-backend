<?php

namespace App\Models;

use App\Enums\IdentityDocumentKind;
use App\Enums\IdentityDocumentStatus;
use Database\Factories\IdentityDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentityDocument extends Model
{
    /** @use HasFactory<IdentityDocumentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'identity_document';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** Status and reviewer columns are only ever written by the identity Actions. */
    protected $fillable = [
        'customer_id',
        'doc_kind',
        'front_ref',
        'back_ref',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_reasons',
        'review_note',
        'image_deleted_at',
    ];

    /** Object keys are internal details; no serialisation path may leak them. */
    protected $hidden = ['front_ref', 'back_ref'];

    protected function casts(): array
    {
        return [
            'doc_kind' => IdentityDocumentKind::class,
            'status' => IdentityDocumentStatus::class,
            'review_reasons' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'image_deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['document_id'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewed_by', 'staff_id');
    }

    public function viewLogs(): HasMany
    {
        return $this->hasMany(DocumentViewLog::class, 'document_id', 'document_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', IdentityDocumentStatus::PENDING->value);
    }

    /** A row is "open for review" when it is pending OR waiting for a resubmission. */
    public function scopeInReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IdentityDocumentStatus::PENDING->value,
            IdentityDocumentStatus::NEEDS_RESUBMISSION->value,
        ]);
    }
}
