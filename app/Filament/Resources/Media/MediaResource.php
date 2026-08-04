<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Media\Schemas\MediaForm;
use App\Filament\Resources\Media\Tables\MediaTable;
use App\Models\Media;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Общая медиабиблиотека.
 *
 * Оба долга вехи 3.4 закрыты вехой 3.5:
 *
 * 1. **Проверка использования перед удалением** — `Media::usages()`
 *    и `DeleteMediaAction`. Массовое удаление при этом убрано: оно идёт
 *    мимо `before()` и обошло бы проверку целиком.
 * 2. **Компонент-пикер** — `App\Filament\Forms\Components\MediaPicker`,
 *    написанный вместе с первыми потребителями (`employees.media_id`,
 *    `reviews.media_id`, ключ настроек `home.promo.image_id`), которые
 *    его сразу и проверили.
 *
 * Отдельной страницы создания нет: запись библиотеки без файла
 * бессмысленна, поэтому загрузка живёт действием в шапке списка.
 */
final class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Media;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Изображение';

    protected static ?string $pluralModelLabel = 'Медиабиблиотека';

    public static function form(Schema $schema): Schema
    {
        return MediaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MediaTable::configure($table);
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
            'index' => ListMedia::route('/'),
            'edit' => EditMedia::route('/{record}/edit'),
        ];
    }
}
