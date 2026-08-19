<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ServiceCategories\Pages\CreateServiceCategory;
use App\Filament\Resources\ServiceCategories\Pages\EditServiceCategory;
use App\Filament\Resources\ServiceCategories\Pages\ListServiceCategories;
use App\Filament\Resources\ServiceCategories\Schemas\ServiceCategoryForm;
use App\Filament\Resources\ServiceCategories\Tables\ServiceCategoriesTable;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Справочник категорий услуг — веха 4.13.
 *
 * До неё категорий было пять и жили они кейсами енама: добавить шестую
 * значило выкатить релиз. Образец раздела назван заказчиком дословно —
 * «как марки авто», и `BrandResource` здесь именно образец.
 *
 * Доступ — только администратору (`ServiceCategoryPolicy`), как у позиций
 * прайса: справочник это контент сайта, а не рабочий инструмент менеджера.
 */
final class ServiceCategoryResource extends Resource
{
    protected static ?string $model = ServiceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    /** Категории идут после позиций: справочник вторичен по отношению к прайсу — та же логика, что у марок после автомобилей. */
    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Категория';

    protected static ?string $pluralModelLabel = 'Категории услуг';

    /**
     * Подпись в меню задаётся явно: по умолчанию Filament прогоняет
     * `$pluralModelLabel` через тайтл-кейс, и в меню появляется
     * «Категории Услуг» с заглавной во втором слове.
     */
    protected static ?string $navigationLabel = 'Категории услуг';

    public static function form(Schema $schema): Schema
    {
        return ServiceCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceCategoriesTable::configure($table);
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
            'index' => ListServiceCategories::route('/'),
            'create' => CreateServiceCategory::route('/create'),
            'edit' => EditServiceCategory::route('/{record}/edit'),
        ];
    }
}
