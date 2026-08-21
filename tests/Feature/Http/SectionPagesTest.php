<?php

use App\Models\Lead;
use App\Models\Setting;
use App\Support\Typography;
use Illuminate\Support\Facades\Queue;

/*
 * Страницы разделов закреплённого меню (веха 4.1).
 *
 * Заглушками на каркасе они были при заведении; наполнение принесли вехи
 * 4.4 (автосервис, запчасти) и 4.5 («О компании»). Здесь проверяется
 * сквозное — то, что обязано работать на КАЖДОЙ публичной странице:
 * адрес отдаёт 200, заявка с неё доходит до базы, поля подбора запчасти
 * на чужие страницы не протекают.
 *
 * **Адреса перечислены руками, и новая страница сама в эти перечни
 * не попадает.** Именно поэтому веха 4.5 дописала `/about` в три места
 * явно: без этого набор остался бы зелёным, а единственная страница
 * без трёх сквозных сторожей — незамеченной. Заводя следующую публичную
 * страницу, пройдите по файлу поиском по `/contacts`.
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
})->with(['/', '/services', '/parts', '/about', '/contacts']);

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
    Setting::set('contacts.phone', '+7 999 000-11-22');
    Setting::set('contacts.email', 'test@laocars.ru');
    Setting::set('socials.whatsapp', 'https://wa.me/70000000000');

    // Ссылки, а не только подписи (веха 4.5). До неё номер и почта
    // на канонической странице контактов выводились текстом — при том
    // что в шапке и подвале были ссылками, — и нажатие с телефона,
    // главный сценарий этой страницы, не работало. Проверка на подписи
    // прошла бы и после отката к тексту.
    $this->get('/contacts')
        ->assertOk()
        ->assertSee('Москва, Тестовая, 2')
        ->assertSee('href="tel:+79990001122"', escape: false)
        ->assertSee('href="mailto:test@laocars.ru"', escape: false)
        ->assertSee('WhatsApp');
});

it('asks for part details only on the parts page', function () {
    // Флаг `:parts` на компоненте формы — единственное, что отличает заявку
    // на подбор запчасти от остальных (`Lead::isPartsRequest()`), и потерять
    // его при правке шаблона проще всего.
    $this->get('/parts')
        ->assertOk()
        ->assertSee('name="part_vin"', escape: false);

    foreach (['/', '/services', '/about', '/contacts'] as $uri) {
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
})->with(['/', '/services', '/parts', '/about', '/contacts']);

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

it('runs section page intros through typography', function () {
    // Веха 4.14, пункт 6: висячие слова лечатся не только `text-pretty`,
    // но и неразрывными пробелами — та рекомендация браузеру, а требование
    // заказчика названо числом слов.
    //
    // Проверяется ИМЕННО символ U+00A0: неразрывный пробел невидим и в
    // diff, и в редакторе, поэтому «выглядит правильно» здесь не работает.
    //
    // Тексты подобраны так, чтобы склейке было что делать: у фикстур
    // соседних тестов хвост слишком длинный, и они прошли бы и без
    // всякой типографики.
    Setting::set('services_page.intro_text', 'Свой сервис, а не партнёрская сеть');
    Setting::set('parts_page.intro_text', 'Подберём деталь по VIN и привезём');
    Setting::set('about_page.intro_text', 'Возим автомобили из Китая и Европы');
    // Веха 4.5: вступление `/contacts` переехало из шаблона в настройку
    // и с этого момента проходит ту же типографику, что и остальные три.
    Setting::set('contacts_page.intro_text', 'Приезжайте в шоу-рум или напишите');
    Setting::flushCache();

    foreach ([
        '/services' => 'а'.Typography::NBSP.'не',
        '/parts' => 'и'.Typography::NBSP.'привезём',
        '/about' => 'и'.Typography::NBSP.'Европы',
        '/contacts' => 'или'.Typography::NBSP.'напишите',
    ] as $url => $expected) {
        expect($this->get($url)->assertOk()->getContent())->toContain($expected);
    }
});

it('keeps the intro paragraph marked text-pretty on every section page', function () {
    // Склейка отвечает за хвост, `text-pretty` — за всё остальное
    // распределение слов по строкам. Одно другое не заменяет.
    //
    // Вступления задаются явно: пустая настройка убирает абзац целиком
    // (рабочий сценарий «блок выключен»), и без них сторож проверял бы
    // отсутствующий тег.
    //
    // `/contacts` в этом перечне с вехи 4.5. До неё вступление там жило
    // в шаблоне константой и попадало в проверку само; теперь оно —
    // такая же настройка, как у трёх соседей, и без строки ниже сторож
    // на четвёртой странице искал бы абзац, которого нет.
    Setting::set('services_page.intro_text', 'Проверочное вступление автосервиса.');
    Setting::set('parts_page.intro_text', 'Проверочное вступление запчастей.');
    Setting::set('about_page.intro_text', 'Проверочное вступление компании.');
    Setting::set('contacts_page.intro_text', 'Проверочное вступление контактов.');
    Setting::flushCache();

    foreach (['/services', '/parts', '/about', '/contacts'] as $url) {
        $content = $this->get($url)->assertOk()->getContent();

        preg_match('/<p class="mt-5[^"]*"/', $content, $intro);

        expect($intro)->not->toBeEmpty()
            ->and($intro[0])->toContain('text-pretty');
    }
});
