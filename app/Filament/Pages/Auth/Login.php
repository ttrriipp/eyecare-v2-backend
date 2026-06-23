<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

class Login extends BaseLogin
{
    protected static string $layout = 'filament.components.layouts.login';

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Username')
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Password')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    public function hasLogo(): bool
    {
        return false;
    }
}
