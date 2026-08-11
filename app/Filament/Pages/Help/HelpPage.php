<?php

declare(strict_types=1);

namespace App\Filament\Pages\Help;

use App\Filament\NavigationGroup;
use App\Services\HelpContent;
use App\Support\Help\HelpSection;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Общий предок страниц-списков справки.
 *
 * Абстрактный класс в папке страниц безопасен: `discoverComponents()`
 * обходит все файлы каталога, но делает `continue` на
 * `(new ReflectionClass($class))->isAbstract()` — то есть этот класс
 * в панели не зарегистрируется, а потомки рядом с ним подхватятся сами.
 *
 * Заголовок, подзаголовок и иконка приходят из самого раздела: второй
 * список подписей рядом с `HelpSection` разошёлся бы с ним на первой же
 * правке — причём молча, потому что обе строки выглядят правдоподобно.
 */
abstract class HelpPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Help;

    protected string $view = 'filament.pages.help.section';

    /**
     * Раздел, который печатает потомок.
     */
    abstract public static function section(): HelpSection;

    /**
     * Страница-список для раздела — обратная сторона `section()`.
     *
     * Живёт здесь, а не в `HelpSection`: enum лежит в `app/Support/`,
     * где по правилу зависимостей нет ни диска, ни внешнего мира,
     * и уж тем более нет знания о том, какими страницами Filament
     * этот раздел печатается.
     *
     * @return class-string<self>
     */
    public static function forSection(HelpSection $section): string
    {
        return match ($section) {
            HelpSection::Scenarios => Scenarios::class,
            HelpSection::Settings => Parameters::class,
        };
    }

    /**
     * Доступ — любому аутентифицированному сотруднику.
     *
     * Сама страница ничего не раскрывает: состав списка уже отфильтрован
     * `HelpContent::visible()` по ключам доступа статей, и закрытых
     * заголовков в нём нет. Закрывать раздел целиком значило бы прятать
     * от менеджера и те шесть статей, которые написаны для него.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return static::section()->icon();
    }

    /**
     * Подпись в меню — статическая, и `getTitle()` её не заменяет.
     *
     * `Page::getNavigationLabel()` читает статические `$navigationLabel`
     * и `$title`, а не одноимённый метод экземпляра: незаданные, они
     * дают имя класса словами — «Scenarios» и «Parameters» посреди
     * русского меню. Ошибка тихая ровно настолько, насколько тихо
     * английское слово в списке из шести русских.
     */
    public static function getNavigationLabel(): string
    {
        return static::section()->label();
    }

    public function getTitle(): string|Htmlable
    {
        return static::section()->label();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return static::section()->description();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'articles' => app(HelpContent::class)->visible(static::section()),
        ];
    }
}
