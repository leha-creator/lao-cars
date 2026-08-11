<?php

declare(strict_types=1);

namespace App\Support\Help;

use Filament\Support\Icons\Heroicon;

/**
 * Раздел справки в панели.
 *
 * Разделов ровно два, и они соответствуют вопросу, с которым человек
 * пришёл: «мне надо сделать X» и «мне надо изменить, как устроен сайт».
 * Третьего раздела («О системе», «Справочник») не заводится: статья,
 * которая не отвечает ни на «как сделать», ни на «как настроить», — это
 * статья, которую никто не откроет.
 *
 * Значения кейсов попадают в адреса страниц-списков (`/admin/help/scenarios`),
 * поэтому переименование кейса — это переименование адреса.
 */
enum HelpSection: string
{
    case Scenarios = 'scenarios';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Scenarios => 'Сценарии работы',
            self::Settings => 'Настройка параметров',
        };
    }

    /**
     * Одна фраза в шапке страницы-списка: чем этот раздел отличается
     * от соседнего. Без неё два списка карточек выглядят одинаково,
     * и выбор между ними делается наугад.
     */
    public function description(): string
    {
        return match ($this) {
            self::Scenarios => 'Ежедневная работа: заявки, каталог, прайс, отзывы.',
            self::Settings => 'Как изменить то, что видит посетитель сайта.',
        };
    }

    /**
     * Иконка — только кейс enum-а `Heroicon`, а не строковое имя:
     * `$navigationIcon` объявлен как `string | BackedEnum | null`,
     * и опечатка в строке проходит молча, оставляя пустое место
     * в меню (правило из PHPDoc `App\Filament\NavigationGroup`).
     */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::Scenarios => Heroicon::OutlinedBookOpen,
            self::Settings => Heroicon::OutlinedAdjustmentsHorizontal,
        };
    }
}
