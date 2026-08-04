<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // По умолчанию отзыв не опубликован — так же, как через форму
        // на сайте. Публикация всегда явное действие модератора.
        return [
            'author_name' => fake()->name(),
            'author_context' => fake()->randomElement([
                'Клиент, импорт авто',
                'Клиент, автосервис',
                'Клиент, детейлинг',
                'Клиент, подбор запчастей',
            ]),
            'body' => fake()->text(220),
            'rating' => fake()->numberBetween(4, 5),
            'media_id' => null,
            'is_published' => false,
            'published_at' => null,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
