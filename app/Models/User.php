<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
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
     * Пока в системе есть только сотрудники — таблица `users` не используется
     * для клиентов сайта, поэтому вход открыт всем аутентифицированным.
     * Разделение администратора и менеджера — веха 3.5, и делаться оно будет
     * политиками, а не проверками внутри контроллеров.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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
        ];
    }
}
