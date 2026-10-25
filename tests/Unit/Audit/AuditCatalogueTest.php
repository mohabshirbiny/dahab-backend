<?php

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;

// Spec 006 FR-006 / SC-002: every kind of recorded action has plain words and
// exactly one category. The enum's match arms have no default, so a new case
// without either fails here (UnhandledMatchError) before release.

it('gives every audit event a label and a category', function (AuditEvent $event) {
    expect($event->label())->toBeString()->not->toBeEmpty()
        ->and($event->label())->not->toBe($event->value)
        ->and($event->category())->toBeInstanceOf(AuditCategory::class);
})->with(AuditEvent::cases());

it('labels every category and keeps sessions out of Everything', function () {
    foreach (AuditCategory::cases() as $category) {
        expect($category->label())->not->toBeEmpty()
            ->and($category->inEverything())->toBe($category !== AuditCategory::SESSIONS);
    }
});

it('lists the codes of a category', function () {
    expect(AuditEvent::codesIn(AuditCategory::PRICING))->toBe([
        'pricing.setting.changed', 'pricing.adjustment.changed', 'pricing.manual_price.entered', 'pricing.manual_price.confirmed',
    ])
        // Spec 008: the wallet statement reads; spec 009: top-ups and receiving accounts; spec 013: withdrawals;
        // spec 014: compensation; spec 015: finance operations; spec 016: tax invoices.
        ->and(AuditEvent::codesIn(AuditCategory::MONEY))->toBe([
            'ledger.statement.viewed', 'ledger.statement.exported', 'wallet.statement.downloaded',
            'topup.matched', 'topup.credited_by_hand', 'topup.held', 'topup.unheld', 'topup.rejected', 'topup.list_exported',
            'receiving_account.created', 'receiving_account.updated',
            'withdrawal.email_confirmed', 'withdrawal.requested', 'withdrawal.cancelled', 'withdrawal.taken_for_review',
            'withdrawal.held', 'withdrawal.unheld', 'withdrawal.released', 'withdrawal.rejected', 'withdrawal.list_exported',
            'withdrawal.pause_ended', 'compensation.paid',
            'compensation.list_exported', 'wallet.adjusted', 'bank.movement_recorded', 'bank.movement_proof_viewed',
            'bank.book_exported', 'bank.movements_exported', 'day.closed', 'day.saved',
            'credit_note.issued', 'invoice.document_viewed', 'credit_note.document_viewed', 'invoices.exported',
            // Spec 023: the first-sale advance.
            'first_sale.enabled', 'first_sale.revoked', 'first_sale.advanced', 'first_sale.skipped',
            'first_sale.recovered', 'first_sale.written_off',
        ])
        ->and(AuditEvent::AUDIT_LOG_EXPORTED->category())->toBe(AuditCategory::SYSTEM);
});

// Spec 021: the founder security events sit under Accounts, and the founder-alert set is the
// Story 3 list plus the founder security events (research R11; plan Q2: nothing more).
it('files the founder security events under Accounts and lists the founder alerts', function () {
    foreach (AuditEvent::cases() as $event) {
        if (str_starts_with($event->value, 'founder.')) {
            expect($event->category())->toBe(AuditCategory::ACCOUNTS);
        }
    }

    expect(array_map(fn (AuditEvent $e) => $e->value, AuditEvent::founderSensitive()))->toBe([
        'wallet.adjusted', 'pricing.setting.changed', 'pricing.adjustment.changed', 'promo.created', 'promo.updated', 'promo.deleted',
        'pricing.manual_price.entered', 'pricing.manual_price.confirmed', 'authz.staff.created', 'authz.staff.disabled',
    ])
        ->and(array_map(fn (AuditEvent $e) => $e->value, AuditEvent::founderSecurity()))->toBe([
            'founder.device_approval_requested', 'founder.device_approved', 'founder.device_refused',
            'founder.device_trusted_by_console', 'founder.frozen', 'founder.unfreeze_confirmed', 'founder.unfrozen',
        ])
        ->and(AuditEvent::founderAlert())->toHaveCount(17)
        ->and(in_array(AuditEvent::STAFF_ENABLED, AuditEvent::founderAlert(), true))->toBeFalse();
});
