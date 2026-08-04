<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Разделы бокового меню админки.
 *
 * Enum, а не строки: `$navigationGroup` у ресурса принимает `UnitEnum`,
 * и одна опечатка в строковой группе («Каталог» с латинской K) даёт
 * в меню две одинаковых на вид секции и ноль подсказок о причине.
 * Порядок кейсов задаёт порядок групп в меню — панель регистрирует их
 * через `->navigationGroups(NavigationGroup::class)`; без этого группы
 * встают в порядке обнаружения ресурсов и меню перетасовывается от
 * каждого нового файла.
 *
 * Кейсы заведены под всю админку, а не только под веху 3.4: `Content`,
 * `Leads` и `Settings` наполняются вехой 3.5.
 *
 * # Конвенции ресурсов Filament v5
 *
 * - ресурс лежит в `app/Filament/Resources/<Множественное>/<Модель>Resource.php`
 *   (раскладка `make:filament-resource`);
 * - форма — `Schemas/<Модель>Form.php`, таблица — `Tables/<Множественное>Table.php`,
 *   страницы — `Pages/`;
 * - действие, нужное и в списке, и на странице редактирования, выносится
 *   в `Actions/<Действие><Модель>Action.php`: продублированное в таблице и
 *   в шапке страницы, оно однажды обновится только в одном месте;
 * - `$modelLabel` и `$pluralModelLabel` — по-русски, имена классов и
 *   доменные термины — по-английски (правило `rules/base.md`);
 * - иконка — только кейс enum-а `Filament\Support\Icons\Heroicon`:
 *   `$navigationIcon` объявлен как `string | BackedEnum | null`, и опечатка
 *   в строковом имени иконки проходит молча, оставляя пустое место в меню.
 */
enum NavigationGroup implements HasLabel
{
    case Catalog;
    case Content;
    case Leads;
    case Media;
    case Settings;

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Catalog => 'Каталог',
            self::Content => 'Контент',
            self::Leads => 'Заявки',
            self::Media => 'Медиа',
            self::Settings => 'Настройки',
        };
    }
}
