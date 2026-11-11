<?php

namespace App\Filament\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class CustomLogin extends Login
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getLoginFormComponent(): Component
    {
        return \Filament\Forms\Components\TextInput::make('login')
            ->label('Email / Username')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $login = trim((string) ($data['login'] ?? ''));

        $user = User::query()
            ->where('email', $login)
            ->orWhere('username', $login)
            ->first();

        if ($user instanceof User && ! $user->is_active) {
            throw ValidationException::withMessages([
                'data.login' => 'Akun Anda dinonaktifkan. Hubungi petugas perpustakaan.',
            ]);
        }

        $credentials = [
            'email' => $user?->email ?? $login,
            'password' => $data['password'] ?? null,
        ];

        $remember = (bool) ($data['remember'] ?? false);

        if (! Filament::auth()->attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'data.login' => 'Email/username atau kata sandi salah.',
            ]);
        }

        $authenticated = Filament::auth()->user();

        if ($authenticated instanceof FilamentUser && ! $authenticated->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            Filament::auth()->logout();

            throw ValidationException::withMessages([
                'data.login' => 'Akun Anda dinonaktifkan. Hubungi petugas perpustakaan.',
            ]);
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }
}
