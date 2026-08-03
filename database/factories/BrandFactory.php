<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Имя собирается латиницей независимо от APP_FAKER_LOCALE=ru_RU:
        // марки автомобилей латинские, а русское «ЗАО ЛенБашкир» в роли
        // марки — мусор в тестовых выборках. Реальные марки приходят
        // из BrandSeeder, здесь важна только уникальность.
        $name = 'Brand '.Str::upper(fake()->unique()->bothify('??##'));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 0,
        ];
    }
}
