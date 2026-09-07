<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Services\SingleSessionManager;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    protected static string $layout = 'filament.components.layouts.login';

    public function authenticate(): ?LoginResponse
    {
        $currentUser = Filament::auth()->user();

        if ($currentUser instanceof User && $currentUser->requiresSingleSession()) {
            throw ValidationException::withMessages([
                'data.email' => 'This account is already signed in on another browser or device.',
            ]);
        }

        $response = parent::authenticate();

        if ($response === null) {
            return null;
        }

        $user = Filament::auth()->user();

        if ($user instanceof User && ! app(SingleSessionManager::class)->claim($user, session()->getId())) {
            Filament::auth()->logout();
            session()->invalidate();
            session()->regenerateToken();

            throw ValidationException::withMessages([
                'data.email' => 'This account is already signed in on another browser or device.',
            ]);
        }

        return $response;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email')
            ->email()
            ->required()
            ->autocomplete('email')
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Password')
            ->hint(filament()->hasPasswordReset() ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="-1">{{ __(\'filament-panels::auth/pages/login.actions.request_password_reset.label\') }}</x-filament::link>')) : null)
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required();
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        // The custom login layout renders the EyeCare brand mark itself,
        // so Filament's default "Sign in" header would otherwise be
        // duplicated inside the card.
        return null;
    }
}
