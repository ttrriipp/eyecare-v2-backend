<?php

namespace App\Console\Commands;

use App\Actions\Auth\ProvisionPilotParticipants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('pilot:provision-participants')]
#[Description('Provision pseudonymous pilot participant accounts and a private one-time credential manifest')]
class ProvisionPilotParticipantsCommand extends Command
{
    public function __construct(
        private readonly ProvisionPilotParticipants $provisionParticipants,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->provisionParticipants->handle();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Provisioned {$result['participant_count']} pilot participant account(s).");
        $this->info('Private credential manifest written to the configured disk.');

        return self::SUCCESS;
    }
}
