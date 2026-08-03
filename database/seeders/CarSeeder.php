<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Каталог для разработки.
 *
 * Марка, модель и год заданы явно, остальное добирает фабрика: по
 * этой тройке строится slug, а значит и признак «такое авто уже
 * засеяно» — иначе повторный `db:seed` удваивал бы каталог.
 *
 * Реальные карточки с фотографиями, характеристиками и ценами
 * наполняет заказчик (этап 5 ТЗ).
 */
class CarSeeder extends Seeder
{
    /**
     * [марка, модель, год, двигатель, привод, цена, статус, на главной].
     *
     * @var list<array{0: string, 1: string, 2: int, 3: EngineType, 4: DriveType, 5: int|null, 6: CarStatus, 7: bool}>
     */
    private const CARS = [
        ['Zeekr', '001', 2024, EngineType::Electric, DriveType::Full, 4_200_000, CarStatus::InStock, true],
        ['Zeekr', '007', 2025, EngineType::Electric, DriveType::Rear, 4_950_000, CarStatus::OnOrder, true],
        ['Zeekr', 'X', 2025, EngineType::Electric, DriveType::Full, 3_890_000, CarStatus::OnOrder, false],
        ['Voyah', 'Free', 2023, EngineType::Hybrid, DriveType::Full, 5_100_000, CarStatus::InStock, true],
        ['Voyah', 'Dream', 2024, EngineType::Hybrid, DriveType::Full, 6_890_000, CarStatus::OnOrder, false],
        ['BYD', 'Song Plus', 2024, EngineType::Hybrid, DriveType::Front, 3_650_000, CarStatus::InStock, false],
        ['BYD', 'Han', 2023, EngineType::Electric, DriveType::Full, 4_400_000, CarStatus::InStock, false],
        ['Li Auto', 'L7', 2024, EngineType::Hybrid, DriveType::Full, 7_200_000, CarStatus::InStock, false],
        ['Exeed', 'TXL', 2023, EngineType::Petrol, DriveType::Full, 2_980_000, CarStatus::InStock, false],
        ['Chery', 'Tiggo 8 Pro', 2024, EngineType::Petrol, DriveType::Front, 3_150_000, CarStatus::InStock, false],
        // Без цены — проверяет вывод «цена по запросу» в карточке.
        ['Haval', 'Jolion', 2022, EngineType::Petrol, DriveType::Front, null, CarStatus::InStock, false],
        // Проданное авто остаётся в базе, но выпадает из выдачи каталога.
        ['Geely', 'Monjaro', 2024, EngineType::Petrol, DriveType::Full, 3_780_000, CarStatus::Sold, false],
    ];

    public function run(): void
    {
        $brands = Brand::query()->pluck('id', 'name');

        if ($brands->isEmpty()) {
            $this->command?->warn('[CarSeeder] справочник марок пуст — сначала BrandSeeder');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach (self::CARS as $index => [$brandName, $model, $year, $engine, $drive, $price, $status, $onHomepage]) {
            $brandId = $brands[$brandName] ?? null;

            if ($brandId === null) {
                $this->command?->warn("[CarSeeder] марка «{$brandName}» не найдена, пропуск");

                continue;
            }

            $slug = Str::slug("{$brandName} {$model} {$year}");

            if (Car::query()->where('slug', $slug)->exists()) {
                $skipped++;

                continue;
            }

            Car::factory()->create([
                'brand_id' => $brandId,
                'model' => $model,
                'slug' => $slug,
                'year' => $year,
                'engine_type' => $engine,
                'engine_volume' => $engine->hasVolume() ? fake()->randomFloat(1, 1.5, 3.0) : null,
                'drive' => $drive,
                // Под заказ — авто новое, пробега у него нет.
                'mileage' => $status === CarStatus::OnOrder ? null : fake()->numberBetween(5_000, 90_000),
                'price' => $price,
                'status' => $status,
                'show_on_homepage' => $onHomepage,
                'sort_order' => $index,
            ]);

            $created++;
        }

        $this->command?->info("[CarSeeder] автомобилей создано: {$created}, пропущено как существующие: {$skipped}");
    }
}
