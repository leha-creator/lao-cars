<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<CarPhoto>
 */
class CarPhotoFactory extends Factory
{
    protected $model = CarPhoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Путь фиктивный, файл на диск не пишется: фабрика работает
        // в тестах, где реальные изображения только замедляют прогон.
        // Настоящие фото раскладывает CarPhotoSeeder.
        return [
            'car_id' => Car::factory(),
            'disk' => 'public',
            'path' => 'cars/placeholder-'.fake()->unique()->numberBetween(1, 100_000).'.jpg',
            'alt' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Порядковый номер фото в галерее — чтобы `->count(n)` давал
     * предсказуемую последовательность, а не n одинаковых нулей.
     */
    public function sequenced(): static
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'sort_order' => $sequence->index,
        ]);
    }
}
