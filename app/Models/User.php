<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

#[Fillable(['name', 'email', 'password', 'role', 'telegram_chat_id', 'notify_telegram', 'notify_push'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

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
     * Получатели Telegram-уведомлений о новой заявке (веха 4.7).
     *
     * Скоуп живёт в модели, а не в `LeadNotifier`: логика выборки
     * принадлежит модели — правило `ARCHITECTURE.md`.
     *
     * Оба условия обязательны и проверяют разное. Флаг — это «хочу
     * получать», заполненный `chat_id` — «есть куда отправить». Выборка
     * по одному флагу дала бы задачу с пустым адресом: она не упала бы,
     * а тихо ушла бы в никуда, и в логе осталась бы запись об успешной
     * постановке.
     */
    #[Scope]
    protected function receivingTelegram(Builder $query): void
    {
        $query->where('notify_telegram', true)
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '');
    }

    /**
     * Получатели браузерных push-уведомлений (веха 4.7).
     *
     * Наличие подписки здесь НЕ проверяется намеренно, в отличие
     * от `chat_id` выше. Канал `database` (колокольчик в панели) уходит
     * тому же уведомлению и работает без всякой подписки: сотрудник,
     * включивший флаг и не разрешивший уведомления в браузере, должен
     * видеть заявку в колокольчике. Отсев по отсутствию подписки
     * отобрал бы у него и колокольчик — молча.
     */
    #[Scope]
    protected function receivingPush(Builder $query): void
    {
        $query->where('notify_push', true);
    }

    protected static function booted(): void
    {
        // Подписка связана с пользователем полиморфно, а внешнего ключа
        // у морфа нет — значит и каскада БД нет. Без этого удалённый
        // сотрудник оставляет свои подписки навсегда, и push продолжают
        // уходить на его устройства до тех пор, пока браузер сам
        // не отзовёт разрешение.
        self::deleting(static function (self $user): void {
            $user->pushSubscriptions()->delete();
        });
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
            'notify_telegram' => 'boolean',
            'notify_push' => 'boolean',
        ];
    }
}
