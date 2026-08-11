<?php

use App\Jobs\NotifyManagerAboutLead;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\NewLeadNotification;
use App\Services\LeadNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Кому уходит новая заявка (веха 4.7).
 *
 * До вехи получатель был один и жил в конфиге. Здесь проверяется то,
 * что заменило эту строку, и главный сторож набора — не «уведомление
 * ушло», а «на каждого получателя своя задача»: цикл по списку внутри
 * одной задачи прошёл бы проверку доставки и провалил бы прод дублями.
 */

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
});

it('sends the push notification only to those who asked for it', function () {
    $wants = User::factory()->create();
    $does_not = User::factory()->create(['notify_push' => false]);

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    Notification::assertSentTo($wants, NewLeadNotification::class);
    Notification::assertNotSentTo($does_not, NewLeadNotification::class);
});

it('needs both the flag and an address to send to telegram', function () {
    $ready = User::factory()->withTelegram('111')->create();
    // Флаг снят — адрес есть.
    $off = User::factory()->create(['notify_telegram' => false, 'telegram_chat_id' => '222']);
    // Флаг стоит — адреса нет. Задача с пустым адресом не упала бы,
    // а тихо ушла бы в никуда, и в логе осталась бы запись об успешной
    // постановке.
    $addressless = User::factory()->create(['notify_telegram' => true, 'telegram_chat_id' => null]);

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    Queue::assertPushed(NotifyManagerAboutLead::class, 1);
    Queue::assertPushed(
        NotifyManagerAboutLead::class,
        fn (NotifyManagerAboutLead $job): bool => $job->chatId === '111',
    );

    expect([$off->getKey(), $addressless->getKey()])->toHaveCount(2)
        ->and($ready->telegram_chat_id)->toBe('111');
});

it('queues a separate telegram job for every recipient', function () {
    User::factory()->withTelegram('111')->create();
    User::factory()->withTelegram('222')->create();
    User::factory()->withTelegram('333')->create();

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    // СТОРОЖ РЕШЕНИЯ 8. Цикл по получателям внутри одной задачи прошёл бы
    // тест «уведомление ушло» и провалил бы прод: упавшая отправка
    // третьему увела бы в ретрай всю задачу, и первые двое получили бы
    // заявку повторно — тем чаще, чем хуже связь.
    Queue::assertPushed(NotifyManagerAboutLead::class, 3);

    foreach (['111', '222', '333'] as $chatId) {
        Queue::assertPushed(
            NotifyManagerAboutLead::class,
            fn (NotifyManagerAboutLead $job): bool => $job->chatId === $chatId,
        );
    }
});

it('falls back to the shared chat only when nobody has a personal one', function () {
    config(['services.telegram.chat_id' => 'общий-чат']);

    // Сотрудник есть, но Telegram у него выключен: персональных
    // получателей нет вовсе.
    User::factory()->create(['notify_telegram' => false]);

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    Queue::assertPushed(NotifyManagerAboutLead::class, 1);
    Queue::assertPushed(
        NotifyManagerAboutLead::class,
        fn (NotifyManagerAboutLead $job): bool => $job->chatId === 'общий-чат',
    );
});

it('stops using the shared chat as soon as someone has a personal one', function () {
    config(['services.telegram.chat_id' => 'общий-чат']);

    User::factory()->withTelegram('111')->create();

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    // «Слать и туда, и туда» — это дубль каждой заявки навсегда, начиная
    // с первого заполненного профиля. Ровно один получатель.
    Queue::assertPushed(NotifyManagerAboutLead::class, 1);
    Queue::assertPushed(
        NotifyManagerAboutLead::class,
        fn (NotifyManagerAboutLead $job): bool => $job->chatId === '111',
    );
});

it('warns when the lead is saved but nobody will hear about it', function () {
    config(['services.telegram.chat_id' => null]);

    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    // Ни одного сотрудника и пустой общий чат.
    app(LeadNotifier::class)->notify($lead = Lead::factory()->general()->create());

    Queue::assertNothingPushed();
    Notification::assertNothingSent();

    // Заявка сохранена, но о ней никто не узнает — ровно тот отказ, ради
    // диагностики которого канал `leads` и заведён: снаружи сайт при этом
    // выглядит работающим.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Lead] получателей уведомлений нет ни по одному каналу'
            && $context['lead_id'] === $lead->getKey())
        ->once();
});

it('marks the fallback in the log so its last day is visible', function () {
    config(['services.telegram.chat_id' => 'общий-чат']);

    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    app(LeadNotifier::class)->notify(Lead::factory()->general()->create());

    // Переход с общего чата на персональные адреса — событие, а не
    // настройка: день, когда флаг погаснет, это день, когда общий чат
    // замолчал, и объяснить это иначе будет нечем.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Lead] уведомления поставлены в очередь'
            && $context['fallback'] === true)
        ->once();
});

it('keeps the lead when the push channel is unusable', function () {
    // Инвариант вехи 3.7 в новых условиях: недоступный канал доставки
    // не теряет заявку и не мешает остальным каналам. Проверяется НЕ на
    // `queue.default = sync` — правило проекта: `SyncQueue` пробрасывает
    // исключение и превращает падение задачи в 500 у пользователя.
    config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

    User::factory()->withTelegram('111')->create();

    $lead = Lead::factory()->general()->create();

    app(LeadNotifier::class)->notify($lead);

    expect(Lead::whereKey($lead->getKey())->exists())->toBeTrue();

    // Telegram не пострадал от того, что push не сконфигурированы.
    Queue::assertPushed(NotifyManagerAboutLead::class, 1);
});
