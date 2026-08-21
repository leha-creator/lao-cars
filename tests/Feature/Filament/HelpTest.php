<?php

/*
 * Раздел «Справка» в панели (веха 4.8).
 *
 * Две группы проверок, и вторая не менее важна первой.
 *
 * Доступ: список, отфильтрованный правильно, и страница, забывшая
 * проверку, — типовая пара, где вторая половина обнаруживается только
 * подбором адреса. Поэтому каждая закрытая статья проверяется дважды:
 * что её нет в списке И что прямой переход даёт отказ.
 *
 * Целостность: реестр и файлы — два независимых источника, между
 * которыми нет ни одной автоматической связи. Битая ссылка в справке
 * не роняет ничего и обнаруживается глазами того, кто пришёл
 * за инструкцией, — то есть в худший возможный момент.
 */

use App\Filament\Actions\HelpAction;
use App\Filament\Pages\Help\Article;
use App\Filament\Pages\Help\Parameters;
use App\Filament\Pages\Help\Scenarios;
use App\Models\User;
use App\Services\HelpContent;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpLibrary;
use App\Support\Help\HelpSection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

/*
|--------------------------------------------------------------------------
| Доступ
|--------------------------------------------------------------------------
*/

it('redirects a guest from the help section to the login page', function () {
    $this->get(Scenarios::getUrl())->assertRedirect('/admin/login');
    $this->get(Article::getUrl(['article' => 'first-steps']))->assertRedirect('/admin/login');
});

it('shows an admin every article of both sections', function () {
    $this->actingAs(User::factory()->create());

    $scenarios = $this->get(Scenarios::getUrl())->assertOk();

    foreach (HelpLibrary::inSection(HelpSection::Scenarios) as $article) {
        $scenarios->assertSee($article->title);
    }

    $settings = $this->get(Parameters::getUrl())->assertOk();

    foreach (HelpLibrary::inSection(HelpSection::Settings) as $article) {
        $settings->assertSee($article->title);
    }
});

/*
 * Состав закреплён числами отдельно от цикла выше: статья, выпавшая
 * из реестра, прошла бы тот цикл вхолостую — проверять было бы нечего.
 */
it('registers exactly the planned number of articles per section', function () {
    // 8 → 9 вехой 4.15: в «Сценарии работы» добавлена «Обновить контакты
    // компании». Число правится вместе с реестром намеренно — оно и есть
    // тот сторож, который замечает статью, молча выпавшую из реестра.
    //
    // 9 → 11 и 7 → 11 вехой 4.16 по итогам обхода панели против реестра.
    // В «Сценарии работы» добавлены «Автомобиль продан» и «Собрать
    // страницу „О компании“», в «Настройку параметров» — «Марки
    // автомобилей», «Категории услуг», «Тексты страницы „О компании“»
    // и «Свой профиль и пароль». Шесть экранов панели не были описаны
    // ни одной статьёй; самый заметный пропуск — седьмая вкладка
    // настроек сайта, единственная без своей статьи.
    expect(HelpLibrary::inSection(HelpSection::Scenarios))->toHaveCount(11)
        ->and(HelpLibrary::inSection(HelpSection::Settings))->toHaveCount(11);
});

it('hides closed articles from a manager in the section list', function () {
    $this->actingAs(User::factory()->manager()->create());

    $response = $this->get(Parameters::getUrl())->assertOk();

    // Видно: у статьи либо нет ключа доступа, либо ключ пускает менеджера.
    $response->assertSee('Уведомления о новых заявках');
    $response->assertSee('Характеристики автомобилей');

    // Не видно: всё, что живёт за настройками сайта и пользователями.
    $response->assertDontSee('Блоки главной страницы');
    $response->assertDontSee('Тексты автосервиса и запчастей');
    $response->assertDontSee('Контакты, соцсети и подвал');
    $response->assertDontSee('Заголовки и описания для поиска');
    $response->assertDontSee('Сотрудники и роли');
});

