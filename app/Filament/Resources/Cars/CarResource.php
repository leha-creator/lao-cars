<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Cars\Pages\CreateCar;
use App\Filament\Resources\Cars\Pages\EditCar;
use App\Filament\Resources\Cars\Pages\ListCars;
use App\Filament\Resources\Cars\Schemas\CarForm;
use App\Filament\Resources\Cars\Tables\CarsTable;
use App\Models\Car;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Каталог автомобилей — основной рабочий раздел админки.
 */
final class CarResource extends Resource
{
    protected static ?string $model = Car::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'model';

    protected static ?string $modelLabel = 'Автомобиль';

    protected static ?string $pluralModelLabel = 'Автомобили';

    /**
     * Предзагрузка обязательна, а не желательна.
     *
     * `mainPhoto()` — отдельная связь `hasOne`, и без `with()` она даёт
     * запрос на каждую строку списка: 12 карточек с превью и маркой
     * стоят два с лишним десятка запросов вместо трёх. N+1 на списках
     * перечислен антипаттерном в `ARCHITECTURE.md`; тест на число
     * запросов (задача 11) существует ровно затем, чтобы эту строку
     * не удалили как «лишнюю».
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['brand', 'mainPhoto']);
    }

    public static function form(Schema $schema): Schema
    {
        return CarForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarsTable::configure($table);
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
            'index' => ListCars::route('/'),
            'create' => CreateCar::route('/create'),
            'edit' => EditCar::route('/{record}/edit'),
        ];
    }
}
