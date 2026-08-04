<?php

/*
 * Отзывы и модерация публикации (веха 3.5).
 *
 * Главное здесь — дата первой публикации. Правило живёт в модели,
 * потому что публиковать можно из формы, из действия в списке и из
 * tinker; по этой дате сортирует `scopeOrdered()`, и обнулять её при
 * снятии публикации значило бы терять порядок отзывов при первой же
 * перемодерации.
 */

use App\Filament\Resources\Reviews\Pages\CreateReview;
use App\Filament\Resources\Reviews\Pages\EditReview;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\Media;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('opens on the moderation queue', function () {
    $pending = Review::factory()->pending()->count(2)->create();
    $published = Review::factory()->published()->create();

    livewire(ListReviews::class)
        ->assertOk()
        ->assertCanSeeTableRecords($pending)
        ->assertCanNotSeeTableRecords([$published]);
});

it('creates a review', function () {
    $media = Media::factory()->create();

    livewire(CreateReview::class)
        ->fillForm([
            'author_name' => 'Ольга Ким',
            'author_context' => 'Клиент, импорт авто',
            'body' => 'Машину привезли раньше срока.',
            'rating' => 5,
            'media_id' => $media->getKey(),
            'is_published' => false,
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('reviews', [
        'author_name' => 'Ольга Ким',
        'rating' => 5,
        'media_id' => $media->getKey(),
        'published_at' => null,
    ]);
});

it('sets published_at on the first publication', function () {
    $review = Review::factory()->pending()->create();

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->callAction('publish');

    expect($review->refresh()->is_published)->toBeTrue()
        ->and($review->published_at)->not->toBeNull();
});

it('keeps the original published_at through unpublish and republish', function () {
    $review = Review::factory()->pending()->create();

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->callAction('publish');

    $firstDate = $review->refresh()->published_at;

    $this->travel(1)->hours();

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->callAction('unpublish');

    expect($review->refresh()->is_published)->toBeFalse()
        // Снятие публикации дату не трогает: это факт, а не флаг.
        ->and($review->published_at?->timestamp)->toBe($firstDate?->timestamp);

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->callAction('publish');

    expect($review->refresh()->published_at?->timestamp)->toBe($firstDate?->timestamp);
});

it('sets published_at when publishing through the form as well', function () {
    // Правило живёт в модели именно поэтому: точек публикации несколько,
    // и три копии правила разъехались бы.
    $review = Review::factory()->pending()->create();

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->fillForm(['is_published' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($review->refresh()->published_at)->not->toBeNull();
});

it('logs moderation', function () {
    Log::spy();

    $review = Review::factory()->pending()->create(['author_name' => 'Ольга Ким']);

    livewire(EditReview::class, ['record' => $review->getKey()])
        ->callAction('publish');

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Отзыв прошёл модерацию'
            && $context['author_name'] === 'Ольга Ким'
            && $context['published'] === true
            // Текст отзыва в лог не пишется.
            && ! array_key_exists('body', $context))
        ->once();
});

it('offers publish only for unpublished records', function () {
    $pending = Review::factory()->pending()->create();
    $published = Review::factory()->published()->create();

    livewire(EditReview::class, ['record' => $pending->getKey()])
        ->assertActionVisible('publish')
        ->assertActionHidden('unpublish');

    livewire(EditReview::class, ['record' => $published->getKey()])
        ->assertActionHidden('publish')
        ->assertActionVisible('unpublish');
});
