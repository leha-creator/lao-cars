<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use Illuminate\Database\Seeder;

/**
 * Значения характеристик для демо-каталога.
 *
 * Автомобили сопоставляются по `slug` — тому же признаку
 * идемпотентности, по которому работает CarSeeder.
 *
 * Заполнение неравномерное, и это часть задачи: карточка вехи 4.3
 * должна проверяться и на полном наборе, и на неполном, и на пустом.
 * Значения заданы явно, без `fake()`: случайные значения при повторном
 * `db:seed` менялись бы, а `updateOrCreate` тихо переписывал бы
 * карточку на каждом прогоне и превращал диффы в шум.
 */
class CarAttributeValueSeeder extends Seeder
{
    /**
     * Значения по slug автомобиля. Все значения типа `select` обязаны
     * входить в `options` своей характеристики — иначе фильтр вехи 3.6
     * покажет вариант, по которому ничего не находится.
     *
     * @var array<string, array<string, mixed>>
     */
    private const VALUES = [
        // Полный набор: все девять характеристик разом.
        'zeekr-001-2024' => [
            'body_type' => 'Универсал',
            'color' => 'Серый',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'clearance' => 133,
            'seats' => 5,
            'trim' => 'Long Range AWD',
            'steering' => 'Левый',
        ],
        'zeekr-007-2025' => [
            'body_type' => 'Седан',
            'color' => 'Белый',
            'origin_country' => 'Китай',
            // Нерастаможенное авто: круговой рейс булева «Нет» должен
            // быть в демо-данных, а не только в тестах.
            'customs_cleared' => false,
            'doors' => 4,
            'seats' => 5,
            'steering' => 'Левый',
        ],
        'zeekr-x-2025' => [
            'body_type' => 'Кроссовер',
            'color' => 'Синий',
            'origin_country' => 'Китай',
            'customs_cleared' => false,
            'doors' => 5,
            'clearance' => 167,
        ],
        'voyah-free-2023' => [
            'body_type' => 'Кроссовер',
            'color' => 'Чёрный',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'clearance' => 200,
            'seats' => 5,
            'trim' => 'Premium',
        ],
        'voyah-dream-2024' => [
            'body_type' => 'Минивэн',
            'color' => 'Чёрный',
            'origin_country' => 'Китай',
            'customs_cleared' => false,
            'doors' => 5,
            'seats' => 7,
        ],
        'byd-song-plus-2024' => [
            'body_type' => 'Кроссовер',
            'color' => 'Белый',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'clearance' => 175,
            'seats' => 5,
        ],
        'byd-han-2023' => [
            'body_type' => 'Седан',
            'color' => 'Чёрный',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 4,
            'trim' => 'Champion Edition',
        ],
        'li-auto-l7-2024' => [
            'body_type' => 'Внедорожник',
            'color' => 'Серебристый',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'clearance' => 190,
            'seats' => 5,
            'trim' => 'Max',
            'steering' => 'Левый',
        ],
        // Только обязательная четвёрка: неполный набор в сетке.
        'exeed-txl-2023' => [
            'body_type' => 'Кроссовер',
            'color' => 'Коричневый',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
        ],
        'chery-tiggo-8-pro-2024' => [
            'body_type' => 'Кроссовер',
            'color' => 'Красный',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'seats' => 7,
        ],
        // Haval Jolion остаётся вовсе без характеристик: сетка вехи 4.3
        // должна корректно вести себя на пустом наборе, и такой случай
        // обязан быть в демо-данных, а не обнаружиться на проде.
        'haval-jolion-2022' => [],
        'geely-monjaro-2024' => [
            'body_type' => 'Кроссовер',
            'color' => 'Серый',
            'origin_country' => 'Китай',
            'customs_cleared' => true,
            'doors' => 5,
            'clearance' => 197,
            'seats' => 5,
            'trim' => 'Exclusive',
            'steering' => 'Левый',
        ],
    ];

    public function run(): void
    {
        if (CarAttribute::query()->doesntExist()) {
            $this->command?->warn('[CarAttributeValueSeeder] справочник характеристик пуст — сначала CarAttributeSeeder');

            return;
        }

        // with('attributeValues'): syncAttributeValues() работает по
        // связи, и без предзагрузки цикл добирает её на каждое авто.
        $cars = Car::query()->with('attributeValues')->get()->keyBy('slug');

        if ($cars->isEmpty()) {
            $this->command?->warn('[CarAttributeValueSeeder] каталог пуст — сначала CarSeeder');

            return;
        }

        $processed = [];

        foreach (self::VALUES as $slug => $values) {
            $car = $cars->get($slug);

            if (! $car instanceof Car) {
                $this->command?->warn("[CarAttributeValueSeeder] автомобиль «{$slug}» не найден, пропуск");

                continue;
            }

            // Один вызов на автомобиль со всем набором разом: вызов на
            // каждую характеристику умножил бы чтение справочника на
            // девять — 108 лишних запросов на прогоне сида.
            $car->syncAttributeValues($values);

            $processed[] = $car->id;
        }

        $written = CarAttributeValue::query()->whereIn('car_id', $processed)->count();

        $this->command?->info(
            '[CarAttributeValueSeeder] автомобилей обработано: '.count($processed).", значений записано: {$written}"
        );
    }
}
