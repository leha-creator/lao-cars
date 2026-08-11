<?php

use App\Models\PushSubscription;
use App\Models\User;

/*
 * Подписка браузера сотрудника на push-уведомления (веха 4.7).
 *
 * Ручка выглядит тривиально — одна запись в одну таблицу, — и почти вся
 * её сложность в том, чей это браузер. За одним компьютером в мастерской
 * по очереди работают двое, а один сотрудник подписывается с трёх
 * устройств: ключом служит `endpoint`, а не пользователь.
 */

/** Тело запроса в форме `PushSubscription.toJSON()` из браузера. */
function subscriptionPayload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM=',
            'auth' => 'tBHItJI5svbpez7KI4CCXg==',
        ],
        'contentEncoding' => 'aes128gcm',
    ];
}

it('stores a subscription for the authenticated employee', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    $subscription = PushSubscription::query()->sole();

    expect($subscription->subscribable_id)->toBe($user->getKey())
        // Алиас morph map, а не FQCN: карта в проекте `enforce`,
        // и значение в колонке — короткое имя.
        ->and($subscription->subscribable_type)->toBe('user')
        ->and($subscription->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/abc123');
});

it('records the browser of the device so the employee can tell them apart', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36')
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    // Без читаемого имени список устройств — три одинаковые строки,
    // и отозвать нужную подписку невозможно.
    expect(PushSubscription::query()->sole()->deviceLabel())->toBe('Chrome · Windows');
});

it('redirects a guest to the panel login instead of failing', function () {
    // Штатное умолчание `auth` — `route('login')`, а такого имени
    // в проекте нет: вход живёт в панели. Без явной настройки гость
    // получал бы 500 вместо страницы входа.
    $this->post(route('push-subscriptions.store'), subscriptionPayload())
        ->assertRedirect(route('filament.admin.auth.login'));

    expect(PushSubscription::query()->count())->toBe(0);
});

it('updates the existing row instead of adding a second one for the same browser', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertOk();

    // Браузер перерегистрирует подписку сам и без спроса. Вторая строка
    // на тот же `endpoint` означала бы два запроса к чужому API на каждую
    // заявку — и уникальный индекс, который однажды уронит ручку.
    expect(PushSubscription::query()->count())->toBe(1);
});

it('moves a subscription left by another employee to the one logged in now', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    $this->actingAs($second)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload());

    // За одним браузером по очереди работают двое, и оставшаяся подписка
    // слала бы заявки не тому человеку — причём заметил бы это тот, кто
    // уведомления получает, а не тот, кто их ждёт.
    expect(PushSubscription::query()->sole()->subscribable_id)->toBe($second->getKey());
});

it('deletes only the subscription of the employee asking for it', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    $this->actingAs($stranger)
        ->deleteJson(route('push-subscriptions.destroy'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ])
        // Ответ одинаковый и в этом случае: 404 на чужой адрес сообщил бы,
        // что такая подписка существует.
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(1);

    $this->actingAs($owner)
        ->deleteJson(route('push-subscriptions.destroy'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ])
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(0);
});

it('rejects a payload that could never deliver anything', function () {
    $user = User::factory()->create();

    // Без ключей шифровать полезную нагрузку нечем, и подписка была бы
    // мёртвой с рождения: строка в базе есть, уведомления не уходят.
    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), [
            'endpoint' => 'не-адрес',
            'keys' => ['p256dh' => '', 'auth' => ''],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);

    expect(PushSubscription::query()->count())->toBe(0);
});

it('answers an unknown content encoding with 422 instead of falling over', function () {
    $user = User::factory()->create();

    // Значение уходит в `ContentEncoding::from()` внутри трейта пакета,
    // а тот на незнакомой строке бросает `ValueError`. Без правила
    // по enum-у ручка отвечала 500: правило мягче потребителя превращает
    // ошибку клиента в ошибку сервера — то же, что правило мягче колонки.
    // `array_merge`, а не `+`: объединение массивов оставляет значение
    // ЛЕВОГО операнда, и подмена ключа, который в наборе уже есть,
    // молча не применяется — тест проходит, ничего не проверив.
    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), array_merge(subscriptionPayload(), [
            'contentEncoding' => 'gzip',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('contentEncoding');

    expect(PushSubscription::query()->count())->toBe(0);
});

it('accepts both encodings the standard allows', function () {
    $user = User::factory()->create();

    // `aesgcm` — историческая кодировка, её всё ещё присылают старые
    // сборки браузеров. Отсечь её значило бы отказать им в подписке
    // ошибкой валидации, которую человек прочитать не может.
    foreach (['aes128gcm', 'aesgcm'] as $index => $encoding) {
        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/enc-'.$index,
                'keys' => subscriptionPayload()['keys'],
                'contentEncoding' => $encoding,
            ])
            ->assertCreated();
    }

    expect(PushSubscription::query()->count())->toBe(2);
});

it('takes the subscriptions of a deleted employee with him', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.store'), subscriptionPayload())
        ->assertCreated();

    // Связь полиморфная, внешнего ключа у неё нет, значит и каскада БД
    // нет: уборка живёт в `User::booted()`. Без неё push продолжали бы
    // уходить на устройства уволенного сотрудника до тех пор, пока
    // браузер сам не отзовёт разрешение.
    $user->delete();

    expect(PushSubscription::query()->count())->toBe(0);
});
