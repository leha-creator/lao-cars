<?php

declare(strict_types=1);

namespace App\Filament\Resources\Employees;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Команда в блоке «О компании».
 *
 * Контент сайта, а не учётные записи панели: пользователи живут
 * в `users` и с этой таблицей не связаны. Доступ — только
 * администратору (`EmployeePolicy`).
 */
final class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Content;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Сотрудник';

    protected static ?string $pluralModelLabel = 'Команда';

    /**
     * Список показывает превью, а превью читается через связь: без
     * предзагрузки это запрос на каждую строку — N+1 на списках прямо
     * назван антипаттерном в `ARCHITECTURE.md`.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('media');
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
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
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