/*
 * Второй раздел проверяется отдельным сторожем с вехи 4.15, и до неё
 * его не существовало по простой причине: в «Сценариях работы» не было
 * НИ ОДНОЙ статьи, закрытой от менеджера, — скрывать там было нечего.
 * «Обновить контакты компании» стала первой: всё, что она описывает,
 * правится на странице настроек сайта, куда менеджер не ходит.
 */
it('hides closed articles from a manager in the scenarios list', function () {
    $this->actingAs(User::factory()->manager()->create());

    $response = $this->get(Scenarios::getUrl())->assertOk();

    $response->assertSee('С чего начать');
    $response->assertSee('Работа с заявкой');

    $response->assertDontSee('Обновить контакты компании');
});

it('forbids a manager from opening a closed article by its direct address', function (string $slug) {
    $this->actingAs(User::factory()->manager()->create());

    $this->get(Article::getUrl(['article' => $slug]))->assertForbidden();
})->with([
    'home-blocks',
    'service-pages-texts',
    'contacts-and-footer',
    // Веха 4.15. Статья живёт в «Сценариях работы», но ключ доступа
    // у неё от настроек сайта — набор закрытых слагов перестал
    // совпадать с составом одного раздела.
    'contacts-update',
    'seo-defaults',
    'staff-and-roles',
    'price-list',
    'reviews-moderation',
    'team-page',
    'media-library',
    // Веха 4.16. `about-page-update` — второй после `contacts-update`
    // случай, когда статья живёт в «Сценариях работы», а ключ доступа
    // у неё от настроек сайта: раздел выбирается по вопросу читателя,
    // ключ — по разделу панели, который статья описывает.
    'about-page-update',
    'about-page-texts',
    // Справочник категорий услуг закрыт менеджеру не как настройка,
    // а как контент сайта — так же, как позиции прайса
    // (`ServiceCategoryPolicy` наследует `AdminOnlyPolicy`).
    'service-categories',
]);

it('opens articles a manager is allowed to read', function (string $slug) {
    $this->actingAs(User::factory()->manager()->create());

    $this->get(Article::getUrl(['article' => $slug]))->assertOk();
})->with([
    'first-steps',
    'lead-processing',
    'notifications-setup',
    'car-publishing',
    'car-photos',
    'car-attributes',
    // Веха 4.16. Марки открыты менеджеру вместе с остальным каталогом:
    // без права завести марку он не добавит автомобиль, которого нет
    // в сидах (`BrandPolicy` наследует `StaffPolicy`).
    'car-brands',
    'car-sold',
    // Ключа доступа у статьи про профиль нет: свой профиль правит любой
    // вошедший сотрудник.
    'profile-and-password',
]);

it('404s an unknown article slug', function () {
    $this->actingAs(User::factory()->create());

    $this->get(Article::getUrl(['article' => 'нет-такой-статьи']))->assertNotFound();
});

/*
 * Сторож решения «проверка в booted(), а не в mount()».
 *
 * Livewire применяет `set()` уже ПОСЛЕ гидрации: к моменту подмены
 * свойства проверка успела отработать на старом, разрешённом значении.
 * Проверка, поставленная только в `mount()`, этот тест провалит —
 * как и любой кеш результата без ключа по слагу.
 */
it('forbids swapping the article property to a closed article', function () {
    $this->actingAs(User::factory()->manager()->create());

    livewire(Article::class, ['article' => 'lead-processing'])
        ->assertOk()
        ->assertSee('Работа с заявкой')
        ->set('article', 'home-blocks')
        ->assertForbidden();
});

it('404s when the article property is swapped to an unknown slug', function () {
    $this->actingAs(User::factory()->create());

    livewire(Article::class, ['article' => 'first-steps'])
        ->set('article', 'нет-такой-статьи')
        ->assertNotFound();
});

