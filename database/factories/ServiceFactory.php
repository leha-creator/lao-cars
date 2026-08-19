<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\Service;
use App\Models\ServiceCategory;
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
            // Категория обязательна на уровне схемы, поэтому фабрика заводит
            // свою: позиция без категории не выводится нигде — существует,
            // но невидима. Состояния `maintenance()`, `tireService()`,
            // `detailing()`, `extra()` и `parts()` ушли вместе с енамом
            // вехой 4.13 — категорий больше не пять, и фиксировать их
            // именами в фабрике значит завести шестой источник правды.
            'service_category_id' => ServiceCategory::factory(),
            'media_id' => null,
            'title' => fake()->unique()->sentence(3),
            'description' => fake()->text(200),
            'details' => null,
            // Цены автосервиса кратны сотне — дробные рубли в прайсе
            // не встречаются.
            'price' => fake()->numberBetween(5, 400) * 100,
            'price_note' => null,
            'is_featured' => false,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function inCategory(ServiceCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'service_category_id' => $category->getKey(),
        ]);
    }

    /**
     * Акцентная позиция — широкая карточка во всю ширину контента.
     *
     * Фотографию НЕ проставляет: флаг и кадр — разные поля, и акцентная
     * позиция без фотографии штатное состояние, а не полумера.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_featured' => true,
        ]);
    }

    public function withPhoto(?Media $media = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'media_id' => $media?->getKey() ?? Media::factory(),
        ]);
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
