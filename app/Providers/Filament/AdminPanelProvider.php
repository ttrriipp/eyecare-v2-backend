<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\BrandAvatarProvider;
use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\AppointmentsChartWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TodaysScheduleWidget;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->passwordReset()
            ->brandName('Eyecare')
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/favicon.svg'))
            ->defaultThemeMode(ThemeMode::Light)
            ->defaultAvatarProvider(BrandAvatarProvider::class)
            ->databaseNotifications()
            ->globalSearchResourceOptIn()
            ->multiFactorAuthentication([
                AppAuthentication::make(),
            ], isRequired: app()->isProduction())
            ->colors([
                'primary' => Color::hex('#4F8DD7'),
                'gray' => Color::Slate,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            // The full sidebar runs taller than a 1366x768 front-desk laptop, so staff
            // can reclaim the width. Icons stay visible when collapsed, which only works
            // because every navigation icon is unique.
            ->sidebarCollapsibleOnDesktop()
            // Groups follow the clinic's working day, not the schema: the shift first,
            // then the people, their care, the sale, the money, and finally reference data.
            ->navigationGroups([
                'Today',
                'Patients',
                'Clinical',
                'Optical',
                'Billing',
                'Catalog',
                'Admin',
            ])
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                TodaysScheduleWidget::class,
                AppointmentsChartWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePasswordIsChanged::class,
            ]);
    }
}
