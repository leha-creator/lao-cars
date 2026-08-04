<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Заведение первого администратора панели.
 *
 * ВНИМАНИЕ: штатная `php artisan make:filament-user` для первого запуска
 * не годится. Она спрашивает ровно три поля — имя, e-mail и пароль, —
 * роли среди них нет, поэтому созданный ею пользователь получает
 * умолчание колонки `users.role`, то есть `manager`. Повысить его можно
 * только из `UserResource`, который менеджеру недоступен: панель
 * работает, войти можно, настройки и пользователи закрыты навсегда.
 *
 * Сиды здесь не помогают — `DatabaseSeeder` создаёт пользователей только
 * вне production.
 *
 * Команда идемпотентна: повторный запуск на уже-администраторе ничего
 * не меняет, на существующем менеджере — повышает его.
 */
final class MakeAdminCommand extends Command
{
    protected $signature = 'laocars:make-admin
                            {--name= : Имя пользователя (спрашивается интерактивно, если не задано)}
                            {--email= : E-mail пользователя (спрашивается интерактивно, если не задан)}
                            {--password= : Пароль; для неинтерактивного деплоя. Без опции — скрытый ввод}';

    protected $description = 'Создать администратора панели или повысить существующего пользователя до администратора';

    public function handle(): int
    {
        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $this->promoteExisting($user);
        }

        return $this->createNew($email);
    }

    /**
     * E-mail с валидацией: без неё опечатка в неинтерактивном деплое
     * молча заведёт второго пользователя вместо повышения первого.
     */
    private function resolveEmail(): ?string
    {
        $email = $this->option('email') ?: text(
            label: 'E-mail администратора',
            required: true,
        );

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->error('Некорректный e-mail: '.$validator->errors()->first('email'));

            return null;
        }

        return (string) $email;
    }

    private function promoteExisting(User $user): int
    {
        if ($user->isAdmin()) {
            $this->info("Пользователь {$user->email} уже администратор — ничего не меняю.");

            return self::SUCCESS;
        }

        $previousRole = $user->role;

        $user->role = UserRole::Admin;
        $user->save();

        Log::info('Пользователь повышен до администратора командой laocars:make-admin', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'from' => $previousRole->value,
        ]);

        $this->info("Пользователь {$user->email} повышен до администратора (была роль «{$previousRole->label()}»).");

        return self::SUCCESS;
    }

    private function createNew(string $email): int
    {
        $name = $this->option('name') ?: text(
            label: 'Имя администратора',
            required: true,
        );

        $plainPassword = $this->option('password') ?: password(
            label: 'Пароль',
            required: true,
        );

        $validator = Validator::make(
            ['name' => $name, 'password' => $plainPassword],
            ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'min:8']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        // Каст `hashed` на модели хеширует пароль сам — Hash::make здесь
        // дал бы двойное хеширование и невозможность войти.
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $plainPassword,
            'role' => UserRole::Admin,
        ]);

        Log::info('Создан администратор командой laocars:make-admin', [
            'user_id' => $user->getKey(),
            'email' => $user->email,
        ]);

        $this->info("Администратор {$user->email} создан. Вход — /admin.");

        return self::SUCCESS;
    }
}
