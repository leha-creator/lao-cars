<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasColors;
use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Статус автомобиля в каталоге (раздел 3.2 ТЗ).
 *
 * «Продан» — не удаление: карточка остаётся ради истории и SEO,
 * но выпадает из выдачи каталога.
 *
 * «В пути» — состояние между покупкой и приездом: автомобиль уже наш
 * и уже в каталоге, поэтому он входит в `Car::available()` наравне
 * с «В наличии» и «Под заказ». Срока доставки у статуса нет намеренно —
 * отслеживание доставки роадмап отложил за пределы MVP.
 *
 * ВАЖНО: порядок кейсов содержателен. Фильтр подборки на главной
 * (`home/index.blade.php`) строит кнопки из `cases()` и берёт порядок
 * отсюда — перестановка кейсов переставит кнопки на сайте, не уронив
 * ни одного теста.
 */
enum CarStatus: string implements HasColor, HasLabel
{
    use HasColors;
    use HasLabels;

    case InStock = 'in_stock';
    case OnOrder = 'on_order';
    case InTransit = 'in_transit';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наличии',
            self::OnOrder => 'Под заказ',
            self::InTransit => 'В пути',
            self::Sold => 'Продан',
        };
    }

    /**
     * Цвет бейджа в админке. Значения — из палитры Filament.
     *
     * Живёт в enum-е по той же причине, что и подпись: хардкод цвета
     * в badge-колонке ресурса — копия словаря, которая разъезжается
     * с оригиналом при первом же новом статусе.
     */
    public function color(): string
    {
        return match ($this) {
            self::InStock => 'success',
            self::OnOrder => 'warning',
            // Единственный оставшийся содержательный цвет палитры Filament:
            // success занят «в наличии», warning — «под заказ», gray — «продан».
            // Читается как «в процессе», что статусу и соответствует.
            self::InTransit => 'info',
            self::Sold => 'gray',
        };
    }
}
