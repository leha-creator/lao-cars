<?php

use App\Filament\Pages\Auth\EditProfile;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

use function Pest\Livewire\livewire;

/*
 * Настройки уведомлений в кабинете сотрудника (веха 4.7).
 *
 * Страница СВОЯ, но наследует штатную, и половина проверок здесь — про
 * то, что от штатной ничего не отвалилось. Скопированный сюда список
 * полей выглядел бы работающей страницей ровно до того дня, когда
 * из него молча выпало бы подтверждение текущим паролем.
 */

/*
 * Сброс лимитеров обязателен, и здесь он про ДРУГОЙ лимитер, чем в тестах
 * формы заявки.
 *
 * `Filament\Auth\Pages\EditProfile::save()` пропускает не больше пяти
 * сохранений подряд (`rateLimit(5)` плюс ключ `filament-edit-profile:<id>`),
 * а счётчик живёт в Redis и `RefreshDatabase` его не откатывает. Ключ
 * при этом строится по id пользователя, а id в тестах начинается с единицы
 * в каждом файле — то есть счётчики РАЗНЫХ тестов складываются.
 *
 * Симптом ровно тот, о котором предупреждает правило проекта: в одиночку
 * файл зелёный, в общем прогоне — красный, и падает не тот тест, который
 * состояние оставил. Причём падает он не ошибкой, а тишиной: `save()`
 * при исчерпанном лимите выходит БЕЗ ошибок формы, поэтому проверка
 * читается как «валидация не сработала», а не как «сохранение
 * не выполнялось».
 */
beforeEach(function (): void {
    resetRateLimiters();
});

it('saves the notification settings of the employee', function () {
    $user = User::factory()->create([
        'notify_push' => true,
        'notify_telegram' => false,
        'telegram_chat_id' => null,
    ]);

    $this->actingAs($user);

    livewire(EditProfile::class)
        ->fillForm([
            'notify_push' => false,
            'notify_telegram' => true,
            'telegram_chat_id' => '123456789',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    expect($user->notify_push)->toBeFalse()
        ->and($user->notify_telegram)->toBeTrue()
        ->and($user->telegram_chat_id)->toBe('123456789');
});

it('accepts a group chat id with a leading minus', function () {
    $this->actingAs(User::factory()->create());

    // Маска «только цифры» отсекла бы групповые чаты молча: у них id
    // отрицательный, и сотрудник увидел бы «сохранено» без сохранённого
    // адреса.
    livewire(EditProfile::class)
        ->fillForm(['notify_telegram' => true, 'telegram_chat_id' => '-1001234567890'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(User::query()->sole()->telegram_chat_id)->toBe('-1001234567890');
});

it('rejects a telegram id that is not a number', function () {
    $this->actingAs(User::factory()->create());

    livewire(EditProfile::class)
        ->fillForm(['notify_telegram' => true, 'telegram_chat_id' => '@ivanov'])
        ->call('save')
        ->assertHasFormErrors(['telegram_chat_id']);
});

it('still demands the current password to change the password', function () {
    $user = User::factory()->create(['password' => Hash::make('старый-пароль')]);

    $this->actingAs($user);

    // СТОРОЖ ТОГО, ЧТО РОДИТЕЛЬСКАЯ СХЕМА НЕ ПЕРЕПИСАНА. Без этой
    // проверки смена чужого пароля из чужой открытой сессии становится
    // возможной, а страница выглядит абсолютно рабочей.
    livewire(EditProfile::class)
        ->fillForm([
            'password' => 'новый-пароль-1234',
            'passwordConfirmation' => 'новый-пароль-1234',
            'currentPassword' => 'совсем-не-тот-пароль',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('старый-пароль', $user->refresh()->password))->toBeTrue();
});

it('shows the devices of the employee and nobody elses', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $user->updatePushSubscription('https://push.example/own-device', 'key', 'token');
    $user->pushSubscriptions()->first()->update([
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Firefox/141.0',
    ]);

    $stranger->updatePushSubscription('https://push.example/stranger-device', 'key', 'token');

    $this->actingAs($user);

    livewire(EditProfile::class)
        ->assertOk()
        ->assertSee('Firefox · macOS');
});

it('tells the employee plainly when no device is subscribed', function () {
    $this->actingAs(User::factory()->create());

    // Пустая таблица под заголовком «Устройства» читается как поломка,
    // а честная фраза объясняет, что делать.
    livewire(EditProfile::class)
        ->assertOk()
        ->assertSee('Ни одного устройства не подписано');
});

it('revokes only the own subscription', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $user->updatePushSubscription('https://push.example/own-device', 'key', 'token');
    $stranger->updatePushSubscription('https://push.example/stranger-device', 'key', 'token');

    $strangerSubscription = PushSubscription::query()
        ->where('endpoint', 'https://push.example/stranger-device')
        ->sole();

    $this->actingAs($user);

    // Id приходит из разметки, то есть из-под контроля клиента:
    // `find($id)->delete()` позволил бы отозвать чужую подписку
    // подбором числа.
    livewire(EditProfile::class)
        ->call('revokeDevice', $strangerSubscription->getKey());

    expect(PushSubscription::whereKey($strangerSubscription->getKey())->exists())->toBeTrue();

    $ownSubscription = PushSubscription::query()
        ->where('endpoint', 'https://push.example/own-device')
        ->sole();

    livewire(EditProfile::class)
        ->call('revokeDevice', $ownSubscription->getKey());

    expect(PushSubscription::whereKey($ownSubscription->getKey())->exists())->toBeFalse();
});
