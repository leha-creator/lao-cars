<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Марки для разработки. Реальный список наполняет заказчик — здесь
 * ходовые в импорте из Китая; Zeekr, Voyah и BYD прямо названы в ТЗ.
 */
class BrandSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const BRANDS = [
        'Zeekr',
        'Voyah',
        'BYD',
        'Li Auto',
        'Exeed',
        'Chery',
        'Haval',
        'Geely',
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::BRANDS as $index => $name) {
            $brand = Brand::updateOrCreate(
                ['name' => $name],
                ['slug' => Str::slug($name), 'sort_order' => $index],
            );

            $brand->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info("[BrandSeeder] марок создано: {$created}, обновлено: {$updated}");
    }
}
