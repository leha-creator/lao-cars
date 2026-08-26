<?php

use App\Enums\LeadStatus;
use App\Jobs\NotifyManagerAboutLead;
use App\Models\Car;
use App\Models\Lead;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Legal\LeadIntake;
use App\Support\Legal\PrivacyPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Приём заявки со всех форм сайта (веха 3.7).
 *
 * Форма — критичный для бизнеса путь (`rules/base.md`): всё, что тут
 * проверяется, теряет заявку молча, если сломается.
 */

/*
 * Счётчик лимитера переживает RefreshDatabase — объяснение и сам сброс
 * лежат в `tests/Pest.php` (`resetRateLimiters()`). Здесь он обязателен:
 * тест на rate limit упирается в лимит намеренно и без сброса оставляет
 * счётчик соседям.
 */
beforeEach(function (): void {
    resetRateLimiters();
    enableLeadForms();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function leadPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Иван',
        'phone' => '+7 999 123-45-67',
        // Согласие на обработку персональных данных (веха 4.17) — часть
        // минимальной валидной заявки, а не дополнительное поле: без него
        // форма не принимается вовсе. Тесты самого согласия передают
        // `consent` через `$overrides`.
        'consent' => '1',
    ], $overrides);
}

it('captures a lead from a car card', function () {
    Queue::fake();

    $car = Car::factory()->create();

    $this->from(route('catalog.show', $car))
        ->post(route('leads.store'), leadPayload([
            'source_type' => 'car',
            'source_id' => $car->id,
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    // Алиас morph map, а не FQCN: так значение хранится в колонке.
    expect($lead->source_type)->toBe('car')
        ->and($lead->source->is($car))->toBeTrue()
        ->and($lead->sourceLabel())->toStartWith('Авто:');
});

it('captures a lead from a service page', function () {
    Queue::fake();

    $service = Service::factory()->create(['title' => 'Полировка кузова']);

    $this->post(route('leads.store'), leadPayload([
        'source_type' => 'service',
        'source_id' => $service->id,
    ]))->assertSessionHasNoErrors();

    expect(Lead::query()->sole()->sourceLabel())->toBe('Услуга: Полировка кузова');
});

it('captures a lead from the general form without a source', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload())
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->source_type)->toBeNull()
        ->and($lead->sourceLabel())->toBe('Общая форма');
});

it('captures a parts lookup request with the car of the client', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload([
        'part_brand' => 'Toyota',
        'part_model' => 'Land Cruiser 200',
        'part_vin' => 'JTMHV05J104123456',
    ]))->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    // Заявку на подбор отличают поля автомобиля клиента, а не источник:
    // этого автомобиля в каталоге нет.
    expect($lead->part_brand)->toBe('Toyota')
        ->and($lead->part_model)->toBe('Land Cruiser 200')
        ->and($lead->part_vin)->toBe('JTMHV05J104123456')
        ->and($lead->isPartsRequest())->toBeTrue();
});

it('gives a new lead the new status', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload())->assertSessionHasNoErrors();

    // Умолчание стоит на колонке и в DTO не дублируется: второй источник
    // истины означал бы заявку, заведённую сразу закрытой.
    expect(Lead::query()->sole()->status)->toBe(LeadStatus::New);
});

it('rejects a lead without a phone number', function () {
    Queue::fake();

    $this->post(route('leads.store'), ['name' => 'Иван'])
        ->assertSessionHasErrors('phone');

    expect(Lead::query()->count())->toBe(0);
});

it('rejects a phone longer than the column allows', function () {
    Queue::fake();

    // Сторож против ослабления правила ниже длины колонки:
    // `phone` — varchar(32), и правило мягче колонки означает ошибку
    // драйвера PostgreSQL вместо сообщения на форме, то есть потерянный лид.
    $tooLong = str_repeat('1', 40);

    expect(mb_strlen($tooLong))->toBeGreaterThan(32);

    $this->post(route('leads.store'), leadPayload(['phone' => $tooLong]))
        ->assertSessionHasErrors('phone');

    expect(Lead::query()->count())->toBe(0);
});

