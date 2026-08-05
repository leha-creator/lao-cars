<?php

use App\Models\Lead;
use App\Models\Setting;
use Illuminate\Support\Facades\Queue;

/*
 * Страницы разделов закреплённого меню (веха 4.1).
 *
 * Пока это заглушки на каркасе: заголовок и вводный текст из настроек плюс
 * форма заявки. Наполнение приходит вехами 4.4 и 4.5 — но адреса и формы
 * работают уже сейчас, и именно это здесь проверяется.
 */

/*
 * Счётчик лимитера заявок живёт в Redis и переживает RefreshDatabase.
 * Правило `RULES.md` требует сброса в каждом файле, который отправляет
 * форму, а не только в том, где проверяется сам лимит: иначе падать
 * начинает произвольный соседний тест, и связь с причиной не читается.
 * Объяснение целиком — в `tests/Pest.php`.
 */
beforeEach(function (): void {
    resetRateLimiters();
});

it('serves every section page', function (string $uri) {
    $this->get($uri)->assertOk();
})->with(['/', '/services', '/parts', '/contacts']);

it('takes the services heading and intro from site settings', function () {
    Setting::set('services_page.intro_title', 'Наш автосервис');
    Setting::set('services_page.intro_text', 'Проверочное вступление автосервиса.');

    $this->get('/services')
        ->assertOk()
        ->assertSee('Наш автосервис')
        ->assertSee('Проверочное вступление автосервиса.');
});

it('takes the parts heading, intro and delivery terms from site settings', function () {
    Setting::set('parts_page.intro_title', 'Наши запчасти');
    Setting::set('parts_page.intro_text', 'Проверочное вступление запчастей.');
    Setting::set('parts_page.delivery_terms', 'Проверочные условия поставки.');

    $this->get('/parts')
        ->assertOk()
        ->assertSee('Наши запчасти')
        ->assertSee('Проверочное вступление запчастей.')
        ->assertSee('Проверочные условия поставки.');
});

it('shows contacts from site settings on the contacts page', function () {
    Setting::set('contacts.address', 'Москва, Тестовая, 2');
    Setting::set('socials.whatsapp', 'https://wa.me/70000000000');

    $this->get('/contacts')
        ->assertOk()
        ->assertSee('Москва, Тестовая, 2')
        ->assertSee('WhatsApp');
});

it('asks for part details only on the parts page', function () {
    // Флаг `:parts` на компоненте формы — единственное, что отличает заявку
    // на подбор запчасти от остальных (`Lead::isPartsRequest()`), и потерять
    // его при правке шаблона проще всего.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('name="part_vin"', escape: false);

    foreach (['/', '/services', '/contacts'] as $uri) {
        $this->get($uri)
            ->assertOk()
            ->assertDontSee('name="part_vin"', escape: false);
    }
});

it('captures a lead from every section page', function (string $uri) {
    Queue::fake();

    $this->from($uri)
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
        ])
        ->assertRedirect($uri)
        ->assertSessionHasNoErrors();

    // Проверка не декоративная: вёрстка вехи 4.1 переписала разметку формы,
    // и переименованное поле потеряло бы заявку молча.
    expect(Lead::query()->count())->toBe(1);
})->with(['/', '/services', '/parts', '/contacts']);

it('captures part details from the parts page form', function () {
    Queue::fake();

    $this->from('/parts')
        ->post(route('leads.store'), [
            'name' => 'Иван',
            'phone' => '+7 999 123-45-67',
            'part_brand' => 'Zeekr',
            'part_model' => '001',
            'part_vin' => 'XW8ZZZ61ZJG000001',
        ])
        ->assertRedirect('/parts')
        ->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->isPartsRequest())->toBeTrue()
        ->and($lead->part_vin)->toBe('XW8ZZZ61ZJG000001');
});
