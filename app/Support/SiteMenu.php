<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Состав навигации сайта — единственный источник правды (веха 4.1).
 *
 * Живёт в `app/Support/`, а не константой на компоненте шапки, по правилу
 * зависимостей из `ARCHITECTURE.md`: список нужен и шапке, и подвалу, и ни
 * один из них не должен знать о другом. Тот же приём, что и в
 * `MediaSettingKeys` и `ThumbnailPath`.
 *
 * Второй список завести проще, чем кажется, — и он разойдётся с первым на
 * первом же новом разделе. Ровно по этой причине веха 3.7 сделала одну форму
 * заявки на три сценария вместо трёх копий.
 *
 * **Состав меню берётся отсюда, а не из макета.** В шапке макета — «Каталог ·
 * Услуги · Блог · Контакты», и это устаревший вариант: блог из проекта снят
 * целиком, а его место заняли «Автосервис» и «Запчасти» (решение зафиксировано
 * в `DESCRIPTION.md` и в разделе «Ориентиры из макета» роадмапа). При сверке
 * вёрстки с макетом меню «поправят» обратно, если не знать этого.
 */
final class SiteMenu
{
    /**
     * Подписи разделов по именам роутов.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'catalog.index' => 'Каталог',
        'services.index' => 'Автосервис',
        'parts.index' => 'Запчасти',
        'contacts.index' => 'Контакты',
        'about.index' => 'О компании',
    ];

    /**
     * Закреплённое меню шапки — четыре пункта из ТЗ, в этом порядке.
     *
     * @var list<string>
     */
    private const array HEADER = [
        'catalog.index',
        'services.index',
        'parts.index',
        'contacts.index',
    ];

    /**
     * Подвал шире шапки на «О компании»: страница есть в макете и в ТЗ, но
     * в закреплённое меню не входит — трафик она не приносит, а пункт меню
     * стоит дорого.
     *
     * @var list<string>
     */
    private const array FOOTER = [
        'catalog.index',
        'services.index',
        'parts.index',
        'about.index',
        'contacts.index',
    ];

    /**
     * @return list<array{name: string, label: string, url: string}>
     */
    public static function header(): array
    {
        return self::links(self::HEADER);
    }

    /**
     * @return list<array{name: string, label: string, url: string}>
     */
    public static function footer(): array
    {
        return self::links(self::FOOTER);
    }

    /**
     * Пункты, у которых есть зарегистрированный роут.
     *
     * Пункт без роута выпадает, а не роняет страницу. Причина не в
     * снисходительности: шапка и подвал рендерятся на каждой странице сайта,
     * и переименованный роут положил бы весь сайт целиком, включая формы
     * заявок — единственное, ради чего сайт существует. Отсутствие пункта
     * ловится тестом до мержа (`LayoutTest` проверяет, что все ожидаемые
     * роуты зарегистрированы и попали в разметку), и это правильное место
     * для такой ошибки.
     *
     * Оно же закрывает переходный период: «О компании» появляется в подвале
     * вехой 4.5 вместе со своим роутом, и до тех пор пункт просто не
     * выводится — вместо ссылки в 404.
     *
     * @param  list<string>  $names
     * @return list<array{name: string, label: string, url: string}>
     */
    private static function links(array $names): array
    {
        $links = [];

        foreach ($names as $name) {
            if (! Route::has($name)) {
                continue;
            }

            $links[] = [
                'name' => $name,
                'label' => self::LABELS[$name],
                'url' => route($name),
            ];
        }

        return $links;
    }
}
