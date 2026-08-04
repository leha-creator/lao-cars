<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Файл на диск не пишется — как и в CarPhotoFactory: реальные
        // изображения в тестах только замедляют прогон. Тесты, которым
        // нужен файл, кладут его сами через Storage::fake().
        $name = fake()->unique()->numberBetween(1, 100_000);

        return [
            'disk' => 'public',
            'path' => "media/placeholder-{$name}.webp",
            'thumb_path' => "media/thumbs/placeholder-{$name}.webp",
            'name' => "Изображение {$name}",
            'alt' => null,
            'mime' => 'image/webp',
            'size' => fake()->numberBetween(20_000, 400_000),
        ];
    }

    /**
     * Запись без превью: обработка не удалась и оригинал сохранён как есть.
     */
    public function withoutThumb(): static
    {
        return $this->state(fn (): array => ['thumb_path' => null]);
    }
}
