<?php

namespace Tests\Support;

use App\Enums\AccountEvent;
use App\Enums\BuyRequestEvent;
use App\Enums\ListingDecision;
use App\Enums\OrderEvent;
use App\Enums\PayoutEvent;
use App\Enums\TopUpRejectReason;
use App\Enums\WalletEvent;
use App\Models\Customer;
use App\Notifications\AccountNotification;
use App\Notifications\BuyRequestNotification;
use App\Notifications\Contracts\InboxNotification;
use App\Notifications\CustomerLoginOtpNotification;
use App\Notifications\CustomerRegistrationEmailOtpNotification;
use App\Notifications\CustomerRegistrationOtpNotification;
use App\Notifications\CustomerRegistrationSubmittedNotification;
use App\Notifications\CustomerVerificationRejectedNotification;
use App\Notifications\CustomerVerificationResubmissionNotification;
use App\Notifications\CustomerVerifiedNotification;
use App\Notifications\EmailChangeLinkNotification;
use App\Notifications\ListingDecisionNotification;
use App\Notifications\ListingReportNotification;
use App\Notifications\OrderNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\PayoutNotification;
use App\Notifications\PhoneChangeCodeNotification;
use App\Notifications\ProxyNamedNotification;
use App\Notifications\TopUpCreditedNotification;
use App\Notifications\TopUpRejectedNotification;
use App\Notifications\WalletNotification;
use App\Notifications\WithdrawalConfirmationNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Every customer message of the platform with fixed inputs (spec 019 T041): the SMS sentence,
 * the email (subject, lines, action) and the inbox item, in both languages. The golden test
 * renders them all and compares with `tests/Fixtures/notification-golden.json`, which was
 * written from the code *before* any text moved into the template catalogue — so the
 * catalogue and the renderer are proven to change nothing until staff publish an override.
 */
final class NotificationCases
{
    private const PIECE = 'Ring 21K';

    private const PIECE_AR = 'خاتم عيار 21';

    private const DEADLINE = '2026-10-12T10:30:00+03:00';

