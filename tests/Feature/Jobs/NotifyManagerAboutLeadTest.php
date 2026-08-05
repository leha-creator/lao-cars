<?php

use App\Jobs\NotifyManagerAboutLead;
use App\Models\Lead;
use App\Services\TelegramNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Уведомление менеджера о заявке (веха 3.7).
 *
 * Главный инвариант вехи: заявка первична, уведомление вторично.
 *
 * Проверяется он двумя тестами, а не одним на `sync`-очереди. Соблазн
 * написать `config(['queue.default' => 'sync'])` + `Http::fake(503)`
 * и ждать редиректа — ловушка: `SyncQueue::handleException()` после
 * `$queueJob->fail($e)` **пробрасывает исключение дальше**
 * (`vendor/laravel/framework/src/Illuminate/Queue/SyncQueue.php`), то есть
 * упавшая задача выносит 500 пользователю — ровно то, что тест должен
 * опровергать. Зелёным такой тест станет только если задача начнёт глотать
 * исключения, то есть если выключить ретраи.
 *
 * Поэтому HTTP-путь проверяется на настоящей очереди, а поведение при
 * недоступном Telegram — прямым вызовом `handle()`.
 */

/*
 * Этот файл тоже отправляет форму, значит тоже упирается в лимитер соседей:
 * без сброса счётчик, оставленный тестом лимита в `LeadStoreTest`, роняет
 * HTTP-тест ниже — и симптом («Слишком много заявок») не имеет никакого
 * отношения к тому, что здесь проверяется. Объяснение — в `tests/Pest.php`.
 */
beforeEach(function (): void {
    resetRateLimiters();
});

it('keeps the lead and answers with a redirect while the notification only gets queued', function () {
    Queue::fake();

    $this->post(route('leads.store'), [
        'name' => 'Иван',
        'phone' => '+7 999 123-45-67',
    ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Lead::query()->count())->toBe(1);

    // Задача действительно ставится под RefreshDatabase: `afterCommit()`
    // внутри тестовой транзакции работает благодаря
    // `Illuminate\Foundation\Testing\DatabaseTransactionsManager`, где
    // `afterCommitCallbacksShouldBeExecuted()` возвращает true на уровне 1.
    // Если этот тест покажет обратное — проблема в реализации,
    // а не во фреймворке.
    Queue::assertPushed(NotifyManagerAboutLead::class);
});

it('throws when the telegram api is down so the retries can happen', function () {
    config(['services.telegram.token' => 'token', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response('', 503)]);

    $lead = Lead::factory()->general()->create();

    // Исключение здесь — правильное поведение, а не баг: без него
    // задача считается выполненной и ретраев не будет.
    expect(fn () => (new NotifyManagerAboutLead($lead))->handle(app(TelegramNotifier::class)))
        ->toThrow(RuntimeException::class);

    // Заявка на месте — это и есть инвариант вехи.
    expect(Lead::whereKey($lead->getKey())->exists())->toBeTrue();
});

it('does not leak the bot token into the exception message', function () {
    config(['services.telegram.token' => 'secret-token-value', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response('{"ok":false}', 401)]);

    $lead = Lead::factory()->general()->create();

    // URL запроса содержит токен, и неаккуратно собранное исключение
    // унесло бы его в лог — файл, который живёт 30 дней и попадает
    // в бэкапы.
    try {
        (new NotifyManagerAboutLead($lead))->handle(app(TelegramNotifier::class));
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')
        ->and($message)->not->toContain('secret-token-value')
        ->and($message)->toContain('401');
});

it('logs the final failure without the full phone number', function () {
    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    $lead = Lead::factory()->general()->create(['phone' => '+7 999 123-45-67']);

    (new NotifyManagerAboutLead($lead))->failed(new RuntimeException('Telegram недоступен'));

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($lead): bool {
            return $message === '[Lead] уведомление менеджеру не доставлено'
                && $context['lead_id'] === $lead->getKey()
                // Проверяется именно отсутствие полного номера, а не только
                // наличие записи: персональные данные клиента в канал
                // `leads` не идут (запрет в шапке канала).
                && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), '+7 999 123-45-67')
                && $context['phone_tail'] === '5-67';
        })
        ->once();
});

it('skips the notification with a warning when telegram is not configured', function () {
    Http::fake();

    config(['services.telegram.token' => null, 'services.telegram.chat_id' => null]);

    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    $lead = Lead::factory()->general()->create();

    // Без исключения: на локальной машине и в CI бота нет, и заваливать
    // failed_jobs пятью попытками на каждый тестовый лид незачем.
    (new NotifyManagerAboutLead($lead))->handle(app(TelegramNotifier::class));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Lead] Telegram не сконфигурирован, уведомление пропущено'
            && $context['lead_id'] === $lead->getKey())
        ->once();

    Http::assertNothingSent();
});

it('escapes angle brackets coming from the client', function () {
    config(['services.telegram.token' => 'token', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $lead = Lead::factory()->general()->create([
        'name' => 'Иван <b>жирный</b>',
        'message' => 'Сравните a < b > c',
    ]);

    app(TelegramNotifier::class)->send($lead);

    // При `parse_mode: HTML` пропущенный `e()` даёт 400 от Telegram,
    // пять ретраев и запись в failed_jobs — по вине формата сообщения,
    // а не сети. Ошибка не воспроизводится на нормальных данных и ждёт
    // первого клиента с `<` в имени.
    Http::assertSent(function (Request $request): bool {
        $text = (string) $request['text'];

        return str_contains($text, 'Иван &lt;b&gt;жирный&lt;/b&gt;')
            && str_contains($text, 'a &lt; b &gt; c')
            // Собственная разметка сообщения при этом цела.
            && str_contains($text, '<b>Новая заявка</b>');
    });
});

it('links to the lead card in the admin panel', function () {
    config(['services.telegram.token' => 'token', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $lead = Lead::factory()->general()->create();

    app(TelegramNotifier::class)->send($lead);

    // Менеджер попадает в панель одним касанием, а не ищет заявку в списке.
    Http::assertSent(fn (Request $request): bool => str_contains(
        (string) $request['text'],
        route('filament.admin.resources.leads.view', $lead),
    ));
});

it('logs a successful delivery', function () {
    config(['services.telegram.token' => 'token', 'services.telegram.chat_id' => '1']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $log = Log::spy();
    $log->shouldReceive('channel')->with('leads')->andReturnSelf();

    $lead = Lead::factory()->general()->create();

    (new NotifyManagerAboutLead($lead))->handle(app(TelegramNotifier::class));

    // Четвёртое из событий, перечисленных в шапке канала `leads`.
    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === '[Lead] уведомление доставлено в Telegram'
            && $context['lead_id'] === $lead->getKey())
        ->once();
});

it('retries five times with growing pauses', function () {
    $job = new NotifyManagerAboutLead(Lead::factory()->general()->create());

    // Четыре паузы на пять попыток — не опечатка: Laravel повторяет
    // последнее значение для всех последующих попыток.
    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 60, 300, 900]);
});
