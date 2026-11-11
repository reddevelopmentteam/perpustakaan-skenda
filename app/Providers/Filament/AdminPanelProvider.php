<?php

namespace App\Providers\Filament;

use App\Filament\Auth\CustomLogin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->brandName('Perpustakaan SMKN 2 Banjarmasin')
            ->brandLogo(asset('images/logo_smkn_2.png'))
            ->font('Plus Jakarta Sans')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => [
                    50 => '#edf7f1',
                    100 => '#dcefe3',
                    200 => '#b8dec7',
                    300 => '#86c5a2',
                    400 => '#4fa579',
                    500 => '#287a52',
                    600 => '#1b5e40',
                    700 => '#164d35',
                    800 => '#123d2b',
                    900 => '#0e2f21',
                    950 => '#081b13',
                ],
                'success' => Color::hex('#287A52'),
                'warning' => Color::hex('#D69E36'),
                'danger' => Color::hex('#C84B4B'),
                'info' => Color::hex('#477FA5'),
                'gray' => Color::Slate,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->globalSearch()
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => Blade::render('<div class="px-4 pb-3 pt-1 text-xs font-medium tracking-wide text-emerald-100/70">Perpustakaan SMKN 2 Banjarmasin</div>')
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                \App\Filament\Widgets\WelcomeWidget::class,
                \App\Filament\Widgets\StudentOverviewStats::class,
                \App\Filament\Widgets\StaffOverviewStats::class,
                \App\Filament\Widgets\LoansChart::class,
                \App\Filament\Widgets\CategoryChart::class,
                \App\Filament\Widgets\ActionableLoansTable::class,
                \App\Filament\Widgets\StudentRecentActivity::class,
                \App\Filament\Widgets\PopularBooksTable::class,
                \App\Filament\Widgets\ActiveStudentsTable::class,
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
            ]);
    }
}
