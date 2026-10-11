<?php

namespace App\Notifications;

use App\Actions\Auth\Staff\IssueStaffPasswordLinkAction;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link a staff member sets a password through (spec 020 R12): an invitation to a new
 * member, a recovery link, or the link after a re-enable. Staff-facing, English; the mail
 * carries the link only — never a password. Sent to the staff address, after commit.
 */
class StaffPasswordLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $link,
        public readonly string $origin,
        public readonly string $expiresAt,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $until = CarbonImmutable::parse($this->expiresAt)->setTimezone('Africa/Cairo')->format('j M Y, H:i');

        [$subject, $intro] = match ($this->origin) {
            IssueStaffPasswordLinkAction::INVITATION => [
                "You have been added to {$app}",
                "A {$app} dashboard account was created for you. Set your password to sign in.",
            ],
            IssueStaffPasswordLinkAction::ENABLED => [
                "Your {$app} account is enabled again",
                "Your {$app} dashboard account was enabled again. Set a new password to sign in.",
            ],
            default => [
                "Reset your {$app} password",
                "Someone asked to reset the password of your {$app} dashboard account. Use the link to set a new one.",
            ],
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($intro)
            ->action('Set your password', $this->link)
            ->line("The link works once and expires on {$until} (Cairo time).")
            ->line($this->origin === IssueStaffPasswordLinkAction::RECOVERY
                ? 'If this was not you, ignore this message: your password has not changed.'
                : 'If you did not expect this message, ignore it.');
    }
}