it('rejects a lead pointing at a car that does not exist', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload([
        'source_type' => 'car',
        'source_id' => 999999,
    ]))->assertSessionHasErrors('source_id');

    expect(Lead::query()->count())->toBe(0);
});

it('rejects a lead for an unpublished service', function () {
    Queue::fake();

    $service = Service::factory()->unpublished()->create();

    // Неопубликованной услуги нет ни на одной странице сайта — значит
    // заявка на неё может прийти только из подделанной формы.
    $this->post(route('leads.store'), leadPayload([
        'source_type' => 'service',
        'source_id' => $service->id,
    ]))->assertSessionHasErrors('source_id');

    expect(Lead::query()->count())->toBe(0);
});

it('accepts a lead for a sold car', function () {
    Queue::fake();

    $car = Car::factory()->sold()->create();

    // «Продан» — не удаление: карточка живёт ради истории и SEO (веха 3.6),
    // и заявка с неё легитимна. Разница с услугой намеренная.
    $this->post(route('leads.store'), leadPayload([
        'source_type' => 'car',
        'source_id' => $car->id,
    ]))->assertSessionHasNoErrors();

    expect(Lead::query()->sole()->source->is($car))->toBeTrue();
});

it('answers a filled honeypot with the same success as a human gets', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload(['website' => 'http://spam.example']))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        // То же сообщение, что и человеку: ответ, отличающийся от успеха,
        // сообщает боту имя поля-ловушки, и ловушка перестаёт работать.
        ->assertSessionHas('status', 'Заявка принята — менеджер свяжется с вами.');

    expect(Lead::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('takes the page address from the server and ignores the client field', function () {
    Queue::fake();

    $car = Car::factory()->create();
    $previous = route('catalog.show', $car);

    $this->from($previous)
        ->post(route('leads.store'), leadPayload([
            // Значение уходит ссылкой в уведомление менеджеру: клиентское
            // поле превратило бы уведомление в вектор фишинга.
            'page_url' => 'http://phishing.example/steal',
        ]))
        ->assertSessionHasNoErrors();

    expect(Lead::query()->sole()->page_url)->toBe($previous);
});

it('truncates a page address longer than the column allows', function () {
    Queue::fake();

    $previous = route('catalog.index').'?'.str_repeat('a', 300);

    expect(mb_strlen($previous))->toBeGreaterThan(255);

    $this->from($previous)
        ->post(route('leads.store'), leadPayload())
        ->assertSessionHasNoErrors();

    // Обрезка, а не отказ: длинный адрес — это обычный отфильтрованный
    // каталог, и терять из-за него заявку нельзя.
    expect(mb_strlen((string) Lead::query()->sole()->page_url))->toBe(255);
});

it('does not lose the client input when validation fails', function () {
    Queue::fake();

    $this->post(route('leads.store'), ['name' => 'Иван', 'message' => 'Перезвоните вечером'])
        ->assertSessionHasErrors('phone')
        ->assertSessionHasInput('name', 'Иван')
        ->assertSessionHasInput('message', 'Перезвоните вечером');
});

it('queues a notification for every captured lead', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload())->assertSessionHasNoErrors();

    Queue::assertPushed(NotifyManagerAboutLead::class);
});

it('stops accepting leads from one address past the configured limit', function () {
    Queue::fake();

    $limit = (int) config('leads.rate_limit.per_minute');

    expect($limit)->toBeGreaterThan(0);

    for ($i = 0; $i < $limit; $i++) {
        $this->post(route('leads.store'), leadPayload(['name' => 'Иван '.$i]))
            ->assertSessionHasNoErrors();
    }

    expect(Lead::query()->count())->toBe($limit);

    // Отказ — редирект с ошибкой на форме, а не голый 429: за одним IP
    // сидит офис или мобильный оператор, и живой человек должен увидеть
    // «попробуйте через минуту» на форме.
    $this->post(route('leads.store'), leadPayload(['name' => 'Иван сверх лимита']))
        ->assertRedirect()
        ->assertSessionHasErrors('phone');

    expect(Lead::query()->count())->toBe($limit);
});

