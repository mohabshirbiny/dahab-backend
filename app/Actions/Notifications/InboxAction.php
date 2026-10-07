<?php

namespace App\Actions\Notifications;

use App\Models\CustomerNotification;
use App\Support\Account\InboxCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A customer's inbox (spec 017 FR-032, FR-035): newest first, keyset paged,
 * the unread count, and marking read — only `read_at` ever changes (DH014).
 * The customer reads their own (forced RLS); staff read any customer's in the
 * Customer file, read-only.
 */
final class InboxAction
{
    /** @return array{items: Collection<int, CustomerNotification>, next_cursor: ?string, unread_count: int} */
    public function page(string $customerId, ?string $cursor, bool $unreadOnly = false, int $perPage = 20): array
    {
        $after = InboxCursor::decode($cursor);

        $items = $this->of($customerId)
            ->when($unreadOnly, fn (Builder $q) => $q->whereNull('read_at'))
            ->when($after !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('created_at', '<', $after->at->format('Y-m-d H:i:s.uP'))
                ->orWhere(fn (Builder $x) => $x->where('created_at', $after->at->format('Y-m-d H:i:s.uP'))->where('notification_id', '<', $after->id))))
            ->orderByDesc('created_at')->orderByDesc('notification_id')
            ->limit($perPage + 1)->get();

        $more = $items->count() > $perPage;
        $items = $items->take($perPage)->values();
        $last = $items->last();

        return [
            'items' => $items,
            'next_cursor' => $more && $last !== null ? (new InboxCursor($last->created_at, $last->notification_id))->encode() : null,
            'unread_count' => $this->unreadCount($customerId),
        ];
    }

    public function unreadCount(string $customerId): int
    {
        return $this->of($customerId)->whereNull('read_at')->count();
    }

    public function markRead(string $customerId, string $id): CustomerNotification
    {
        $item = $this->of($customerId)->whereKey($id)->first() ?? throw new ModelNotFoundException;
        if ($item->read_at === null) {
            $this->of($customerId)->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
            $item->refresh();
        }

        return $item;
    }

    public function markAllRead(string $customerId): int
    {
        return $this->of($customerId)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @return Builder<CustomerNotification> */
    private function of(string $customerId): Builder
    {
        return CustomerNotification::query()->where('customer_id', $customerId);
    }
}
