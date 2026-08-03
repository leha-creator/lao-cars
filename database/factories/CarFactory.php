<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Enums\EngineType;
use App\Models\Brand;
use App\Models\Car;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    protected $model = Car::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $engineType = fake()->randomElement(EngineType::cases());

        return [
            'brand_id' => Brand::factory(),
            'model' => fake()->bothify('Model ?##'),
            'year' => fake()->numberBetween(2019, 2026),
            'engine_type' => $engineType,
            // У электромобиля объёма двигателя нет — фабрика не должна
            // рождать данные, которые карточка не сможет показать.
            'engine_volume' => $engineType->hasVolume() ? fake()->randomFloat(1, 1.0, 4.0) : null,
            'engine_power' => fake()->numberBetween(90, 600),
            'drive' => fake()->randomElement(DriveType::cases()),
            'mileage' => fake()->numberBetween(0, 150_000),
            'price' => fake()->numberBetween(15, 90) * 100_000,
            'status' => CarStatus::InStock,
            'show_on_homepage' => false,
            'history' => fake()->realText(300),
            'description' => fake()->realText(200),
            'sort_order' => 0,
        ];
    }

    public function inStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CarStatus::InStock,
        ]);
    }

    /**
     * Авто под заказ — новое: пробега у него нет, и в каталоге на его
     * месте выводится «Новый».
     */
    public function onOrder(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CarStatus::OnOrder,
            'mileage' => null,
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CarStatus::Sold,
            'show_on_homepage' => false,
        ]);
    }

    public function onHomepage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'show_on_homepage' => true,
        ]);
    }

    /**
     * «Цена по запросу» — именно null, а не ноль.
     */
    public function withoutPrice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => null,
        ]);
    }
}
