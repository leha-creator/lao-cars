<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;

/**
 * Статус автомобиля в каталоге (раздел 3.2 ТЗ).
 *
 * «Продан» — не удаление: карточка остаётся ради истории и SEO,
 * но выпадает из выдачи каталога.
 */
enum CarStatus: string
{
    use HasLabels;

    case InStock = 'in_stock';
    case OnOrder = 'on_order';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наличии',
            self::OnOrder => 'Под заказ',
            self::Sold => 'Продан',
        };
    }
}
