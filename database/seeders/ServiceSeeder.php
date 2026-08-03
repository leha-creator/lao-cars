<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Прайс автосервиса и категории запчастей для разработки.
 *
 * Настоящие позиции и цены поступают от заказчика (этап 5 ТЗ);
 * здесь важно, чтобы на странице автосервиса были заполнены все
 * блоки, а на посадочной запчастей — все категории.
 */
class ServiceSeeder extends Seeder
{
    /**
     * Категория => список позиций: [название, цена или null, уточнение].
     *
     * @var array<string, list<array{0: string, 1: int|null, 2: string|null}>>
     */
    private const SERVICES = [
        ServiceCategory::Maintenance->value => [
            ['Плановое ТО', 6500, 'от'],
            ['Замена масла и фильтров', 3200, null],
            ['Диагностика ходовой части', 2500, null],
            ['Ремонт гибридных установок', null, null],
            ['Компьютерная диагностика', 2000, null],
        ],
        ServiceCategory::TireService->value => [
            ['Шиномонтаж легкового автомобиля', 1200, 'за колесо'],
            ['Балансировка колёс', 600, 'за колесо'],
            ['Сезонное хранение шин', 4500, 'за сезон'],
            ['Ремонт бокового пореза', null, null],
        ],
        ServiceCategory::Detailing->value => [
            ['Комплексная мойка', 2500, 'от'],
            ['Полировка кузова', 12000, 'от'],
            ['Защитное керамическое покрытие', 35000, 'от'],
            ['Химчистка салона', 9000, 'от'],
        ],
        ServiceCategory::Extra->value => [
            ['Русификация мультимедиа', 8000, 'от'],
            ['Трейд-ин', null, null],
            ['Помощь в оформлении и постановке на учёт', null, null],
        ],
        ServiceCategory::Parts->value => [
            ['Оригинальные запчасти', null, null],
            ['Аналоги проверенных производителей', null, null],
            ['Расходники для ТО', null, null],
            ['Кузовные детали', null, null],
            ['Электрика и электроника', null, null],
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::SERVICES as $category => $items) {
            foreach ($items as $index => [$title, $price, $priceNote]) {
                $service = Service::updateOrCreate(
                    ['slug' => Str::slug($title)],
                    [
                        'category' => ServiceCategory::from($category),
                        'title' => $title,
                        'description' => null,
                        'price' => $price,
                        'price_note' => $priceNote,
                        'is_published' => true,
                        'sort_order' => $index,
                    ],
                );

                $service->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $this->command?->info("[ServiceSeeder] позиций создано: {$created}, обновлено: {$updated}");
    }
}
