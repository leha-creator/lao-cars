<?php

/*
 * Раздача ролей и защита от самоблокировки (веха 3.5).
 *
 * Отдельный акцент — на двух сценариях, которые чинятся только руками
 * в psql: перезаписанный пустым значением пароль и панель, оставшаяся
 * без администратора.
 */

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    // Фабрика по умолчанию отдаёт администратора — см. её PHPDoc.
    $this->actingAs(User::factory()->create());
});

it('shows users in the list', function () {
    $users = User::factory()->count(2)->create();

    livewire(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords($users);
});

it('creates a manager who can then log into the panel', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Новый Менеджер',
            'email' => 'new-manager@example.com',
            'password' => 'secret1234',
            'role' => UserRole::Manager->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $manager = User::where('email', 'new-manager@example.com')->firstOrFail();

    expect($manager->role)->toBe(UserRole::Manager);

    $this->actingAs($manager)->get('/admin/cars')->assertOk();
});

it('keeps the password hash when the field is left empty on edit', function () {
    $target = User::factory()->manager()->create();
    $hash = $target->password;

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => 'Переименован',
            'email' => $target->email,
            'password' => '',
            'role' => UserRole::Manager->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->password)->toBe($hash)
        ->and($target->name)->toBe('Переименован');
});

it('changes the password when the field is filled', function () {
    $target = User::factory()->manager()->create();

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'другой-пароль-1234',
            'role' => UserRole::Manager->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('другой-пароль-1234', $target->refresh()->password))->toBeTrue();
});

it('disables the role field on your own record', function () {
    livewire(EditUser::class, ['record' => auth()->id()])
        ->assertFormFieldDisabled('role');
});

it('refuses to demote the last administrator', function () {
    $admin = auth()->user();
    User::factory()->manager()->create();

    livewire(EditUser::class, ['record' => $admin->getKey()])
        ->fillForm(['role' => UserRole::Manager->value])
        ->call('save');

    expect($admin->refresh()->role)->toBe(UserRole::Admin);
});

it('refuses to delete the last administrator', function () {
    $admin = auth()->user();
    User::factory()->manager()->create();

    livewire(EditUser::class, ['record' => $admin->getKey()])
        ->callAction(DeleteAction::class);

    expect(User::whereKey($admin->getKey())->exists())->toBeTrue();
});

it('deletes an administrator while another one remains', function () {
    $other = User::factory()->create();

    livewire(EditUser::class, ['record' => $other->getKey()])
        ->callAction(DeleteAction::class);

    expect(User::whereKey($other->getKey())->exists())->toBeFalse();
});

it('logs a role change', function () {
    Log::spy();

    $target = User::factory()->manager()->create();

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'password' => '',
            'role' => UserRole::Admin->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->role)->toBe(UserRole::Admin);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Изменена роль пользователя панели'
            && $context['from'] === UserRole::Manager->value
            && $context['to'] === UserRole::Admin->value)
        ->once();
});

it('does not log when the role stays the same', function () {
    Log::spy();

    $target = User::factory()->manager()->create();

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm([
            'name' => 'Другое имя',
            'email' => $target->email,
            'password' => '',
            'role' => UserRole::Manager->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Log::shouldNotHaveReceived('info', ['Изменена роль пользователя панели']);
});
