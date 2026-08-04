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
 * # Долг вехи 3.5
 *
 * 1. **Проверка использования перед удалением.** Сейчас удалить можно
 *    любую запись: потребителей у библиотеки ещё нет, и проверять нечего.
 *    С появлением `employees.media_id` и полей настроек сайта проверка
 *    «файл где-то используется» становится обязательной — иначе удаление
 *    из библиотеки молча ломает страницу «О компании».
 * 2. **Компонент-пикер `MediaPicker`.** В веху 3.4 он не входит осознанно:
 *    `ModalTableSelect::relationship()` требует связи, а связей к `media`
 *    до вехи 3.5 нет. Компонент, который нельзя ни запустить, ни покрыть
 *    тестом, ошибётся молча и всплывёт через веху, когда контекст забыт.
 *    Веха 3.4 отдаёт хранилище, точку подключения пишет 3.5 вместе
 *    с первым потребителем, который её сразу и проверит.
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
