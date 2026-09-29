<?php

namespace App\Models;

use App\Enums\TopUpMethod;
use Database\Factories\ReceivingAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of Dahab's accounts that customers send money to (spec 009 US4).
 * Reference data managed from the Dashboard; deactivated, never deleted.
 * `daily_limit` and `provider_fee_percent` are display-only.
 *
 * @property int $receiving_account_id
 * @property TopUpMethod $method
 * @property string $label
 * @property bool $is_active
 */
class ReceivingAccount extends Model
{
    /** @use HasFactory<ReceivingAccountFactory> */
    use HasFactory;

    protected $table = 'receiving_account';

    protected $primaryKey = 'receiving_account_id';

    protected $guarded = ['receiving_account_id'];

    protected function casts(): array
    {
        return [
            'method' => TopUpMethod::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Customer display order: method, then sort order, then id. */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('method')->orderBy('sort_order')->orderBy('receiving_account_id');
    }

    /**
     * The details customers copy, in the contract's order for the method;
     * unset optional details are left out.
     *
     * @return list<array{key: string, value: string}>
     */
    public function detailRows(): array
    {
        $rows = [];
        foreach ($this->method->detailKeys() as $key) {
            $value = $this->getAttribute($key);
            if ($value !== null && $value !== '') {
                $rows[] = ['key' => $key, 'value' => (string) $value];
            }
        }

        return $rows;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by', 'staff_id');
    }
}
