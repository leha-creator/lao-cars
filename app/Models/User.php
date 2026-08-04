<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Доступ к админ-панели.
     *
     * Интерфейс реализован явно, потому что без него Filament пускает в панель
     * только при `APP_ENV=local`: на любом другом окружении — 403. Такое
     * поведение означало бы, что права зависят от окружения, а не от модели.
     *
     * Метод по-прежнему возвращает `true` всем аутентифицированным, и это
     * не пропущенная проверка: в панель ходят обе роли, а различает их не
     * вход, а политики (`app/Policies/`). Панель работает в строгом режиме
     * авторизации, поэтому отсутствие политики закрывает ресурс, а не
     * открывает его.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Полный доступ к панели.
     *
     * Тонкая обёртка над enum-ом: сравнение с кейсом живёт в одном месте
     * (`UserRole::isAdmin()`), а политики зовут этот метод.
     */
    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }
}
