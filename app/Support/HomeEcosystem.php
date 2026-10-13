<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Блок «Экосистема» главной: умолчания и закрытые списки значков и адресов.
 *
 * Тексты блока жили в шаблоне до первой просьбы заказчика их править —
 * триггер был назван в `docs/homepage.md` заранее. Теперь они приходят
 * из настройки `home.ecosystem`, а прежние строки шаблона переехали сюда
 * дословно и стали умолчаниями.
 *
 * **Умолчания живут здесь, а не в шаблоне и не в сиде**, потому что нужны
 * троим: сиду (значение свежезасеянной базы), форме настроек (на проде
 * ключа нет до первого сохранения, и секция обязана открыться с тем же,
 * что показывает сайт) и `HomeContent` (пустое поле возвращает текст
 * по умолчанию, блок не бывает пустым). Три копии одних и тех же строк
 * разошлись бы на первой правке. Тот же приём, что у `SiteMenu`
 * и `MediaSettingKeys`: данные нужны и нижнему слою, и верхнему.
 *
 * **Значок и адрес плашки — закрытые списки**, и сверяет их не только
 * форма, но и `HomeContent` на выводе:
 *
 * - `x-ui.icon` на неизвестном имени бросает исключение, то есть опечатка
 *   в jsonb уронила бы главную целиком;
 * - свободная ссылка потребовала бы проверки схемы и сняла бы сторожа
 *   якорей категорий, заведённого вехой 4.13.
 *
 * **Категория адресуется слагом**, а не id: умолчания обязаны ссылаться
 * на блоки «Автосервис» и «Детейлинг», не зная их id, а якорь блока
 * на `/services` и есть слаг. Цена записана в `HomeContent::ecosystem()`:
 * переименованный слаг даёт ссылку без якоря и предупреждение в лог.
 */
final class HomeEcosystem
{
    public const string SETTING_KEY = 'home.ecosystem';

    /**
     * Потолок числа плашек.
     *
     * Сетка в две колонки стоит рядом с фотографией фиксированной высоты
     * и рассчитана на четыре плашки; при шести правая колонка уже выше
     * кадра, дальше блок разваливается.
     */
    public const int MAX_TILES = 6;

    /**
     * Значок вместо неизвестного. Нейтральный: не обещает ни сервиса,
     * ни звонка.
     */
    public const string DEFAULT_ICON = 'check-circle';

    /**
     * Адрес вместо неизвестного — форма заявки на той же странице.
     */
    public const string DEFAULT_LINK = 'lead';

    /**
     * Префикс адреса, ведущего в блок категории на странице автосервиса:
     * `category:<slug>`.
     */
    public const string CATEGORY_PREFIX = 'category:';

    /**
     * Статические адреса: подпись для выпадающего списка и роут либо якорь.
     *
     * @var array<string, array{label: string, route: ?string, anchor: ?string}>
     */
    private const array PAGE_LINKS = [
        'lead' => ['label' => 'Форма заявки на этой странице', 'route' => null, 'anchor' => '#lead-form'],
        'services' => ['label' => 'Страница «Автосервис»', 'route' => 'services.index', 'anchor' => null],
        'parts' => ['label' => 'Страница «Запчасти»', 'route' => 'parts.index', 'anchor' => null],
        'catalog' => ['label' => 'Каталог автомобилей', 'route' => 'catalog.index', 'anchor' => null],
        'contacts' => ['label' => 'Страница «Контакты»', 'route' => 'contacts.index', 'anchor' => null],
        'about' => ['label' => 'Страница «О компании»', 'route' => 'about.index', 'anchor' => null],
    ];

    /**
     * Значение настройки по умолчанию — полный объект.
     *
     * Перечислены ВСЕ поля: форма настроек пишет объект целиком,
     * и неполный объект в сиде объявлялся бы изменённым при первом же
     * сохранении нетронутой формы.
     *
     * @return array{
     *     eyebrow: string,
     *     title: string,
     *     title_accent: string,
     *     panel_eyebrow: string,
     *     panel_title: string,
     *     panel_text: string,
     *     tiles: list<array{icon: string, title: string, text: string, link: string}>,
     * }
     */
    public static function defaults(): array
    {
        return [
            'eyebrow' => 'Экосистема ЛАО КАРС',
            'title' => 'Всё для автомобиля',
            'title_accent' => 'в одном месте',
            'panel_eyebrow' => 'После покупки',
            'panel_title' => 'Мы не исчезаем после передачи ключей',
            'panel_text' => 'Собственный сервис превращает разовую покупку в долгосрочные отношения.',
            'tiles' => [
                [
                    'icon' => 'wrench',
                    'title' => 'Автосервис',
                    'text' => 'Диагностика, регламентные работы и подготовка автомобиля к эксплуатации.',
                    'link' => self::CATEGORY_PREFIX.'maintenance',
                ],
                [
                    'icon' => 'sparkle',
                    'title' => 'Детейлинг',
                    'text' => 'Защитные покрытия, полировка, уход за салоном и кузовом.',
                    'link' => self::CATEGORY_PREFIX.'detailing',
                ],
                [
                    'icon' => 'gear',
                    'title' => 'Запчасти',
                    'text' => 'Оригинальные детали и проверенные аналоги по VIN с понятным каналом заказа.',
                    'link' => 'parts',
                ],
                [
                    'icon' => 'headset',
                    'title' => 'Поддержка',
                    'text' => 'Единая точка контакта по автомобилю после покупки.',
                    'link' => 'lead',
                ],
            ],
        ];
    }

    /**
     * Значки, доступные плашке: имя в наборе `x-ui.icon` → подпись.
     *
     * Весь набор, кроме `close`: крестик — контрол закрытия, а не значок
     * направления. Совпадение имён с набором компонента сторожит
     * `HomeEcosystemTest`.
     *
     * @return array<string, string>
     */
    public static function icons(): array
    {
        return [
            'wrench' => 'Гаечный ключ',
            'wheel' => 'Колесо',
            'sparkle' => 'Блеск',
            'gear' => 'Шестерёнка',
            'headset' => 'Гарнитура',
            'phone' => 'Телефон',
            'mail' => 'Конверт',
            'pin' => 'Метка на карте',
            'route' => 'Маршрут',
            'clock' => 'Часы',
            'percent' => 'Процент',
            'check-circle' => 'Галочка в круге',
            'plus-circle' => 'Плюс в круге',
        ];
    }

    /**
     * Статические адреса плашки: значение настройки → подпись.
     *
     * @return array<string, string>
     */
    public static function pageLinks(): array
    {
        return array_map(
            static fn (array $link): string => $link['label'],
            self::PAGE_LINKS,
        );
    }

    /**
     * URL статического адреса или `null`.
     *
     * `null` — адреса нет в списке либо его роут не зарегистрирован.
     * Роут проверяется по тому же основанию, что в `SiteMenu`: блок стоит
     * на главной, и переименованный роут иначе уронил бы её целиком.
     */
    public static function pageUrl(string $target): ?string
    {
        $link = self::PAGE_LINKS[$target] ?? null;

        if ($link === null) {
            return null;
        }

        if ($link['route'] === null) {
            return $link['anchor'];
        }

        return Route::has($link['route']) ? route($link['route']) : null;
    }

    /**
     * Слаг категории из адреса вида `category:<slug>` или `null`,
     * если адрес не про категорию.
     */
    public static function categorySlug(string $target): ?string
    {
        if (! str_starts_with($target, self::CATEGORY_PREFIX)) {
            return null;
        }

        $slug = substr($target, strlen(self::CATEGORY_PREFIX));

        return $slug === '' ? null : $slug;
    }
}
