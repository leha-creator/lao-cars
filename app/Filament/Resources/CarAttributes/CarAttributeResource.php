<?php

declare(strict_types=1);

namespace App\Filament\Resources\CarAttributes;

use App\Filament\NavigationGroup;
use App\Filament\Resources\CarAttributes\Pages\CreateCarAttribute;
use App\Filament\Resources\CarAttributes\Pages\EditCarAttribute;
use App\Filament\Resources\CarAttributes\Pages\ListCarAttributes;
use App\Filament\Resources\CarAttributes\Schemas\CarAttributeForm;
use App\Filament\Resources\CarAttributes\Tables\CarAttributesTable;
use App\Models\CarAttribute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Справочник динамических характеристик автомобиля (веха 3.3).
 *
 * В этом ресурсе весь смысл динамических характеристик: администратор
 * добавляет сюда строку и немедленно видит поле в форме автомобиля —
 * без разработчика и без миграции.
 */
final class CarAttributeResource extends Resource
{
    protected static ?string $model = CarAttribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'Характеристика';

    protected static ?string $pluralModelLabel = 'Характеристики авто';

    public static function form(Schema $schema): Schema
    {
        return CarAttributeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarAttributesTable::configure($table);
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
            'index' => ListCarAttributes::route('/'),
            'create' => CreateCarAttribute::route('/create'),
            'edit' => EditCarAttribute::route('/{record}/edit'),
        ];
    }
}
