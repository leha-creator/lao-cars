<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\Review;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Очередь модерации — первой и по умолчанию: это главный экран
     * раздела, а не список всех отзывов. Сид специально держит один
     * неопубликованный отзыв, чтобы очередь не была пустой на первом
     * же открытии.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('На модерации')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_published', false))
                ->badge(Review::pending()->count()),

            'published' => Tab::make('Опубликованные')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_published', true))
                ->badge(Review::published()->count()),

            'all' => Tab::make('Все')
                ->badge(Review::query()->count()),
        ];
    }
}
