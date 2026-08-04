<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                // `dehydrated` при непустом значении — не удобство, а защита
                // от выхода пользователя из системы: без него сохранение
                // формы редактирования с пустым полем перезаписало бы хеш
                // пустой строкой. Каст `hashed` на модели хеширует значение
                // сам, поэтому здесь оно передаётся как есть.
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('При редактировании оставьте пустым, чтобы не менять пароль.')
                    ->maxLength(255),

                // Первая защита от самоблокировки: свою роль не понижают.
                // `disabled()` в Filament заодно снимает дегидрацию, поэтому
                // поле не участвует в сохранении вовсе — подделать его через
                // состояние Livewire не выйдет.
                Select::make('role')
                    ->label('Роль')
                    ->options(UserRole::class)
                    ->required()
                    ->default(UserRole::Manager)
                    ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) === true)
                    ->helperText(fn (?User $record): ?string => $record?->is(auth()->user()) === true
                        ? 'Свою роль изменить нельзя — иначе можно запереть себя из настроек.'
                        : 'Менеджер видит каталог и заявки; настройки сайта, контент и пользователи — только у администратора.')
                    // Вторая защита: последнего администратора не понижают.
                    // Через интерфейс сценарий почти недостижим (свою роль
                    // менять нельзя, а чужой администратор по определению
                    // не последний), но правило стоит одну функцию и держит
                    // инвариант, если условие `disabled()` когда-нибудь
                    // ослабят.
                    ->rule(static fn (?User $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null || ! $record->isAdmin()) {
                            return;
                        }

                        if ($value === UserRole::Admin->value || $value === UserRole::Admin) {
                            return;
                        }

                        $anotherAdminExists = User::query()
                            ->where('role', UserRole::Admin)
                            ->whereKeyNot($record->getKey())
                            ->exists();

                        if (! $anotherAdminExists) {
                            $fail('Это последний администратор — понизить его роль нельзя, панель останется без доступа к настройкам.');
                        }
                    }),
            ]);
    }
}
