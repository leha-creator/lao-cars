<?php

declare(strict_types=1);

namespace App\Filament\Resources\Services;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Filament\Resources\Services\Schemas\ServiceForm;
use App\Filament\Resources\Services\Tables\ServicesTable;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Услуги автосервиса и категории запчастей — одна сущность на все
 * категории (см. PHPDoc модели `Service`).
 *
 * `$pluralModelLabel` не «Услуги», а «Услуги и запчасти»: сущность одна,
 * но администратор ищет в меню и то и другое.
 *
 * Доступ — только администратору (`ServicePolicy`): прайс и тексты
 * страниц это контент сайта, а не рабочий инструмент менеджера.
 */
final class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    /** Услуги — первый раздел контента: их правят чаще команды и отзывов. */
    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Позиция';

    protected static ?string $pluralModelLabel = 'Услуги и запчасти';

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
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
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
