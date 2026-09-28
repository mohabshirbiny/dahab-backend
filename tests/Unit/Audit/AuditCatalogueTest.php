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
        // Spec 008: the first money events are the wallet statement reads.
        ->and(AuditEvent::codesIn(AuditCategory::MONEY))->toBe(['ledger.statement.viewed', 'ledger.statement.exported'])
        ->and(AuditEvent::AUDIT_LOG_EXPORTED->category())->toBe(AuditCategory::SYSTEM);
});
