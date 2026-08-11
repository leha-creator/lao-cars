<?php

declare(strict_types=1);

namespace App\Filament\Pages\Help;

use App\Filament\NavigationGroup;
use App\Services\HelpContent;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpLibrary;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Одна статья справки.
 *
 * Единственная страница панели с параметром в адресе, и почти всё
 * необычное в ней — следствие именно этого.
 */
final class Article extends Page
{
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Help;

    protected static ?string $slug = 'help/article';

    protected string $view = 'filament.pages.help.article';

    /**
     * Пункта меню у страницы нет, и это не вопрос вкуса.
     *
     * Пункт меню строит URL маршрута, а у этого маршрута обязательный
     * параметр — построение упало бы исключением. Причём упало бы
     * на КАЖДОЙ странице панели, потому что боковое меню рендерится
     * везде: сломалась бы вся админка, а не справка.
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Слаг статьи из адреса.
     *
     * Публичное свойство: только такие Livewire переносит между запросами.
     * Оно же означает, что значение приходит с клиента и обязано
     * перепроверяться на каждом запросе — см. `article()`.
     */
    public string $article = '';

    private ?HelpArticle $resolved = null;

    private ?string $resolvedFor = null;

    /**
     * Адрес получает параметр, а имя маршрута остаётся производным
     * от слага (`…pages.help.article`).
     *
     * Сегмент `article` в пути ОБЯЗАТЕЛЕН, хотя выглядит лишним.
     * Короткий вариант `/help/{article}` совпадает по форме с адресами
     * страниц-списков (`/help/scenarios`, `/help/settings`), а порядок
     * маршрутов задаёт обнаружение страниц — то есть порядок файлов
     * в папке. `Article.php` идёт по алфавиту первым, его маршрут
     * встаёт раньше, и оба списка отдают 404: параметр съедает их
     * адреса, слаг `scenarios` в реестре не находится. Симптом при этом
     * не читается как конфликт маршрутов — страница просто «пропадает»,
     * причём та, которую никто не трогал.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/help/article/{article}';
    }

    public static function canAccess(): bool
    {
        // Доступ к КОНКРЕТНОЙ статье проверяется не здесь: `canAccess()`
        // статический и слага не видит. Всё, что можно спросить на этом
        // уровне, — вошёл ли человек в панель.
        return auth()->check();
    }

    public function mount(string $article): void
    {
        $this->article = $article;
    }

    /**
     * Проверка доступа живёт здесь, а НЕ в `canAccess()`.
     *
     * `CanAuthorizeAccess` зовёт статический `canAccess()` дважды —
     * `mountCanAuthorizeAccess()` и `hydrateCanAuthorizeAccess()`, —
     * но статический метод параметра маршрута не видит. `booted()` же
     * отрабатывает и после `mount()`, и после гидрации, то есть
     * значение, подменённое на клиенте, проверяется заново.
     */
    public function booted(): void
    {
        $this->article();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->article()->title;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->article()->summary;
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        $article = $this->article();

        return [
            'Справка',
            HelpPage::forSection($article->section)::getUrl() => $article->section->label(),
            $article->title,
        ];
    }

    /**
     * Кнопка «Перейти в раздел» — только когда у статьи задан ключ доступа.
     *
     * Проверять доступ у неё не нужно: ключ доступа и есть тот раздел,
     * у которого спрашивалась видимость статьи. Раз статья открылась,
     * раздел человеку доступен.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $gate = $this->article()->gate;

        if ($gate === null) {
            return [];
        }

        return [
            Action::make('section')
                ->label('Перейти в раздел')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url($gate::getUrl()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $article = $this->article();
        $content = app(HelpContent::class);

        $body = $content->render($article);

        // Файл статьи пропал: в реестре она есть, в списке есть, а текста
        // нет. WARN об этом уже написал сервис — страница только переводит
        // отказ в HTTP-код, потому что кодами занимается она.
        abort_if($body === null, 404);

        return [
            'body' => $body,
            'related' => array_values(array_filter(
                array_map(HelpLibrary::find(...), $article->related),
                // Закрытая для менеджера статья не должна светиться
                // заголовком в блоке «См. также»: список видимого — это
                // тоже информация о том, как устроена панель.
                static fn (?HelpArticle $related): bool => $related !== null
                    && app(HelpContent::class)->isVisible($related),
            )),
        ];
    }

    /**
     * Статья, за которой пришли, — с проверкой доступа на каждом запросе.
     *
     * Неизвестный слаг — 404, закрытый — 403. 404 и 403 не логируются:
     * подобранный вручную адрес — это шум того же класса, что и отказы
     * авторизации, которые проект намеренно не пишет.
     *
     * Результат запоминается ПО ЗНАЧЕНИЮ СЛАГА, а не просто «один раз
     * за запрос», и вот почему. Livewire применяет `$wire.set()` уже
     * ПОСЛЕ `hydrate()` и `booted()`: к моменту подмены свойства проверка
     * в `booted()` отработала на старом, разрешённом значении. Кеш без
     * ключа сделал бы её единственной, и подменённый слаг доехал бы
     * до рендера непроверенным.
     */
    private function article(): HelpArticle
    {
        if ($this->resolvedFor === $this->article && $this->resolved !== null) {
            return $this->resolved;
        }

        $article = HelpLibrary::find($this->article);

        abort_if($article === null, 404);
        abort_unless(app(HelpContent::class)->isVisible($article), 403);

        $this->resolvedFor = $this->article;

        return $this->resolved = $article;
    }
}
