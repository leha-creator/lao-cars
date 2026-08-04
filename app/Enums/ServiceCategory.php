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
