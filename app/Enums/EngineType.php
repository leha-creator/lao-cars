<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Тип двигателя — фильтруемая характеристика каталога (раздел 3.2 ТЗ).
 *
 * «Газ» в перечне ТЗ нет: кейс добавлен по просьбе заказчика после
 * вехи 3.2. Колонка `cars.engine_type` — обычная строка, а не enum
 * базы, поэтому новое значение не требует миграции.
 *
 * Порядок кейсов — это порядок чипов фильтра и пунктов select-а:
 * `CatalogFilterOptions::engines()` отбирает `cases()`, сохраняя
 * объявленную здесь последовательность. Переставить их местами
 * «для порядка» означает переставить их в интерфейсе.
 */
enum EngineType: string implements HasLabel
{
    use HasLabels;

    case Petrol = 'petrol';
    case Diesel = 'diesel';
    case Gas = 'gas';
    case Hybrid = 'hybrid';
    case Electric = 'electric';

    public function label(): string
    {
        return match ($this) {
            self::Petrol => 'Бензин',
            self::Diesel => 'Дизель',
            self::Gas => 'Газ',
            self::Hybrid => 'Гибрид',
            self::Electric => 'Электро',
        };
    }

    /**
     * У электромобиля нет объёма двигателя — карточка не должна
     * показывать «0.0 л» там, где показывать нечего.
     */
    public function hasVolume(): bool
    {
        return $this !== self::Electric;
    }
}