it('hides closed articles from the "see also" block', function () {
    $this->actingAs(User::factory()->manager()->create());

    // У «Уведомлений» в связанных — «Работа с заявкой» (менеджеру видна)
    // и «С чего начать» (общая). У «С чего начать» связанных закрытых нет,
    // поэтому проверяем на статье с гарантированно смешанным набором.
    $this->get(Article::getUrl(['article' => 'car-publishing']))
        ->assertOk()
        ->assertSee('Фотографии автомобиля')
        ->assertSee('Характеристики автомобилей');

    // «Медиабиблиотека» связана с «Фотографиями автомобиля» и «Блоками
    // главной». Менеджеру сама статья закрыта, поэтому смотрим с обратной
    // стороны: у «Фотографий» в связанных есть закрытая «Медиабиблиотека».
    $this->get(Article::getUrl(['article' => 'car-photos']))
        ->assertOk()
        ->assertSee('Добавить автомобиль в каталог')
        ->assertDontSee('Медиабиблиотека');
});

it('shows the "see also" block in full to an admin', function () {
    $this->actingAs(User::factory()->create());

    $this->get(Article::getUrl(['article' => 'car-photos']))
        ->assertOk()
        ->assertSee('Медиабиблиотека');
});

/*
|--------------------------------------------------------------------------
| Точки входа
|--------------------------------------------------------------------------
*/

it('puts the help group with both sections into the panel navigation', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Справка')
        ->assertSee('Сценарии работы')
        ->assertSee('Настройка параметров');
});

it('refuses to build a help action for a slug that is not registered', function () {
    HelpAction::make('нет-такой-статьи');
})->throws(InvalidArgumentException::class);

/*
|--------------------------------------------------------------------------
| Целостность реестра и файлов
|--------------------------------------------------------------------------
*/

it('has a non-empty file for every registered article', function () {
    foreach (HelpLibrary::all() as $article) {
        $path = resource_path("help/{$article->file()}");

        expect(is_file($path))->toBeTrue("Нет файла статьи «{$article->slug}»: {$article->file()}");
        expect(trim((string) file_get_contents($path)))->not->toBe('', "Файл статьи «{$article->slug}» пуст.");
    }
});

/*
 * Обратная сторона предыдущей проверки: она ловит осиротевший текст,
 * оставшийся в папке после переименования слага. Такой файл не показывается
 * нигде и не мешает ничему — именно поэтому он и живёт годами.
 */
it('registers every file lying in the help directory', function () {
    $registered = array_map(
        static fn (HelpArticle $article): string => $article->file(),
        HelpLibrary::all(),
    );

    foreach (File::files(resource_path('help')) as $file) {
        expect($file->getFilename())->toBeIn($registered);
    }
});

it('points every related slug at a registered article', function () {
    foreach (HelpLibrary::all() as $article) {
        foreach ($article->related as $slug) {
            expect(HelpLibrary::find($slug))
                ->not->toBeNull("У статьи «{$article->slug}» связанная статья «{$slug}» не найдена в реестре.");
        }
    }
});

/*
 * Заголовок первого уровня печатала бы и страница, и сам файл. Ошибка
 * рефлекторная: текст в docs/ начинается именно с «#», и копирование
 * оттуда приносит его с собой.
 */
it('starts no article file with a first-level heading', function () {
    foreach (File::files(resource_path('help')) as $file) {
        $first = strtok(trim((string) file_get_contents($file->getPathname())), "\n");

        expect($first)->not->toStartWith('# ', "Файл {$file->getFilename()} начинается с заголовка первого уровня.");
    }
});

it('uses an existing gate class with canAccess() for every article', function () {
    foreach (HelpLibrary::all() as $article) {
        if ($article->gate === null) {
            continue;
        }

        expect(class_exists($article->gate))
            ->toBeTrue("Ключ доступа статьи «{$article->slug}» — несуществующий класс {$article->gate}.");

        expect(method_exists($article->gate, 'canAccess'))
            ->toBeTrue("У ключа доступа статьи «{$article->slug}» нет метода canAccess().");
    }
});

