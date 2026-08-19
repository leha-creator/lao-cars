<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ServicePage;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Имя русское — категории и на сайте, и в админке русские, —
        // но с гарантированно уникальным хвостом: `name` и `slug` оба
        // под уникальным индексом, а тесты заводят категории пачками.
        $name = 'Категория '.Str::upper(fake()->unique()->bothify('??##'));

        return [
            'name' => $name,
            // Слаг задаётся явно, а не отдаётся `HasSlug`: тест, которому
            // нужен предсказуемый якорь блока, не должен угадывать его
            // по транслитерации.
            'slug' => Str::slug($name),
            'page' => ServicePage::Services,
            'description' => fake()->text(120),
            'sort_order' => 0,
        ];
    }

    public function onPage(ServicePage $page): static
    {
        return $this->state(fn (array $attributes): array => [
            'page' => $page,
        ]);
    }

    public function services(): static
    {
        return $this->onPage(ServicePage::Services);
    }

    public function parts(): static
    {
        return $this->onPage(ServicePage::Parts);
    }

    /**
     * Категория без абзаца под названием: блок остаётся, описание уходит.
     */
    public function withoutDescription(): static
    {
        return $this->state(fn (array $attributes): array => [
            'description' => null,
        ]);
    }
}
