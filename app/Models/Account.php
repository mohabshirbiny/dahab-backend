<?php

namespace App\Models;

use App\Enums\AccountKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A bucket money sits in (spec 008, `account`). Customer accounts are
 * created by the database when the customer is; the internal singletons are
 * seeded by the ledger migration. Balances are never stored.
 *
 * @property string $account_id
 * @property AccountKind $kind
 * @property string|null $customer_id
 */
class Account extends Model
{
    protected $table = 'account';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @var array<string, string> kind => account_id, per process */
    private static array $internal = [];

    protected function casts(): array
    {
        return [
            'kind' => AccountKind::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** The id of Dahab's one account of this internal kind. */
    public static function internal(AccountKind $kind): string
    {
        if ($kind->isCustomer()) {
            throw new LogicException("[{$kind->value}] is a customer account kind.");
        }

        return self::$internal[$kind->value] ??= (string) self::query()
            ->where('kind', $kind->value)->whereNull('customer_id')->valueOrFail('account_id');
    }

    /** The id of a customer's account of this kind. */
    public static function forCustomerKind(string $customerId, AccountKind $kind): string
    {
        return (string) self::query()->where('customer_id', $customerId)->where('kind', $kind->value)->valueOrFail('account_id');
    }

    /** Forget cached internal ids (tests rebuild the database). */
    public static function flushInternalCache(): void
    {
        self::$internal = [];
    }

    public function scopeForCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'account_id', 'account_id');
    }
}
