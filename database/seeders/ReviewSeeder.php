<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Review;
use Illuminate\Database\Seeder;

/**
 * Отзывы для блока «Что говорят клиенты».
 *
 * Последний отзыв намеренно остаётся на модерации: без него нечем
 * проверить, что публичная выборка действительно фильтрует
 * неопубликованное, а очередь модерации в админке пуста.
 */
class ReviewSeeder extends Seeder
{
    /**
     * @var list<array{author: string, context: string, body: string, rating: int, published: bool}>
     */
    private const REVIEWS = [
        [
            'author' => 'Сергей Морозов',
            'context' => 'Клиент, импорт авто',
            'body' => 'Привезли автомобиль из Китая точно в оговорённый срок. Все документы оформили сами, я только приехал забрать.',
            'rating' => 5,
            'published' => true,
        ],
        [
            'author' => 'Наталья Ерёмина',
            'context' => 'Клиент, автосервис',
            'body' => 'Обслуживаю гибрид здесь второй год. Объясняют, что и зачем меняют, лишнего не навязывают.',
            'rating' => 5,
            'published' => true,
        ],
        [
            'author' => 'Дмитрий Лаптев',
            'context' => 'Клиент, детейлинг',
            'body' => 'Делали полировку и керамику. Результат держится вторую зиму, реагенты кузов не тронули.',
            'rating' => 5,
            'published' => true,
        ],
        [
            'author' => 'Ольга Ким',
            'context' => 'Клиент, подбор запчастей',
            'body' => 'Нашли деталь по VIN за два дня, хотя в наличии её нигде не было.',
            'rating' => 4,
            'published' => false,
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $updated = 0;

        foreach (self::REVIEWS as $index => $review) {
            $record = Review::updateOrCreate(
                ['author_name' => $review['author']],
                [
                    'author_context' => $review['context'],
                    'body' => $review['body'],
                    'rating' => $review['rating'],
                    'is_published' => $review['published'],
                    'published_at' => $review['published'] ? now() : null,
                    'sort_order' => $index,
                ],
            );

            $record->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info("[ReviewSeeder] отзывов создано: {$created}, обновлено: {$updated}");
    }
}