    /** @return array<string, \Closure(string): Notification> id => builder(lang) */
    public static function all(): array
    {
        $c = [];

        // OrderNotification: every event, fully filled — then the optional pieces left out.
        foreach (OrderEvent::cases() as $e) {
            $c["order.{$e->value}.full"] = fn (string $lang) => new OrderNotification($e, 'DH-2026-000042', self::PIECE, self::PIECE_AR, '58200.5', self::DEADLINE, 'Nasr City', 'مدينة نصر', '482913', 'Please bring the original invoice');
            $c["order.{$e->value}.bare"] = fn (string $lang) => new OrderNotification($e, 'DH-2026-000042', self::PIECE, self::PIECE_AR, null, null, null, null, null, 'Reply text');
        }

        foreach (BuyRequestEvent::cases() as $e) {
            $c["buy_request.{$e->value}.full"] = fn (string $lang) => new BuyRequestNotification($e, self::PIECE, self::PIECE_AR, '5820.0', self::DEADLINE, 'DH-2026-000042', 'Maadi', 'المعادي', 'Piece did not match');
            $c["buy_request.{$e->value}.bare"] = fn (string $lang) => new BuyRequestNotification($e, self::PIECE, self::PIECE_AR, null, self::DEADLINE, null, null, null, 'No reason');
        }

        foreach (AccountEvent::cases() as $e) {
            $c["account.{$e->value}.plain"] = fn (string $lang) => new AccountNotification($e, $lang, '+201012345678');
            $c["account.{$e->value}.stopped"] = fn (string $lang) => new AccountNotification($e, $lang, 'a@example.test', self::DEADLINE, 2);
            $c["account.{$e->value}.paused"] = fn (string $lang) => new AccountNotification($e, $lang, 'Chrome on Windows', self::DEADLINE, 0);
        }

        foreach (PayoutEvent::cases() as $e) {
            $c["payout.{$e->value}.full"] = fn (string $lang) => new PayoutNotification($e, 'CIB •••• 4417', '1000.0', 'WD-12', self::DEADLINE, 'Name mismatch', 'الاسم مش مطابق', 'Please call us', 3);
            $c["payout.{$e->value}.bare"] = fn (string $lang) => new PayoutNotification($e, 'CIB •••• 4417', '1000.0', 'WD-12', null, 'Name mismatch', 'الاسم مش مطابق', 'Please call us', 0);
        }

        foreach (ListingDecision::cases() as $d) {
            $c["listing.{$d->value}.message"] = fn (string $lang) => new ListingDecisionNotification($d, self::PIECE, self::PIECE_AR, 'Please add a clearer photo.');
            $c["listing.{$d->value}.bare"] = fn (string $lang) => new ListingDecisionNotification($d, self::PIECE, self::PIECE_AR);
        }

        $c['wallet.compensation_paid'] = fn (string $lang) => new WalletNotification(WalletEvent::COMPENSATION_PAID, '250.0', null, 'A wasted trip', 'مشوار ضايع');
        $c['wallet.adjusted_credit'] = fn (string $lang) => new WalletNotification(WalletEvent::WALLET_ADJUSTED, '75.5', 'credit');
        $c['wallet.adjusted_debit'] = fn (string $lang) => new WalletNotification(WalletEvent::WALLET_ADJUSTED, '75.5', 'debit');
        $c['wallet.credit_note'] = fn (string $lang) => new WalletNotification(WalletEvent::CREDIT_NOTE_ISSUED, '98.0', null, null, null, 'DH-2026-000042-S');

        $c['customer.verified'] = fn (string $lang) => new CustomerVerifiedNotification;
        $c['customer.registration_submitted'] = fn (string $lang) => new CustomerRegistrationSubmittedNotification;
        $c['customer.verification_rejected.note'] = fn (string $lang) => new CustomerVerificationRejectedNotification('Blurry photo', ['ID unreadable', 'Name mismatch']);
        $c['customer.verification_rejected.bare'] = fn (string $lang) => new CustomerVerificationRejectedNotification(null, []);
        $c['customer.resubmission.note'] = fn (string $lang) => new CustomerVerificationResubmissionNotification(['Back of the ID missing'], 'Send both sides');
        $c['customer.resubmission.bare'] = fn (string $lang) => new CustomerVerificationResubmissionNotification(['Back of the ID missing'], null);

        $c['topup.credited'] = fn (string $lang) => new TopUpCreditedNotification('1500.0', 'DAHAB-000123');
        foreach (TopUpRejectReason::cases() as $r) {
            $c["topup.rejected.{$r->value}"] = fn (string $lang) => new TopUpRejectedNotification($r, 'DAHAB-000123');
        }

        $c['listing_report.reviewed'] = fn (string $lang) => new ListingReportNotification('RPT-17');
        $c['proxy.named'] = fn (string $lang) => new ProxyNamedNotification('Karim', self::PIECE, self::PIECE_AR, 'Nasr City', 'مدينة نصر', $lang === 'ar');

        $c['auth.login_otp'] = fn (string $lang) => new CustomerLoginOtpNotification('123456', $lang);
        $c['auth.registration_otp'] = fn (string $lang) => new CustomerRegistrationOtpNotification('123456', $lang);
        $c['auth.registration_email_otp'] = fn (string $lang) => new CustomerRegistrationEmailOtpNotification('123456', $lang);
        $c['auth.phone_change_code'] = fn (string $lang) => new PhoneChangeCodeNotification('654321', $lang);
        $c['auth.password_reset_code'] = fn (string $lang) => new PasswordResetCodeNotification('654321', $lang);
        $c['auth.email_change_link'] = fn (string $lang) => new EmailChangeLinkNotification('https://app.example.test/email-confirm?t=abc', $lang, 30);
        $c['withdrawal.confirmation_link'] = fn (string $lang) => new WithdrawalConfirmationNotification('https://app.example.test/withdraw-confirm?t=abc', '1000.0', 'CIB •••• 4417', 30);

        return $c;
    }

    /**
     * What one message says in one language: SMS, email and inbox.
     *
     * @return array<string, mixed>
     */
    public static function capture(Notification $n, string $lang): array
    {
        $customer = (new Customer)->forceFill(['preferred_lang' => $lang, 'email' => 'a@example.test', 'display_ref' => '000123']);
        $out = [];

        if (method_exists($n, 'toMail')) {
            try {
                $mail = $n->toMail($customer);
                if ($mail instanceof MailMessage) {
                    $out['mail'] = [
                        'subject' => $mail->subject,
                        'intro' => $mail->introLines,
                        'outro' => $mail->outroLines,
                        'action' => $mail->actionText === null ? null : [$mail->actionText, $mail->actionUrl],
                    ];
                }
            } catch (Throwable $e) {
                $out['mail_error'] = $e::class;
            }
        }

        if (method_exists($n, 'toSms')) {
            try {
                $sms = $n->toSms($customer);
                $out['sms'] = is_string($sms) ? $sms : $sms->content;
            } catch (Throwable $e) {
                $out['sms_error'] = $e::class;
            }
        }

        if ($n instanceof InboxNotification) {
            try {
                $inbox = $n->toInbox($customer);
                $out['inbox'] = $inbox === null ? null : [
                    'type' => $inbox->type, 'title_en' => $inbox->titleEn, 'title_ar' => $inbox->titleAr,
                    'body_en' => $inbox->bodyEn, 'body_ar' => $inbox->bodyAr,
                ];
            } catch (Throwable $e) {
                $out['inbox_error'] = $e::class;
            }
        }

        return $out;
    }

    /** @return array<string, array{en: array<string, mixed>, ar: array<string, mixed>}> */
    public static function render(): array
    {
        $all = [];
        foreach (self::all() as $id => $build) {
            $all[$id] = ['en' => self::capture($build('en'), 'en'), 'ar' => self::capture($build('ar'), 'ar')];
        }

        return $all;
    }
}
