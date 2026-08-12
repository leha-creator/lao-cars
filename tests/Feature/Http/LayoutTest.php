<?php

use App\Models\Car;
use App\Models\Setting;
use App\Support\SiteMenu;
use Illuminate\Support\Facades\Route;

/*
 * Каркас публичной части (веха 4.1).
 *
 * Шапка и подвал рендерятся на каждой странице сайта, поэтому их поломка
 * ломает всё сразу — включая формы заявок, ради которых сайт существует.
 */

it('shows the phone from site settings in the header', function () {
    Setting::set('contacts.phone', '+7 000 000-00-00');

    // Настройка меняется в тесте намеренно: проверка сида показала бы, что
    // сид работает, а не что шапка читает настройки.
    $this->get('/')
        ->assertOk()
        ->assertSee('+7 000 000-00-00')
        ->assertSee('tel:+70000000000');
});

it('shows contacts, socials and the guarantee block in the footer', function () {
    Setting::set('contacts.address', 'Москва, Тестовая, 1');
    Setting::set('contacts.email', 'test@laocars.test');
    Setting::set('contacts.work_hours', 'Круглосуточно');
    Setting::set('socials.telegram', 'https://t.me/test-laocars');
    Setting::set('footer.guarantee', [
        'title' => 'Гарантия 45 дней',
        'text' => 'Проверочный текст гарантии.',
        'link_text' => 'Условия',
        'link_url' => '/services',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Москва, Тестовая, 1')
        ->assertSee('test@laocars.test')
        ->assertSee('Круглосуточно')
        ->assertSee('https://t.me/test-laocars', escape: false)
        ->assertSee('Telegram')
        ->assertSee('Гарантия 45 дней')
        ->assertSee('Проверочный текст гарантии.');
});

it('omits a social link instead of rendering an empty one', function () {
    Setting::set('socials.vk', '');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('ВКонтакте')
        // Пустой `href` увёл бы посетителя на текущую страницу, и ссылка
        // выглядела бы рабочей.
        ->assertDontSee('href=""', escape: false);
});

it('omits the phone block instead of rendering an empty tel link', function () {
    Setting::set('contacts.phone', '');

    $this->get('/')
        ->assertOk()
        ->assertDontSee('tel:"', escape: false);
});

it('links every menu item to a registered route', function () {
    // Сторож против мёртвых ссылок. `SiteMenu` выводит только пункты
    // с зарегистрированным роутом — это защищает сайт от падения, но
    // означает, что удалённый роут просто исчезает из меню. Ловится
    // ошибка здесь, до мержа, а не жалобой на пропавший раздел.
    $expected = ['catalog.index', 'services.index', 'parts.index', 'contacts.index'];

    foreach ($expected as $name) {
        expect(Route::has($name))->toBeTrue("роут {$name} не зарегистрирован");
    }

    expect(array_column(SiteMenu::header(), 'name'))->toBe($expected);

    $response = $this->get('/')->assertOk();

    // «Автомобили», а не «Каталог»: подпись пункта переименована заказчиком
    // на демонстрации 11.08.2026. Название самого раздела осталось прежним —
    // см. docblock `SiteMenu`, там разобрано, почему это не рассинхрон.
    foreach (['Автомобили', 'Автосервис', 'Запчасти', 'Контакты'] as $label) {
        $response->assertSee($label);
    }
});

it('marks the catalog menu item active on the catalog and on a car page', function () {
    $car = Car::factory()->create();

    // Второй случай и есть весь смысл сравнения по имени роута с маской:
    // `/catalog/{car}` — другой адрес, но тот же раздел, и сравнение
    // по `url()->current()` подсветку бы не дало.
    foreach (['/catalog', '/catalog/'.$car->slug] as $uri) {
        $this->get($uri)
            ->assertOk()
            ->assertSee('aria-current="page"', escape: false);
    }
});

it('does not highlight any menu item on the home page', function () {
    // Главной в закреплённом меню нет — подсвечивать нечего.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('aria-current="page"', escape: false);
});

it('serves fonts from its own domain', function () {
    // Макет подключает Unbounded и Manrope тегом <link> с Google Fonts.
    // На живом сайте это блокирующий запрос к чужому домену на критическом
    // пути рендера и передача IP каждого посетителя третьей стороне.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com')
        ->assertDontSee('fonts.bunny.net');
});

it('preloads the self-hosted fonts before the stylesheet', function () {
    // Тест зелёный только после `npm run build`: `Vite::fonts()` при
    // отсутствующем манифесте шрифтов возвращает пустую строку и не бросает
    // исключение, в отличие от `@vite`. CI собирает фронтенд до тестов —
    // это то же условие, на котором держится `SmokeTest`.
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('as="font"');

    $preload = strpos($html, 'as="font"');
    $stylesheet = strpos($html, 'rel="stylesheet"');

    expect($stylesheet)->not->toBeFalse()
        ->and($preload)->toBeLessThan($stylesheet);
});

it('reads site settings once per request, not once per component', function () {
    // Шапка, подвал и сама страница обращаются к настройкам независимо.
    // Все три идут через кеш `Setting`, поэтому на холодном кеше вся
    // страница стоит ровно один запрос к `site_settings`. Двойка здесь
    // означала бы, что кто-то читает настройки мимо кеша, и цена
    // размазалась бы по всем страницам сайта сразу.
    $cold = countQueries(fn () => $this->get('/contacts')
        ->assertOk()
        // Ответ проверяется по содержимому: счётчик запросов у страницы,
        // которая не отрендерилась, тоже был бы маленьким.
        ->assertSee('Контакты'));

    expect($cold)->toBe(1);

    $warm = countQueries(fn () => $this->get('/contacts')->assertOk());

    expect($warm)->toBe(0);
});
