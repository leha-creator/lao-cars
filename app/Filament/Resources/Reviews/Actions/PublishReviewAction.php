<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Actions;

use App\Models\Review;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;

/**
 * Модерация отзыва — действие с подтверждением, а не `ToggleColumn`.
 *
 * Инлайн-колонки таблицы **обходят политики**, и это прямо
 * задокументировано в самом Filament: «Inline editable table columns
 * bypass these checks — they only respect `disabled()`»
 * (`vendor/filament/filament/src/Resources/Resource/Concerns/HasAuthorization.php:14-22`).
 * Публикация отзыва — то, что видит весь интернет: у неё должно быть
 * подтверждение, запись в лог и проверка прав, которой колонка не делает.
 *
 * Отдельный класс, потому что действия нужны и в строке списка, и в шапке
 * страницы редактирования (правило `RULES.md`).
 *
 * Дату первой публикации ставит модель (`Review::booted()`), а не эти
 * действия: публиковать можно ещё и из формы, и из tinker.
 */
final class PublishReviewAction
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Опубликовать')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Опубликовать отзыв?')
            ->modalDescription('Отзыв появится на сайте.')
            ->visible(fn (Review $record): bool => ! $record->is_published)
            ->action(fn (Review $record) => self::apply($record, published: true));
    }

    public static function unpublish(): Action
    {
        return Action::make('unpublish')
            ->label('Снять с публикации')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Снять отзыв с публикации?')
            ->modalDescription('Отзыв исчезнет с сайта. Дата первой публикации сохранится.')
            ->visible(fn (Review $record): bool => $record->is_published)
            ->action(fn (Review $record) => self::apply($record, published: false));
    }

    /**
     * Логируется само событие модерации: в отличие от отказов политик,
     * это событие с последствиями — отзыв появляется на публичном сайте
     * или исчезает с него. Текст отзыва в лог не пишется.
     */
    private static function apply(Review $review, bool $published): void
    {
        $review->is_published = $published;
        $review->save();

        Log::info('Отзыв прошёл модерацию', [
            'review_id' => $review->getKey(),
            'author_name' => $review->author_name,
            'published' => $published,
            'actor_id' => auth()->id(),
        ]);

        Notification::make()
            ->title($published ? 'Отзыв опубликован' : 'Отзыв снят с публикации')
            ->success()
            ->send();
    }
}
