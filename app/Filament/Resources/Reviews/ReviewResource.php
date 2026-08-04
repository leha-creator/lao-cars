<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reviews;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Reviews\Pages\CreateReview;
use App\Filament\Resources\Reviews\Pages\EditReview;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Filament\Resources\Reviews\Schemas\ReviewForm;
use App\Filament\Resources\Reviews\Tables\ReviewsTable;
use App\Models\Review;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Отзывы клиентов с модерацией публикации.
 *
 * Публичной формы отправки отзыва в проекте нет и по вехе 3.5 не
 * планировалось: ТЗ требует модерации, а не пользовательской отправки.
 * Отзывы заводит администратор, публикация — отдельное действие
 * с подтверждением (`PublishReviewAction`).
 */
final class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'author_name';

    protected static ?string $modelLabel = 'Отзыв';

    protected static ?string $pluralModelLabel = 'Отзывы';

    /**
     * Фото автора читается через связь — без предзагрузки это запрос
     * на каждую строку списка (антипаттерн N+1 из `ARCHITECTURE.md`).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media');
    }

    public static function form(Schema $schema): Schema
    {
        return ReviewForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'create' => CreateReview::route('/create'),
            'edit' => EditReview::route('/{record}/edit'),
        ];
    }

    /**
     * Счётчик очереди модерации в меню: раздел, где что-то ждёт решения,
     * должен быть виден без захода в него.
     */
    public static function getNavigationBadge(): ?string
    {
        $pending = Review::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }
}
