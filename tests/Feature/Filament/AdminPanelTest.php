<?php

/*
 * Админ-панель Filament доступна и закрыта авторизацией.
 *
 * Ресурсы (CarResource, LeadResource и прочие) относятся к вехам 3.4–3.5 —
 * здесь проверяется только сам каркас панели: маршруты, вход и дашборд.
 */

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Pages\Dashboard;

use function Pest\Livewire\livewire;

it('redirects guests from the panel to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('serves the login page', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('ЛАО КАРС', escape: false);
});

it('logs an existing user into the panel', function () {
    $user = User::factory()->create([
        'email' => 'manager@laocars.ru',
        'password' => 'секрет-для-теста',
    ]);

    livewire(Login::class)
        ->fillForm([
            'email' => 'manager@laocars.ru',
            'password' => 'секрет-для-теста',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($user);
});

it('rejects wrong credentials', function () {
    User::factory()->create(['email' => 'manager@laocars.ru']);

    livewire(Login::class)
        ->fillForm([
            'email' => 'manager@laocars.ru',
            'password' => 'не-тот-пароль',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('shows the dashboard to an authenticated user', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertOk();

    livewire(Dashboard::class)->assertOk();
});