it('keeps article slugs unique', function () {
    $slugs = array_map(
        static fn (HelpArticle $article): string => $article->slug,
        HelpLibrary::all(),
    );

    expect(array_unique($slugs))->toHaveCount(count($slugs));
});

/*
|--------------------------------------------------------------------------
| Снимки экрана (веха 4.15)
|--------------------------------------------------------------------------
|
| До вехи 4.15 скриншотов в справке не было намеренно, и записанное
| основание звучало так: снимок устаревает на первой правке вёрстки
| панели, а переснимать его не будет никто. Заказчик попросил снимки
| прямо, то есть цена отсутствия наглядности оказалась выше.
|
| Аргумент не опровергнут — он ЗАМЕЩЁН, и замещают его в том числе эти
| сторожа. Они сверяют текст статей и папку со снимками в ОБЕ стороны:
| ссылка без файла и файл без ссылки одинаково роняют набор. Ровно так
| же устроена пара сторожей выше для самих статей.
|
| Отсюда же следует, почему пропавший снимок не пишется в лог
| (см. PHPDoc `HelpScreenshotController`): состояние «ссылка есть,
| файла нет» до продакшена не доезжает. Снимут эти сторожа — WARN
| придётся возвращать.
*/

/**
 * Все картинки, на которые ссылаются статьи: имя файла → список слагов.
 *
 * @return array<string, list<string>>
 */
function helpScreenshotReferences(): array
{
    $prefix = helpScreenshotUrlPrefix();
    $references = [];

    foreach (File::files(resource_path('help')) as $file) {
        $text = (string) file_get_contents($file->getPathname());
        $slug = $file->getBasename('.md');

        preg_match_all('/!\[[^\]]*\]\(([^)]+)\)/u', $text, $matches);

        foreach ($matches[1] as $url) {
            $name = str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : $url;
            $references[$name][] = $slug;
        }
    }

    return $references;
}

/**
 * Адресный префикс раздачи снимков, взятый У САМОГО МАРШРУТА.
 *
 * Не константа со строкой: тогда переименование маршрута расходилось бы
 * с текстами статей молча, а сторож ниже подтверждал бы согласие текста
 * сам с собой.
 */
function helpScreenshotUrlPrefix(): string
{
    // Заглушка ОБЯЗАНА быть латиницей: `route()` кодирует параметр
    // процентами, и кириллическое слово вернулось бы из адреса как
    // `%D0%98...`, а `str_replace()` его уже не нашёл бы. Префикс
    // при этом получился бы длиннее настоящего, и сторож ниже начал бы
    // ронять КАЖДУЮ картинку — что и произошло при написании.
    return str_replace('NAME', '', route('help.screenshot', ['name' => 'NAME'], absolute: false));
}

it('has a file for every screenshot referenced from an article', function () {
    $references = helpScreenshotReferences();

    expect($references)->not->toBeEmpty('Ни одна статья не ссылается на снимки — сторож проверяет пустоту.');

    foreach ($references as $name => $slugs) {
        $path = resource_path("help/screenshots/{$name}");

        expect(is_file($path))->toBeTrue(
            "Снимка «{$name}» нет на диске, а на него ссылается: ".implode(', ', $slugs).'.',
        );
    }
});

/*
 * Адрес картинки записан ровно в одной форме.
 *
 * Ловит опечатку `images/` вместо `image/` и относительный путь: обе
 * дают битую картинку МОЛЧА — разметка валидна, страница 200, на экране
 * рамка с подписью. Проверяются любые ссылки на `.png`, а не только те,
 * что уже начинаются с нужного префикса: иначе сторож подтверждал бы
 * только то, что и так верно.
 */
