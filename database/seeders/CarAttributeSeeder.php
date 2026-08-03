<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CarAttributeType;
use App\Models\CarAttribute;
use Illuminate\Database\Seeder;

/**
 * Стартовый набор динамических характеристик.
 *
 * Набор шире пяти характеристик из роадмапа намеренно: в нём есть по
 * представителю каждого из четырёх типов и одна характеристика с
 * единицей измерения. Веха 3.4 строит редактор значений, 4.3 — сетку
 * карточки; обе должны столкнуться с каждым типом на демо-данных, а не
 * обнаружить непокрытую ветку на реальном контенте.
 *
 * Это данные для разработки. Финальный перечень характеристик
 * уточняется у заказчика — справочник на то и справочник.
 */
class CarAttributeSeeder extends Seeder
{
    /**
     * [ключ, подпись, тип, группа, единица, options, в карточке, в фильтре].
     *
     * @var list<array{0: string, 1: string, 2: CarAttributeType, 3: string, 4: string|null, 5: list<string>|null, 6: bool, 7: bool}>
     */
    private const ATTRIBUTES = [
        [
            'body_type', 'Кузов', CarAttributeType::Select, 'Кузов и салон', null,
            ['Седан', 'Хэтчбек', 'Универсал', 'Кроссовер', 'Внедорожник', 'Купе', 'Минивэн'],
            true, true,
        ],
        ['doors', 'Количество дверей', CarAttributeType::Number, 'Кузов и салон', null, null, true, false],
        ['clearance', 'Клиренс', CarAttributeType::Number, 'Кузов и салон', 'мм', null, true, false],
        [
            'color', 'Цвет', CarAttributeType::Select, 'Кузов и салон', null,
            ['Чёрный', 'Белый', 'Серый', 'Серебристый', 'Синий', 'Красный', 'Коричневый'],
            true, true,
        ],
        ['seats', 'Количество мест', CarAttributeType::Number, 'Комплектация', null, null, true, false],
        ['trim', 'Комплектация', CarAttributeType::Text, 'Комплектация', null, null, true, false],
        ['steering', 'Расположение руля', CarAttributeType::Select, 'Импорт', null, ['Левый', 'Правый'], true, true],
        [
            'origin_country', 'Страна происхождения', CarAttributeType::Select, 'Импорт', null,
            ['Китай', 'Германия', 'Япония', 'Корея', 'США'],
            true, true,
        ],
        ['customs_cleared', 'Растаможен', CarAttributeType::Boolean, 'Импорт', null, null, true, true],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::ATTRIBUTES as $index => [$key, $label, $type, $group, $unit, $options, $inCard, $inFilter]) {
            // updateOrCreate по key: ключ — публичный контракт, по нему
            // же и определяется, засеяна ли характеристика.
            $attribute = CarAttribute::query()->updateOrCreate(
                ['key' => $key],
                [
                    'label' => $label,
                    'type' => $type,
                    'unit' => $unit,
                    'group' => $group,
                    'options' => $options,
                    // Порядок в константе задаёт и порядок в сетке, и
                    // порядок групп: позиция группы — минимальный
                    // sort_order среди её характеристик.
                    'sort_order' => ($index + 1) * 10,
                    'show_in_card' => $inCard,
                    'show_in_filter' => $inFilter,
                ],
            );

            $attribute->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info("[CarAttributeSeeder] характеристик создано: {$created}, обновлено: {$updated}");
    }
}
