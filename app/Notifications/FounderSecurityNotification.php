<?php

namespace App\Notifications;

use App\Enums\StaffNoticeKind;
use App\Models\Staff;
use App\Notifications\Channels\StaffNoticeChannel;
use App\Notifications\Contracts\StaffNoticeNotification;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec 021 research R11 (Story 3, D21-3): every active founder is told of a sensitive action on
 * a founder account and of every founder security event — by email and a Dashboard notice, and
 * by SMS too for a freeze. Staff-facing English, like FounderAlertNotification.
 *
 * Built by App\Support\FounderSecurity\FounderAlerts from the audit row. It carries only what
 * happened, who, when, the IP, the device ref (8 hex characters of the fingerprint hash), the
 * platform for a device request, and a Dashboard link — never a password, code, poll ref,
 * device id, amount or setting value. The detail lives in the audit entry the link opens.
 */
class FounderSecurityNotification extends Notification implements ShouldQueue, StaffNoticeNotification
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array{event: string, label: string, actor: string, at: string, ip: ?string, device_ref: ?string, platform: ?string, entity_type: ?string, audit_id: ?int}  $facts
     */
    public function __construct(
        public readonly array $facts,
        public readonly string $link,
        public readonly bool $sms = false,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            'mail',
            StaffNoticeChannel::class,
            $this->sms ? 'sms' : null,
        ]));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Dahab security: '.$this->facts['label'])
            ->line($this->sentence());

        foreach ($this->details() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action($this->isDeviceRequest() ? 'Open Security' : 'Open the audit entry', $this->url())
            ->line('If you did not expect this, call the other founder now.');
    }

    public function toStaffNotice(Staff $notifiable): ?array
    {
        return [
            'kind' => StaffNoticeKind::FOUNDER_ALERT->value,
            'severity' => $this->severity(),
            'title' => $this->facts['label'],
            'body' => trim($this->sentence().' '.implode(' · ', $this->details())),
            'params' => array_filter([
                'event' => $this->facts['event'],
                'actor' => $this->facts['actor'],
                'device_ref' => $this->facts['device_ref'],
                'platform' => $this->facts['platform'],
            ], fn ($v) => $v !== null),
            'link' => $this->link,
            'audit_id' => $this->facts['audit_id'],
        ];
    }

    public function toSms(mixed $notifiable): SmsMessage
    {
        return new SmsMessage('Dahab security: '.$this->facts['label'].' by '.$this->facts['actor'].' at '.$this->facts['at'].'. Check your email or the Dashboard.');
    }

    private function sentence(): string
    {
        return $this->facts['label'].' — by '.$this->facts['actor'].', '.$this->facts['at'].' (Cairo time).';
    }

    /** @return list<string> */
    private function details(): array
    {
        return array_values(array_filter([
            $this->facts['platform'] !== null ? 'Platform: '.$this->facts['platform'] : null,
            $this->facts['ip'] !== null ? 'IP: '.$this->facts['ip'] : null,
            $this->facts['device_ref'] !== null ? 'Device: '.$this->facts['device_ref'] : null,
        ]));
    }

    private function severity(): string
    {
        return match ($this->facts['event']) {
            'founder.frozen' => 'critical',
            'founder.device_approval_requested', 'founder.device_refused', 'founder.device_trusted_by_console',
            'founder.unfreeze_confirmed', 'founder.unfrozen' => 'warning',
            default => 'info',
        };
    }

    private function isDeviceRequest(): bool
    {
        return $this->facts['event'] === 'founder.device_approval_requested';
    }

    private function url(): string
    {
        return rtrim((string) config('dahab-auth.dashboard_url'), '/').$this->link;
    }
}
