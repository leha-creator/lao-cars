<?php

use App\Models\Setting;
use App\Support\Legal\PrivacyPolicy;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Support\Facades\Log;

/*
 * Страница политики обработки персональных данных (веха 4.17).
 *
 * Три места, где ошибка молчит и обнаруживается уже юристом или проверкой:
 * очищенный текст (страница выглядит недоделанной, а не сломанной),
 * оставшиеся в тексте плейсхолдеры реквизитов и форма заявки, случайно
 * заехавшая на страницу, которая объясняет, как обрабатываются данные.
 */

beforeEach(function (): void {
    $this->seed(SiteSettingSeeder::class);
});

it('serves the policy page with the stored text', function () {
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<h2>Раздел про обработку</h2><p>Текст политики.</p>',
        'version' => '2.1',
        'effective_on' => '01.09.2026',
    ]);

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertSee('Раздел про обработку')
        ->assertSee('Текст политики.')
        ->assertSee('Редакция 2.1')
        ->assertSee('действует с 01.09.2026');
});

it('keeps the policy markup instead of escaping it', function () {
    // Текст приходит из RichEditor размеченным. Печать через `{{ }}`
    // показала бы посетителю сами теги — и выглядело бы это как испорченный
    // документ, а не как ошибка вывода.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<h2>Заголовок</h2><ul><li><p>Пункт</p></li></ul>',
        'version' => '1.0',
        'effective_on' => '26.08.2026',
    ]);

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertSee('<h2>Заголовок</h2>', escape: false)
        ->assertSee('<li><p>Пункт</p></li>', escape: false);
});

it('strips markup the editor cannot produce', function () {
    // Значение поля RichEditor — сырой HTML, и перехватить запрос к панели
    // может тот, у кого есть доступ к ней. Ограниченный набор инструментов
    // живёт в браузере и запретом не является: защита — `RichContentRenderer`
    // в `PrivacyPageContent`, и это прямое требование шапки самого
    // `Filament\Forms\Components\RichEditor`.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст</p><script>alert(1)</script><iframe src="https://example.com"></iframe>',
        'version' => '1.0',
        'effective_on' => '26.08.2026',
    ]);

    $response = $this->get(route('privacy.index'))->assertOk();

    expect($response->getContent())
        ->toContain('<p>Текст</p>')
        ->not->toContain('alert(1)')
        ->not->toContain('<iframe');
});

it('serves the page and warns in the log when the text is empty', function () {
    // 404 здесь запрещён: на этот адрес ведут подвал и чекбокс согласия
    // в каждой форме сайта, и битая обязательная ссылка хуже пустой
    // страницы. Заголовок остаётся, блок текста не рендерится вовсе.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '',
        'version' => '1.0',
        'effective_on' => '26.08.2026',
    ]);

    Log::spy();

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertSee('Политика обработки персональных данных')
        ->assertDontSee('prose-legal');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'текст политики пуст'))
        ->atLeast()->once();
});

it('warns in the log when operator details are still placeholders', function () {
    // Единственный автоматический способ узнать, что документ уехал в прод
    // с `[[ИНН]]` внутри: страницу открывают редко, а читают ещё реже.
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Оператор: [[НАИМЕНОВАНИЕ ОПЕРАТОРА]], ИНН [[ИНН]].</p>',
        'version' => '1.0',
        'effective_on' => '26.08.2026',
    ]);

    Log::spy();

    $this->get(route('privacy.index'))->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'незаполненные реквизиты')
            // Имена реквизитов в записи обязательны: «остались плейсхолдеры»
            // без списка заставляет открывать страницу и искать их глазами.
            && $context['placeholders'] === ['[[НАИМЕНОВАНИЕ ОПЕРАТОРА]]', '[[ИНН]]'])
        ->atLeast()->once();
});

it('does not warn about placeholders when the text is filled in', function () {
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Оператор: ООО «ЛАО КАРС», ИНН 7712345678.</p>',
        'version' => '1.0',
        'effective_on' => '26.08.2026',
    ]);

    Log::spy();

    $this->get(route('privacy.index'))->assertOk();

    Log::shouldNotHaveReceived('warning');
});

it('hides the version and the date separately when either is cleared', function () {
    // Очищенное поле настройки не должно давать «Редакция , действует с».
    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст.</p>',
        'version' => '',
        'effective_on' => '01.09.2026',
    ]);

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertDontSee('Редакция')
        ->assertSee('действует с 01.09.2026');

    Setting::set(PrivacyPolicy::SETTING_KEY, [
        'body' => '<p>Текст.</p>',
        'version' => '3.0',
        'effective_on' => '',
    ]);

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertSee('Редакция 3.0')
        ->assertDontSee('действует с');
});

it('carries no lead form of its own', function () {
    // Единственная страница сайта без секции заявки, и это намеренно:
    // форма под текстом политики — это предложение оставить персональные
    // данные прямо на странице, объясняющей, как их обрабатывают.
    enableLeadForms();

    $this->get(route('privacy.index'))
        ->assertOk()
        ->assertDontSee('id="lead-form"', escape: false)
        ->assertDontSee(route('leads.store'), escape: false);
});

it('is linked from the footer of every page', function () {
    // Ссылка живёт в нижней строке подвала, а подвал рендерится на каждой
    // странице. Мёртвый текст без адреса стоял там с вехи 4.1.
    enableLeadForms();

    foreach (['/', '/catalog', '/services', '/parts', '/about', '/contacts'] as $uri) {
        $this->get($uri)
            ->assertOk()
            ->assertSee('href="'.route('privacy.index').'"', escape: false)
            ->assertSee('Политика обработки персональных данных');
    }
});
