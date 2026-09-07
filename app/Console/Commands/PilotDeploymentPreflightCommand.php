<?php

namespace App\Console\Commands;

use App\Services\Deployment\PilotDeploymentPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pilot:preflight')]
#[Description('Validate production configuration and dependencies before exposing the capstone deployment')]
class PilotDeploymentPreflightCommand extends Command
{
    public function __construct(
        private readonly PilotDeploymentPreflight $preflight,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->preflight->run();

        foreach ($result['checks'] as $key => $check) {
            if ($check['passed']) {
                $this->line("PASS {$key}");
            } else {
                $this->error("FAIL {$key}: {$check['message']}");
            }
        }

        if (! $result['passed']) {
            $this->error('Deployment preflight failed.');

            return self::FAILURE;
        }

        $this->info('Deployment preflight passed.');

        return self::SUCCESS;
    }
}
