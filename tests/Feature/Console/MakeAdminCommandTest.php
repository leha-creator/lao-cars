<?php

/*
 * Бутстрап первого администратора на проде (веха 3.5).
 *
 * Команда существует потому, что штатная `make:filament-user` роли
 * не спрашивает и заводит менеджера, которого некому повысить: панель
 * работает, войти можно, настройки закрыты навсегда.
 */

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

it('creates an administrator on an empty database', function () {
    $this->artisan('laocars:make-admin', [
        '--name' => 'Первый Админ',
        '--email' => 'first-admin@example.com',
        '--password' => 'secret1234',
    ])->assertSuccessful();

    $admin = User::where('email', 'first-admin@example.com')->firstOrFail();

    expect($admin->role)->toBe(UserRole::Admin)
        // Каст `hashed` хеширует пароль сам: Hash::make в команде дал бы
        // двойное хеширование и невозможность войти.
        ->and(Hash::check('secret1234', $admin->password))->toBeTrue();

    $this->actingAs($admin)->get('/admin/users')->assertOk();
});

it('is idempotent on an existing administrator', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com']);
    $hash = $admin->password;

    $this->artisan('laocars:make-admin', ['--email' => 'admin@example.com'])
        ->assertSuccessful();

    expect($admin->refresh()->role)->toBe(UserRole::Admin)
        ->and($admin->password)->toBe($hash)
        ->and(User::where('email', 'admin@example.com')->count())->toBe(1);
});

it('promotes an existing manager', function () {
    // Ровно тот пользователь, которого заводит make:filament-user:
    // роли она не спрашивает, поэтому он получает умолчание колонки.
    $manager = User::factory()->manager()->create(['email' => 'from-filament@example.com']);

    $this->artisan('laocars:make-admin', ['--email' => 'from-filament@example.com'])
        ->assertSuccessful();

    expect($manager->refresh()->role)->toBe(UserRole::Admin);
});

it('logs the promotion', function () {
    Log::spy();

    User::factory()->manager()->create(['email' => 'promoted@example.com']);

    $this->artisan('laocars:make-admin', ['--email' => 'promoted@example.com'])
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Пользователь повышен до администратора командой laocars:make-admin'
            && $context['from'] === UserRole::Manager->value)
        ->once();
});

it('rejects an invalid email without creating anything', function () {
    $this->artisan('laocars:make-admin', ['--email' => 'not-an-email'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('rejects a password shorter than eight characters', function () {
    $this->artisan('laocars:make-admin', [
        '--name' => 'Слабый Пароль',
        '--email' => 'weak@example.com',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});
