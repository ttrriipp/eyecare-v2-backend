<?php

namespace App\Console\Commands;

use App\Actions\Auth\ProvisionPilotAdministrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

#[Signature('pilot:provision-administrator
    {email : administrator email address}
    {first_name : administrator first name}
    {last_name : administrator last name}
    {--password-file= : read the password from a protected file}
    {--yes : skip the confirmation prompt}')]
#[Description('Create or update the pilot administrator without a default password')]
class ProvisionPilotAdministratorCommand extends Command
{
    public function __construct(
        private readonly ProvisionPilotAdministrator $provisionAdministrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $firstName = (string) $this->argument('first_name');
        $lastName = (string) $this->argument('last_name');

        if (! $this->confirmOperation()) {
            return self::FAILURE;
        }

        try {
            $password = $this->readPassword();
            $this->validatePassword($password);
            $this->provisionAdministrator->handle($email, $firstName, $lastName, $password);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Pilot administrator provisioned.');

        return self::SUCCESS;
    }

    private function confirmOperation(): bool
    {
        if ((bool) $this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Confirmation is required; rerun with --yes in non-interactive mode.');

            return false;
        }

        if ($this->confirm('Provision the pilot administrator account?')) {
            return true;
        }

        $this->line('Operation cancelled.');

        return false;
    }

    private function readPassword(): string
    {
        $passwordFile = $this->option('password-file');
        if (is_string($passwordFile) && trim($passwordFile) !== '') {
            $passwordFile = trim($passwordFile);

            if (! is_file($passwordFile) || ! is_readable($passwordFile)) {
                throw new RuntimeException('The administrator password file is not readable.');
            }

            $permissions = fileperms($passwordFile);

            if ($permissions === false || ($permissions & 077) !== 0) {
                throw new RuntimeException('The administrator password file must be readable only by its owner.');
            }

            $password = file_get_contents($passwordFile);
            if ($password === false) {
                throw new RuntimeException('The administrator password file could not be read.');
            }

            return rtrim($password, "\r\n");
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException('Non-interactive bootstrap requires --password-file.');
        }

        $password = $this->secret('Administrator password');
        $confirmation = $this->secret('Confirm administrator password');

        if ($password !== $confirmation) {
            throw new RuntimeException('Administrator password confirmation did not match.');
        }

        return $password;
    }

    private function validatePassword(string $password): void
    {
        $validator = Validator::make(
            [
                'password' => $password,
                'password_confirmation' => $password,
            ],
            [
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            throw new RuntimeException((string) $validator->errors()->first('password'));
        }
    }
}
