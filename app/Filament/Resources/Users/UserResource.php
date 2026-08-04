<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Сотрудники панели и раздача ролей.
 *
 * В формулировке вехи 3.5 этого ресурса не было — дыра закрыта здесь:
 * без него завести менеджера можно только из tinker, а это не сдача вехи.
 *
 * Доступ ограничен `UserPolicy` (только администратор); отдельных проверок
 * внутри ресурса нет и быть не должно — права живут в политиках
 * (правило `rules/base.md`).
 *
 * Свою учётную запись пользователь правит не здесь, а на странице профиля
 * (`Panel::profile()` в `AdminPanelProvider`): менеджеру этот ресурс
 * недоступен, и без профиля придуманный администратором пароль оставался
 * бы известен администратору бессрочно.
 */
final class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
