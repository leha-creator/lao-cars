<?php

use App\Models\Review;
use Carbon\CarbonInterface;

it('is not published by default', function () {
    // Модерация из раздела 3.4 ТЗ должна быть свойством схемы,
    // а не дисциплины администратора.
    $review = Review::query()->create([
        'author_name' => 'Сергей Морозов',
        'body' => 'Привезли автомобиль точно в срок.',
    ]);

    $review->refresh();

    expect($review->is_published)->toBeFalse()
        ->and($review->published_at)->toBeNull();
});

it('hides unmoderated reviews from public queries', function () {
    Review::factory()->count(2)->published()->create();
    Review::factory()->count(3)->pending()->create();

    expect(Review::published()->count())->toBe(2)
        ->and(Review::pending()->count())->toBe(3)
        ->and(Review::count())->toBe(5);
});

it('casts rating and publication timestamp', function () {
    $review = Review::factory()->published()->create(['rating' => 5]);

    $review->refresh();

    expect($review->rating)->toBe(5)
        ->and($review->published_at)->toBeInstanceOf(CarbonInterface::class);
});

it('keeps the author context shown next to the review', function () {
    $review = Review::factory()->published()->create([
        'author_context' => 'Клиент, импорт авто',
    ]);

    expect($review->author_context)->toBe('Клиент, импорт авто');
});
