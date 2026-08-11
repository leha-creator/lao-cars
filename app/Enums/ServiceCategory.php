<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Категория позиции в единой сущности «услуга».
 *
 * Запчасти — такая же категория, а не отдельная модель: посадочной
 * странице нужны ровно поля услуги (название, описание, цена или
 * «по запросу», порядок). Модель `Part` появится вместе с витриной
 * (артикулы, наличие, фильтры), которой в MVP нет — см. DESCRIPTION.md.
 */
enum ServiceCategory: string implements HasLabel
{
    use HasLabels;

    case Maintenance = 'maintenance';
    case TireService = 'tire_service';
    case Detailing = 'detailing';
    case Extra = 'extra';
    case Parts = 'parts';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'ТО и ремонт',
            self::TireService => 'Шиномонтаж',
            self::Detailing => 'Детейлинг',
            self::Extra => 'Дополнительные сервисы',
            self::Parts => 'Запчасти',
        };
    }

    /**
     * Запчасти живут на своей посадочной странице, остальные категории —
     * блоками на странице автосервиса. Сравнение со строкой в шаблонах
     * и контроллерах заменяется этой проверкой.
     */
    public function isParts(): bool
    {
        return $this === self::Parts;
    }

    /**
     * Идентификатор якоря блока категории на странице автосервиса.
     *
     * Живёт здесь, а не в шаблоне, потому что нужен в двух местах сразу:
     * `id` у блока прайса и `href` у ссылки якорной навигации. Собирать
     * его в двух местах значит гарантированно разъехаться в третьем,
     * а ссылка на несуществующий якорь не работает МОЛЧА — ни ошибки
     * в консоли, ни падения теста разметки.
     *
     * Значение енама с подчёркиванием, заменённым на дефис
     * (`tire_service` → `tire-service`) — как в макете. Одно выражение,
     * а не `match` по кейсам: `match` повторил бы то же преобразование
     * пять раз и дал бы пять шансов на опечатку в якоре.
     */
    public function anchor(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * Имя иконки категории в шапке блока прайса — из набора `x-icon`.
     *
     * Доменное свойство того же сорта, что `label()`, и живёт рядом с ним,
     * а не в настройках сайта: значок — часть дизайн-системы, а не контент,
     * и заказчику нечего в нём править.
     *
     * До этого метод назывался `badge()` и отдавал буквы («ТО», «ШМ», «Д»).
     * Буквы были заглушкой под иконки, а не решением: в кружке они читаются
     * как аватар, и «Д» у детейлинга не отличается от «Д» любого другого
     * слова на ту же букву.
     *
     * Возвращается ИМЯ, а не сам контур: разметка иконки живёт в одном месте
     * (`resources/views/components/icon.blade.php`), иначе SVG приехал бы
     * в enum, а вместе с ним и вопрос, кому его экранировать.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Maintenance => 'wrench',
            self::TireService => 'wheel',
            self::Detailing => 'sparkle',
            self::Extra => 'plus-circle',
            self::Parts => 'gear',
        };
    }

    /**
     * Категории страницы «Автосервис» в порядке вывода блоков.
     *
     * @return array<int, self>
     */
    public static function serviceCategories(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $category): bool => ! $category->isParts(),
        ));
    }
}
