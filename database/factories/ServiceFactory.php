<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServiceCategory;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => fake()->randomElement(ServiceCategory::cases()),
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->text(200),
            // Цены автосервиса кратны сотне — дробные рубли в прайсе
            // не встречаются.
            'price' => fake()->numberBetween(5, 400) * 100,
            'price_note' => null,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function inCategory(ServiceCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }

    public function maintenance(): static
    {
        return $this->inCategory(ServiceCategory::Maintenance);
    }

    public function tireService(): static
    {
        return $this->inCategory(ServiceCategory::TireService);
    }

    public function detailing(): static
    {
        return $this->inCategory(ServiceCategory::Detailing);
    }

    public function extra(): static
    {
        return $this->inCategory(ServiceCategory::Extra);
    }

    public function parts(): static
    {
        return $this->inCategory(ServiceCategory::Parts);
    }

    /**
     * «Цена по запросу» — весь раздел запчастей работает так.
     */
    public function withoutPrice(): static
    {
        return $this->state(fn (array $attributes): array => [
            'price' => null,
            'price_note' => null,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => false,
        ]);
    }
}
