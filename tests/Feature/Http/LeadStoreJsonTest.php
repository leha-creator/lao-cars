<?php

use App\Jobs\NotifyManagerAboutLead;
use App\Models\Car;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Вторая форма ответа на заявку — JSON вместо редиректа (веха 4.7).
 *
 * Развилка построена на заголовках запроса (`Request::expectsJson()`),
 * а не на поле формы, и разница видна прямо здесь: соседний
 * `LeadStoreTest` ходит через `$this->post()` без `Accept` и остаётся
 * описанием HTML-пути, а этот файл — через `$this->postJson()`. Два набора
 * на одном контроллере, и ни один не может перехватить чужой.
 *
 * Проверяется форма ответа, а не приём заявки: правила валидации, honeypot,
 * серверный `page_url` и лимит по IP — инварианты вехи 3.7, и их сторожа
 * лежат в `LeadStoreTest`. Здесь важно, что все эти развилки отвечают
 * в том же формате, что и успех.
 */

/*
 * Обязателен по правилу проекта: счётчик лимитера живёт в Redis
 * и RefreshDatabase его не откатывает. Здесь он тем более обязателен —
 * тест на лимит ниже упирается в него намеренно.
 */
beforeEach(function (): void {
    resetRateLimiters();
});

/**
 * Минимальная валидная заявка.
 *
 * Имя своё, а не `leadPayload()` соседнего файла: глобальные функции Pest
 * объявляются в самих файлах тестов, и вторая декларация того же имени
 * — фатальная ошибка PHP, а не переопределение.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jsonLeadPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Иван',
        'phone' => '+7 999 123-45-67',
    ], $overrides);
}

it('captures a lead sent as json and answers with the success message', function () {
    Queue::fake();

    $this->postJson(route('leads.store'), jsonLeadPayload())
        ->assertOk()
        ->assertExactJson(['message' => 'Заявка принята — менеджер свяжется с вами.']);

    expect(Lead::query()->count())->toBe(1);

    // Порядок «заявка первична, уведомление вторично» форма ответа
    // не меняет: постановка задачи обязана случиться и на этом пути.
    Queue::assertPushed(NotifyManagerAboutLead::class);
});

it('answers a filled honeypot with the same json a human gets', function () {
    Queue::fake();

    // Ответ боту неотличим от ответа человеку — то же решение вехи 3.7,
    // перенесённое на вторую форму ответа. JSON-ветка, забывшая про
    // honeypot, называет боту имя поля-ловушки кодом ответа.
    $this->postJson(route('leads.store'), jsonLeadPayload(['website' => 'http://spam.example']))
        ->assertOk()
        ->assertExactJson(['message' => 'Заявка принята — менеджер свяжется с вами.']);

    expect(Lead::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('writes the honeypot rejection to the leads channel on the json path too', function () {
    Queue::fake();

    // Единственный след отброшенной заявки — запись в канале `leads`.
    // Без неё спам через `fetch` перестаёт быть диагностируемым: лида нет,
    // ответ успешный, в логах пусто.
    Log::shouldReceive('channel')
        ->with('leads')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('debug')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'honeypot'));

    $this->postJson(route('leads.store'), jsonLeadPayload(['website' => 'http://spam.example']))
        ->assertOk();
});

it('answers a validation failure with 422 and field errors', function () {
    Queue::fake();

    // 422 отдаёт сам Laravel по тем же заголовкам — правил
    // `StoreLeadRequest` веха 4.7 не касалась.
    $this->postJson(route('leads.store'), ['name' => 'Иван'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');

    expect(Lead::query()->count())->toBe(0);
});

it('answers an exhausted rate limit with 429 in the shape of a validation failure', function () {
    Queue::fake();

    $limit = (int) config('leads.rate_limit.per_minute');

    expect($limit)->toBeGreaterThan(0);

    for ($i = 0; $i < $limit; $i++) {
        $this->postJson(route('leads.store'), jsonLeadPayload(['name' => 'Иван '.$i]))
            ->assertOk();
    }

    $response = $this->postJson(route('leads.store'), jsonLeadPayload(['name' => 'Иван сверх лимита']))
        ->assertStatus(429)
        // Тело в формате ошибок валидации намеренно: клиент разбирает 422
        // и 429 одной веткой и рисует текст под полем «Телефон». Голый 429
        // вернул бы человека за общим адресом к странице ошибки — ровно
        // то, что веха 3.7 отвергла.
        ->assertJsonValidationErrors('phone');

    // Без `Retry-After` клиенту нечем отличить «через минуту»
    // от «навсегда»: заголовки лимитера обязаны доехать и в JSON.
    $response->assertHeader('Retry-After');

    expect(Lead::query()->count())->toBe($limit);
});

it('still takes the page address from the server on the json path', function () {
    Queue::fake();

    $car = Car::factory()->create();
    $previous = route('catalog.show', $car);

    $this->from($previous)
        ->postJson(route('leads.store'), jsonLeadPayload([
            // Значение уходит ссылкой в уведомление менеджеру. Смена формы
            // ответа этого не касается, но проверить дешевле, чем однажды
            // обнаружить, что клиентское поле доехало до колонки.
            'page_url' => 'http://phishing.example/steal',
        ]))
        ->assertOk();

    expect(Lead::query()->sole()->page_url)->toBe($previous);
});
