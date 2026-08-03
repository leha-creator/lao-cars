<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\CarAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarAttributeValue>
 */
class CarAttributeValueFactory extends Factory
{
    protected $model = CarAttributeValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'car_id' => Car::factory(),
            'car_attribute_id' => CarAttribute::factory(),
            // Тип по умолчанию у справочника — text, поэтому строка
            // подходит любому значению без донастройки.
            'value' => fake()->word(),
        ];
    }

    /**
     * Значение конкретной характеристики — единственный способ собрать
     * пару, у которой `value` осмыслен для типа из справочника.
     */
    public function forAttribute(CarAttribute $attribute, string $value): static
    {
        return $this->state(fn (array $attributes): array => [
            'car_attribute_id' => $attribute->id,
            'value' => $value,
        ]);
    }
}