it('writes every screenshot address in exactly one form', function () {
    $prefix = helpScreenshotUrlPrefix();

    foreach (File::files(resource_path('help')) as $file) {
        $text = (string) file_get_contents($file->getPathname());

        preg_match_all('/\]\(([^)]*\.png[^)]*)\)/u', $text, $matches);

        foreach ($matches[1] as $url) {
            expect($url)->toStartWith(
                $prefix,
                "В статье «{$file->getBasename('.md')}» адрес снимка «{$url}» не начинается с «{$prefix}».",
            );
        }
    }
});

/*
 * Обратная сторона первого сторожа: осиротевший снимок не показывается
 * нигде и не мешает ничему — именно поэтому он и живёт годами, занимая
 * место в истории репозитория, из которой его уже не убрать.
 */
it('references every screenshot lying in the screenshots directory', function () {
    $referenced = array_keys(helpScreenshotReferences());

    foreach (File::files(resource_path('help/screenshots')) as $file) {
        expect($file->getFilename())->toBeIn(
            $referenced,
            "Снимок «{$file->getFilename()}» не упомянут ни в одной статье.",
        );
    }
});

/*
 * Картинка без подписи бесполезна тому, у кого она не загрузилась,
 * и невидима для программы чтения с экрана. Пустой `alt` при этом
 * выглядит в исходнике статьи как заполненный — скобки на месте.
 */
it('gives every screenshot a non-empty alt text', function () {
    foreach (File::files(resource_path('help')) as $file) {
        $text = (string) file_get_contents($file->getPathname());

        preg_match_all('/!\[([^\]]*)\]\(([^)]+)\)/u', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $alt, $url]) {
            expect(trim($alt))->not->toBe(
                '',
                "В статье «{$file->getBasename('.md')}» у снимка «{$url}» пустая подпись.",
            );
        }
    }
});

/*
 * Потолок веса. Ловит полноэкранный снимок в несколько мегабайт ДО
 * того, как он попадёт в историю репозитория навсегда: удалить файл
 * из следующего коммита можно, из истории — нет.
 */
it('keeps every screenshot under the weight ceiling', function () {
    $ceiling = 300 * 1024;

    foreach (File::files(resource_path('help/screenshots')) as $file) {
        expect($file->getSize())->toBeLessThanOrEqual(
            $ceiling,
            "Снимок «{$file->getFilename()}» весит ".round($file->getSize() / 1024).' КБ при потолке 300 КБ.',
        );
    }
});

/*
|--------------------------------------------------------------------------
| Единственная запись раздела в лог
|--------------------------------------------------------------------------
*/

/*
 * Пропавший файл — единственный отказ справки, который снаружи выглядит
 * как «раздел поломался»: в реестре статья есть, в списке она есть,
 * а открыть нельзя. Без записи в лог он неразрешим.
 */
it('warns in the log when an article file has gone missing', function () {
    Log::spy();

    $article = new HelpArticle(
        slug: 'исчезнувшая-статья',
        title: 'Исчезнувшая статья',
        summary: 'Её файла нет на диске.',
        section: HelpSection::Scenarios,
    );

    expect(app(HelpContent::class)->render($article))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'пропал файл')
            && $context['slug'] === 'исчезнувшая-статья');
});

it('does not log anything while rendering an existing article', function () {
    Log::spy();

    $article = HelpLibrary::find('first-steps');

    expect(app(HelpContent::class)->render($article))->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

/*
|--------------------------------------------------------------------------
| Рендеринг
|--------------------------------------------------------------------------
*/

it('strips html and unsafe links out of an article', function () {
    $path = resource_path('help/first-steps.md');
    $original = (string) file_get_contents($path);

    file_put_contents($path, <<<'MARKDOWN'
        ## Заголовок

        <script>alert('нет')</script>

        [опасная ссылка](javascript:alert(1))
        MARKDOWN);

    try {
        $html = (string) app(HelpContent::class)->render(HelpLibrary::find('first-steps'));

        expect($html)
            ->not->toContain('<script>')
            ->and($html)->not->toContain('javascript:')
            ->and($html)->toContain('<h2>');
    } finally {
        file_put_contents($path, $original);
    }
});
