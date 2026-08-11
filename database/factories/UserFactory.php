<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * ВАЖНО: умолчание роли здесь — администратор, и это не описка.
 *
 * В БД умолчание обратное (`manager`), потому что там забытая роль обязана
 * урезать права. В тестах наоборот: десятки тестов админки заводят
 * пользователя через `User::factory()->create()` и заходят в каталог и
 * медиабиблиотеку. Менеджер по умолчанию уронил бы их все разом с 403,
 * и причина «поменяли умолчание фабрики» ищется дольше, чем пишется.
 *
 * Ограничения роли проверяются явным состоянием `->manager()`.
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Admin,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Менеджер: каталог и заявки, без контента, медиа и настроек.
     *
     * Единственный способ получить в тесте урезанные права — умолчание
     * фабрики их не даёт намеренно (см. PHPDoc класса).
     */
    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Manager,
        ]);
    }

    /**
     * Сотрудник с персональным адресом Telegram (веха 4.7).
     *
     * Умолчания флагов уведомлений в `definition()` нет намеренно: они
     * стоят на колонках (`true`), и второй источник истины разошёлся бы
     * с первым — та же причина, по которой в фабрике нет статуса заявки.
     */
    public function withTelegram(string $chatId = '123456789'): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_chat_id' => $chatId,
            'notify_telegram' => true,
        ]);
    }

    /**
     * Сотрудник, отключивший все уведомления.
     */
    public function withoutNotifications(): static
    {
        return $this->state(fn (array $attributes) => [
            'notify_telegram' => false,
            'notify_push' => false,
            'telegram_chat_id' => null,
        ]);
    }
}
