<?php

namespace App\Console\Commands;

use App\Actions\FounderSecurity\TrustFounderDeviceAction;
use App\Support\DatabaseActor;
use App\Support\SystemActor;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Spec 021 C2: trust a founder's device from the server. The founder reads the device id on the
 * Dashboard's "Waiting for approval" screen. Audited as the system actor; both founders are told.
 */
class TrustFounderDevice extends Command
{
    protected $signature = 'founder:trust-device
        {email : The founder\'s email}
        {device-id : The id shown on the founder\'s "Waiting for approval" screen}
        {--platform=web : The device platform (X-Device-Platform)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Trust a founder\'s device so their sign-in from it is not held for approval (spec 021)';

    public function handle(TrustFounderDeviceAction $trust): int
    {
        $email = (string) $this->argument('email');
        $platform = (string) $this->option('platform');

        if (! $this->option('force') && ! $this->confirm("Trust this {$platform} device for {$email}? Sign-ins from it will no longer be held.")) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        try {
            $result = DatabaseActor::elevate(
                'system',
                fn () => $trust->handle($email, (string) $this->argument('device-id'), $platform),
                SystemActor::id(),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Trusted device {$result['device_ref']} for {$result['staff']->full_name}.");
        if ($result['pending_ended'] > 0) {
            $this->line('The waiting request from this device has ended; sign in again.');
        }

        return self::SUCCESS;
    }
}
