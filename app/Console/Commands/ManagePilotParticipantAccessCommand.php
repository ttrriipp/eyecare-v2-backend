<?php

namespace App\Console\Commands;

use App\Actions\Auth\ResetPilotParticipantCredential;
use App\Actions\Auth\RevokePilotParticipants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('pilot:manage-access
    {action : reset or revoke}
    {participant_code? : participant code for one account}
    {--all : target all participant accounts (revoke only)}
    {--yes : skip the confirmation prompt}')]
#[Description('Reset credentials or revoke pilot participant access')]
class ManagePilotParticipantAccessCommand extends Command
{
    public function __construct(
        private readonly ResetPilotParticipantCredential $resetCredential,
        private readonly RevokePilotParticipants $revokeParticipants,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        $participantCode = $this->argument('participant_code');
        $participantCode = is_string($participantCode) && trim($participantCode) !== ''
            ? $participantCode
            : null;
        $all = (bool) $this->option('all');

        if (! in_array($action, ['reset', 'revoke'], true)) {
            $this->error('Action must be reset or revoke.');

            return self::FAILURE;
        }

        if ($action === 'reset' && $all) {
            $this->error('Reset requires one participant code and cannot use --all.');

            return self::FAILURE;
        }

        if ($action === 'reset' && $participantCode === null) {
            $this->error('Reset requires one participant code.');

            return self::FAILURE;
        }

        if ($action === 'revoke' && $all && $participantCode !== null) {
            $this->error('Specify one participant code or use --all, but not both.');

            return self::FAILURE;
        }

        if ($action === 'revoke' && ! $all && $participantCode === null) {
            $this->error('Specify one participant code or use --all.');

            return self::FAILURE;
        }

        if (! $this->confirmOperation($action, $all)) {
            return self::FAILURE;
        }

        try {
            if ($action === 'reset') {
                $result = $this->resetCredential->handle($participantCode);
                $this->info('Pilot participant credential reset.');
                $this->info('Private credential file written to the configured disk.');
                $this->line("File: {$result['manifest_path']}");

                return self::SUCCESS;
            }

            $result = $this->revokeParticipants->handle($participantCode, $all);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Revoked {$result['revoked_count']} pilot participant account(s).");
        $this->info("Deleted {$result['tokens_deleted']} access token(s).");

        return self::SUCCESS;
    }

    private function confirmOperation(string $action, bool $all): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Confirmation is required; rerun with --yes in non-interactive mode.');

            return false;
        }

        $prompt = match (true) {
            $action === 'reset' => 'Reset this pilot participant credential?',
            $all => 'Revoke access for all pilot participant accounts?',
            default => 'Revoke access for this pilot participant account?',
        };

        if ($this->confirm($prompt)) {
            return true;
        }

        $this->line('Operation cancelled.');

        return false;
    }
}
