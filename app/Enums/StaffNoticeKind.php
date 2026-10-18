<?php

namespace App\Enums;

/**
 * The kinds of Dashboard notice (`staff_notice.kind`). Spec 021 writes the founder alerts;
 * spec 026 adds one work kind per queue (App\Support\Notices\WorkNotices): who is told (the
 * queue's permission, and for orders the order's branch), the staff-facing English text and
 * the Dashboard link. Texts carry only a public reference, never a name, phone, amount or ID
 * number (spec 026 FR-006).
 */
enum StaffNoticeKind: string
{
    /** Spec 021 research R11: a sensitive action on a founder account, or a founder security event. */
    case FOUNDER_ALERT = 'founder_alert';

    /** Spec 026 E1: an ID document was submitted (registration or a new attempt). */
    case WORK_IDENTITY = 'work_identity';

    /** Spec 026 E2: a customer sent a top-up notice. */
    case WORK_TOPUP = 'work_topup';

    /** Spec 026 E3: a listing was sent for review. */
    case WORK_LISTING = 'work_listing';

    /** Spec 026 E4: a seller accepted a buyer — a new order expects the piece at a branch. */
    case WORK_ORDER = 'work_order';

    /** Spec 026 E5: a dispute was opened on an order. */
    case WORK_DISPUTE = 'work_dispute';

    /** Spec 026 E6: a withdrawal was submitted. */
    case WORK_WITHDRAWAL = 'work_withdrawal';

    /** Spec 026 E7: a payout account was added. */
    case WORK_PAYOUT_ACCOUNT = 'work_payout_account';

    /** Spec 026 E8: a seller asked for more time on an order. */
    case WORK_EXTENSION = 'work_extension';

    /** Spec 026 E9: a listing was reported. */
    case WORK_LISTING_REPORT = 'work_listing_report';

    /** @return list<self> */
    public static function work(): array
    {
        return array_values(array_filter(self::cases(), fn (self $k) => $k !== self::FOUNDER_ALERT));
    }

    /** The permission whose holders are told (spec 026 R4). */
    public function permission(): ?StaffPermission
    {
        return match ($this) {
            self::FOUNDER_ALERT => null,
            self::WORK_IDENTITY => StaffPermission::IDENTITY_REVIEW,
            self::WORK_TOPUP => StaffPermission::TOPUP_MATCH,
            self::WORK_LISTING => StaffPermission::LISTING_REVIEW,
            self::WORK_ORDER => StaffPermission::ORDER_RECEIVE,
            self::WORK_DISPUTE => StaffPermission::DISPUTE_HANDLE,
            self::WORK_WITHDRAWAL => StaffPermission::WITHDRAWAL_RELEASE,
            self::WORK_PAYOUT_ACCOUNT => StaffPermission::PAYOUT_ACCOUNT_VERIFY,
            self::WORK_EXTENSION => StaffPermission::ORDER_EXTEND_DEADLINE,
            self::WORK_LISTING_REPORT => StaffPermission::LISTING_REPORT_HANDLE,
        };
    }

    /** Only staff assigned to the order's branch are told; staff with no branch are not (D26-3). */
    public function branchScoped(): bool
    {
        return $this === self::WORK_ORDER || $this === self::WORK_EXTENSION;
    }

    public function title(int $count): string
    {
        $one = $count === 1;

        return match ($this) {
            self::FOUNDER_ALERT => 'Security notice',
            self::WORK_IDENTITY => $one ? 'New ID document to review' : "{$count} new ID documents to review",
            self::WORK_TOPUP => $one ? 'New transfer notice' : "{$count} new transfer notices",
            self::WORK_LISTING => $one ? 'New listing to review' : "{$count} new listings to review",
            self::WORK_ORDER => $one ? 'New order for your branch' : "{$count} new orders for your branch",
            self::WORK_DISPUTE => $one ? 'New dispute' : "{$count} new disputes",
            self::WORK_WITHDRAWAL => $one ? 'New withdrawal to release' : "{$count} new withdrawals to release",
            self::WORK_PAYOUT_ACCOUNT => $one ? 'New payout account to check' : "{$count} new payout accounts to check",
            self::WORK_EXTENSION => $one ? 'Request for more time' : "{$count} requests for more time",
            self::WORK_LISTING_REPORT => $one ? 'New listing report' : "{$count} new listing reports",
        };
    }

    public function body(int $count, ?string $ref): string
    {
        if ($count > 1) {
            return match ($this) {
                self::WORK_ORDER => 'Sellers will bring these pieces to your branch. Open Orders to see them.',
                self::WORK_EXTENSION => 'Sellers asked for more time to bring their pieces. Open Orders to answer.',
                default => "{$count} arrived since you last read this notice.",
            };
        }

        return match ($this) {
            self::FOUNDER_ALERT => '',
            self::WORK_IDENTITY => 'A customer sent an ID document. It is waiting in Users and verification.',
            self::WORK_TOPUP => 'A customer says they sent a transfer'.($ref !== null ? " with the reference {$ref}" : '').'. Match it when it arrives.',
            self::WORK_LISTING => 'A seller sent a piece for review.',
            self::WORK_ORDER => 'Order '.($ref ?? '').' — the seller will bring the piece to your branch.',
            self::WORK_DISPUTE => 'A problem was reported'.($ref !== null ? " on order {$ref}" : '').'. The order is on hold.',
            self::WORK_WITHDRAWAL => 'Withdrawal '.($ref ?? '').' is waiting to be released.',
            self::WORK_PAYOUT_ACCOUNT => 'A customer added a bank account for withdrawals.',
            self::WORK_EXTENSION => 'The seller of order '.($ref ?? '').' asked for more time to bring the piece.',
            self::WORK_LISTING_REPORT => 'Report '.($ref ?? '').' is waiting to be handled.',
        };
    }

    /** The Dashboard page that works this queue, for a group of several items. */
    public function queueLink(): ?string
    {
        return match ($this) {
            self::FOUNDER_ALERT => null,
            self::WORK_IDENTITY => '/dashboard/users',
            self::WORK_TOPUP => '/dashboard/transfers',
            self::WORK_LISTING => '/dashboard/listings',
            self::WORK_ORDER, self::WORK_EXTENSION => '/dashboard/orders',
            self::WORK_DISPUTE => '/dashboard/disputes',
            self::WORK_WITHDRAWAL => '/dashboard/withdrawals',
            self::WORK_PAYOUT_ACCOUNT => '/dashboard/withdrawals?tab=accounts',
            self::WORK_LISTING_REPORT => '/dashboard/disputes?view=reports',
        };
    }

    /** The Dashboard path of one item, where the page opens one; otherwise the queue page. */
    public function itemLink(?string $itemId): ?string
    {
        if ($itemId === null) {
            return $this->queueLink();
        }

        $id = rawurlencode($itemId);

        return match ($this) {
            self::WORK_LISTING => "/dashboard/listings?id={$id}",
            self::WORK_ORDER, self::WORK_EXTENSION => "/dashboard/orders?id={$id}",
            self::WORK_DISPUTE => "/dashboard/disputes?id={$id}",
            self::WORK_WITHDRAWAL => "/dashboard/withdrawals?id={$id}",
            self::WORK_LISTING_REPORT => "/dashboard/disputes?view=reports&rid={$id}",
            default => $this->queueLink(),
        };
    }
}
