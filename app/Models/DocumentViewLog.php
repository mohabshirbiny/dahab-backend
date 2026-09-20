<?php

namespace App\Models;

use Database\Factories\DocumentViewLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per staff view of an identity document. Append-only: the database
 * trigger `docview_no_update` refuses UPDATE/DELETE, and the model refuses
 * them before a query is even built.
 */
class DocumentViewLog extends Model
{
    /** @use HasFactory<DocumentViewLogFactory> */
    use HasFactory;

    protected $table = 'document_view_log';

    protected $primaryKey = 'view_id';

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'viewed_by',
        'viewed_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('document_view_log is append-only.'));
        static::deleting(fn () => throw new LogicException('document_view_log is append-only.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IdentityDocument::class, 'document_id', 'document_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'viewed_by', 'staff_id');
    }
}