/*
 * Согласие на обработку персональных данных (веха 4.17).
 *
 * Заявка без согласия — это персональные данные, собранные без основания.
 * Записанное согласие без времени и редакции документа — доказательство,
 * которое нечем предъявить.
 */

it('refuses a lead without consent', function () {
    Queue::fake();

    $this->post(route('leads.store'), leadPayload(['consent' => null]))
        ->assertRedirect()
        ->assertSessionHasErrors('consent');

    expect(Lead::query()->count())->toBe(0);
});

it('refuses a lead when the consent field is absent entirely', function () {
    Queue::fake();

    // Именно так приходит форма с неотмеченным чекбоксом: браузер
    // не кладёт его в запрос вовсе, и `new FormData(form)` в `lead-form.js`
    // ведёт себя так же. Правило `accepted` обязано ловить оба случая.
    $payload = leadPayload();
    unset($payload['consent']);

    $this->post(route('leads.store'), $payload)
        ->assertRedirect()
        ->assertSessionHasErrors('consent');

    expect(Lead::query()->count())->toBe(0);
});

it('stamps the moment of consent and the policy version', function () {
    Queue::fake();

    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст.</p>',
        'version' => '2.4',
        'effective_on' => '01.09.2026',
    ]);

    $this->post(route('leads.store'), leadPayload())->assertRedirect();

    $lead = Lead::query()->sole();

    expect($lead->consented_at)->not->toBeNull()
        ->and($lead->consent_policy_version)->toBe('2.4');
});

it('still accepts the lead when the policy version was cleared', function () {
    Queue::fake();

    // Отказать человеку из-за пустого поля в админке — худшее из решений:
    // доказательством согласия остаётся `consented_at`, а пустую редакцию
    // видно и в карточке заявки, и в записи канала `leads`.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст.</p>',
        'version' => '',
        'effective_on' => '01.09.2026',
    ]);

    $this->post(route('leads.store'), leadPayload())->assertRedirect();

    $lead = Lead::query()->sole();

    expect($lead->consented_at)->not->toBeNull()
        ->and($lead->consent_policy_version)->toBeNull();
});

/*
 * Выключатель приёма заявок (веха 4.17).
 *
 * Уведомление об обработке персональных данных подаётся в Роскомнадзор
 * ДО начала обработки, а обработка начинается с первой принятой заявки.
 */

it('refuses to accept a lead while intake is switched off', function () {
    Queue::fake();

    Setting::set(LeadIntake::SETTING_KEY, false);

    // Проверяется ОТСУТСТВИЕ СТРОКИ, а не только код ответа: спрятать форму
    // в разметке недостаточно — роут остаётся публичным, и заявка придёт
    // с закешированной страницы, из вчерашней вкладки или из curl.
    $this->post(route('leads.store'), leadPayload())->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('refuses to accept a lead when the intake setting does not exist at all', function () {
    Queue::fake();

    // Умолчание в коде — «выключено». На проде до
    // `laocars:install-legal-settings` строки настройки не будет вовсе,
    // и именно это состояние обязано означать «приём закрыт», а не «можно».
    Setting::query()->where('key', LeadIntake::SETTING_KEY)->delete();
    Setting::flushCache();

    $this->post(route('leads.store'), leadPayload())->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('writes the refusal to the log without personal data', function () {
    Queue::fake();

    Setting::set(LeadIntake::SETTING_KEY, false);

    // Прогрев до мока: промах кеша настроек пишет собственный DEBUG.
    warmSettingsCache();

    Log::spy();

    $this->post(route('leads.store'), leadPayload())->assertForbidden();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'приём заявок выключен')
            // Ни имени, ни телефона: записи ровно столько, чтобы отличить
            // «никто не пишет» от «пишут, но мы отказываем».
            && array_keys($context) === ['ip', 'referer'])
        ->atLeast()->once();
});
