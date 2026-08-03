<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CarAttributeType;
use App\Models\CarAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarAttribute>
 */
class CarAttributeFactory extends Factory
{
    protected $model = CarAttribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Ключ собирается из числа, а не из fake()->word(): локаль
            // проекта — ru_RU, а `key` обязан быть латиницей в
            // snake_case. Уникальность нужна из-за unique-индекса.
            'key' => 'attr_'.fake()->unique()->numberBetween(1, 100_000),
            'label' => fake()->word(),
            // Текст — тип без списка значений и без единицы измерения:
            // фабрика по умолчанию не должна требовать донастройки.
            'type' => CarAttributeType::Text,
            'unit' => null,
            'group' => null,
            'options' => null,
            'sort_order' => 0,
            'show_in_card' => true,
            'show_in_filter' => false,
        ];
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CarAttributeType::Text,
            'unit' => null,
            'options' => null,
        ]);
    }

    /**
     * Число с необязательной единицей измерения — «190 мм».
     */
    public function number(?string $unit = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CarAttributeType::Number,
            'unit' => $unit,
            'options' => null,
        ]);
    }

    public function boolean(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CarAttributeType::Boolean,
            'unit' => null,
            'options' => null,
        ]);
    }

    /**
     * Выбор из списка. Список обязателен: `select` без `options` не
     * принимает ни одного значения (см. `CarAttribute::isValidValue`).
     *
     * @param  list<string>  $options
     */
    public function select(array $options): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CarAttributeType::Select,
            'unit' => null,
            'options' => $options,
        ]);
    }

    public function inFilter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'show_in_filter' => true,
        ]);
    }

    public function hiddenInCard(): static
    {
        return $this->state(fn (array $attributes): array => [
            'show_in_card' => false,
        ]);
    }

    public function inGroup(string $group): static
    {
        return $this->state(fn (array $attributes): array => [
            'group' => $group,
        ]);
    }
}
