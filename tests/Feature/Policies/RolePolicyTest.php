<?php

/*
 * Матрица прав администратора и менеджера (веха 3.5).
 *
 * Центральный тест вехи: дыру в правах видно либо здесь, либо в проде.
 *
 * Умолчание `User::factory()` — администратор (см. PHPDoc фабрики),
 * поэтому менеджер везде заводится явным состоянием `->manager()`.
 */

use App\Enums\UserRole;
use App\Filament\Pages\ManageSiteSettings;
use App\Models\Brand;
use App\Models\Car;
use App\Models\CarAttribute;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\Media;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

use function Pest\Livewire\livewire;

/**
 * Подписи всех пунктов бокового меню, видимых текущему пользователю.
 *
 * @return list<string>
 */
function navigationLabels(): array
{
    $labels = [];

    foreach (Filament::getNavigation() as $group) {
        foreach ($group->getItems() as $item) {
            $labels[] = $item->getLabel();
        }
    }

    return $labels;
}

it('answers viewAny according to the role matrix', function (string $model, bool $managerAllowed) {
    $admin = User::factory()->create();
    $manager = User::factory()->manager()->create();

    expect(Gate::forUser($admin)->allows('viewAny', $model))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('viewAny', $model))->toBe($managerAllowed);
})->with([
    // Каталог и заявки — работа менеджера.
    'cars' => [Car::class, true],
    'brands' => [Brand::class, true],
    'car attributes' => [CarAttribute::class, true],
    // Ресурса заявок ещё нет (веха 3.7), поэтому права проверяются
    // напрямую через Gate — иначе половина определения роли не покрыта.
    'leads' => [Lead::class, true],
    'lead comments' => [LeadComment::class, true],
    // Контент сайта, медиабиблиотека и раздача прав — администратор.
    'services' => [Service::class, false],
    'employees' => [Employee::class, false],
    'reviews' => [Review::class, false],
    'media' => [Media::class, false],
    'users' => [User::class, false],
]);

it('closes admin-only routes for a manager', function (string $route) {
    $this->actingAs(User::factory()->manager()->create());

    $this->get($route)->assertForbidden();
})->with([
    '/admin/services',
    '/admin/employees',
    '/admin/reviews',
    '/admin/media',
    '/admin/users',
    '/admin/manage-site-settings',
]);

it('opens admin-only routes for an administrator', function (string $route) {
    $this->actingAs(User::factory()->create());

    $this->get($route)->assertOk();
})->with([
    '/admin/services',
    '/admin/employees',
    '/admin/reviews',
    '/admin/media',
    '/admin/users',
    '/admin/manage-site-settings',
]);

it('opens the catalog for a manager', function (string $route) {
    $this->actingAs(User::factory()->manager()->create());

    $this->get($route)->assertOk();
})->with([
    '/admin/cars',
    '/admin/brands',
    '/admin/car-attributes',
]);

it('hides forbidden sections from the manager navigation', function () {
    // Не только прямой переход: пункт меню, ведущий в 403, — это дефект
    // интерфейса, а не защита. Проверяется сама модель навигации, а не
    // HTML: боковое меню рендерит отдельный Livewire-компонент, и в теле
    // первого ответа его может не быть — тогда assertDontSee проходил бы
    // вхолостую.
    $this->actingAs(User::factory()->manager()->create());

    expect(navigationLabels())
        ->not->toContain('Услуги и запчасти')
        ->not->toContain('Команда')
        ->not->toContain('Отзывы')
        ->not->toContain('Медиабиблиотека')
        ->not->toContain('Пользователи')
        ->not->toContain('Настройки сайта')
        // Нижняя граница: пустое меню прошло бы все проверки выше.
        ->toContain('Автомобили');
});

it('shows every section to an administrator', function () {
    $this->actingAs(User::factory()->create());

    expect(navigationLabels())
        ->toContain('Услуги и запчасти')
        ->toContain('Команда')
        ->toContain('Отзывы')
        ->toContain('Медиабиблиотека')
        ->toContain('Пользователи')
        ->toContain('Настройки сайта')
        ->toContain('Автомобили');
});

it('closes the settings page for a manager at the page level', function () {
    // Страница — не ресурс, политика к ней не применяется, поэтому
    // `canAccess()` проверяется отдельно от кода ответа.
    $this->actingAs(User::factory()->manager()->create());
    expect(ManageSiteSettings::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create());
    expect(ManageSiteSettings::canAccess())->toBeTrue();
});

it('defaults a user created without a role to manager', function () {
    $user = User::create([
        'name' => 'Без роли',
        'email' => 'no-role@example.com',
        'password' => 'secret1234',
    ]);

    expect($user->refresh()->role)->toBe(UserRole::Manager)
        ->and($user->isAdmin())->toBeFalse();
});

it('keeps the panel in strict authorization mode', function () {
    // Тест-сторож: снятие `strictAuthorization()` возвращает поведение
    // «нет политики = разрешено», и заметить это иначе нечем.
    expect(Filament::getDefaultPanel()->isAuthorizationStrict())->toBeTrue();
});

it('lets a manager change their own password on the profile page', function () {
    $manager = User::factory()->manager()->create();
    $this->actingAs($manager);

    $this->get('/admin/profile')->assertOk();

    livewire(Filament::getDefaultPanel()->getProfilePage())
        ->fillForm([
            'name' => $manager->name,
            'email' => $manager->email,
            'password' => 'новый-пароль-1234',
            'passwordConfirmation' => 'новый-пароль-1234',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('новый-пароль-1234', $manager->refresh()->password))->toBeTrue();
});
