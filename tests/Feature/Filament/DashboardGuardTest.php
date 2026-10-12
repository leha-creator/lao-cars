<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\AdminOnly;
use App\Models\Car;
use App\Models\PageView;
use App\Models\User;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\File;

use function Pest\Livewire\livewire;

/*
 * Сквозные сторожа инфопанели.
 *
 * Оба первых перебирают КАТАЛОГ `app/Filament/Widgets`, а не список
 * в тесте: девятый виджет, добавленный без трейта доступа или с опросом
 * по умолчанию, обязан уронить набор сам — список в тесте его бы
 * не заметил, потому что дополнять список пришлось бы тому же человеку,
 * который забыл трейт.
 */

/**
 * Классы виджетов проекта — по файлам каталога, без `Concerns/`.
 *
 * @return list<class-string<Widget>>
 */
function dashboardWidgets(): array
{
    return collect(File::files(app_path('Filament/Widgets')))
        ->map(fn (SplFileInfo $file): string => 'App\\Filament\\Widgets\\'.$file->getBasename('.php'))
        ->values()
        ->all();
}

beforeEach(function () {
    resetAnalytics();

    config([
        'ops.status_file' => base_path('tests/Fixtures/ops/status.txt'),
        'ops.deploy_file' => base_path('tests/Fixtures/ops/deploy.json'),
        'ops.proc_path' => base_path('tests/Fixtures/proc'),
    ]);
});

it('finds the widgets it is supposed to guard', function () {
    // Нижняя граница перебора: тест по пустому каталогу прошёл бы вхолостую.
    expect(dashboardWidgets())->toHaveCount(8);

    foreach (dashboardWidgets() as $widget) {
        expect(is_subclass_of($widget, Widget::class))->toBeTrue("{$widget} — не виджет Filament");
    }
});

it('closes every widget to the manager', function () {
    foreach (dashboardWidgets() as $widget) {
        expect(class_uses($widget))->toContain(AdminOnly::class);

        $this->actingAs(User::factory()->manager()->create());

        expect($widget::canView())->toBeFalse("{$widget} виден менеджеру")
            ->and($widget::canAccess())->toBeFalse();

        $this->actingAs(User::factory()->create());

        expect($widget::canView())->toBeTrue("{$widget} не виден администратору")
            ->and($widget::canAccess())->toBeTrue();
    }

    // Гость — тоже не администратор.
    auth()->logout();

    foreach (dashboardWidgets() as $widget) {
        expect($widget::canView())->toBeFalse();
    }
});

it('gives the manager a dashboard without a single admin block', function () {
    $this->actingAs(User::factory()->manager()->create());

    $response = $this->get('/admin')->assertOk();

    foreach (dashboardWidgets() as $widget) {
        $response->assertDontSeeLivewire($widget);
    }

    // Страница при этом не пустая: карточка учётной записи остаётся всем.
    expect(livewire(Dashboard::class)->instance()->getVisibleWidgets())->toBe([AccountWidget::class]);
});

it('gives the administrator all eight blocks', function () {
    $this->actingAs(User::factory()->create());

    // Каждый виджет каталога стоит на странице: забытый в `getWidgets()`
    // не виден никому, и заметить это можно только глазами.
    expect(livewire(Dashboard::class)->instance()->getVisibleWidgets())
        ->toEqualCanonicalizing([AccountWidget::class, ...dashboardWidgets()]);
});

it('declares the polling interval in every widget itself', function () {
    foreach (dashboardWidgets() as $widget) {
        // Унаследованное значение — это `5s` трейта `CanPoll`: восемь
        // виджетов на нём дают восемь запросов Livewire каждые пять секунд
        // с каждой вкладки, и инфопанель сама становится нагрузкой, которую
        // показывает. Интервал обязан быть решением, записанным в классе.
        $property = new ReflectionProperty($widget, 'pollingInterval');

        expect($property->getDeclaringClass()->getName())->toBe($widget, "{$widget} наследует интервал опроса");

        $interval = $property->getDefaultValue();

        // Чаще минуты не опрашивает никто.
        expect($interval === null || (int) $interval >= 60)->toBeTrue("{$widget} опрашивается чаще раза в минуту: {$interval}");
    }
});

it('does not query more as the statistics grow', function () {
    $this->actingAs(User::factory()->create());

    $car = Car::factory()->create();

    $measure = function (): int {
        return countQueries(function (): void {
            $this->get('/admin')->assertOk();

            // Виджеты на странице ленивые: их запросы идут не в ответе
            // страницы, а при собственной загрузке каждого.
            foreach (dashboardWidgets() as $widget) {
                livewire($widget, ['pageFilters' => ['period' => 'last_7_days']])->assertOk();
            }
        });
    };

    PageView::factory()->count(5)->create();
    PageView::factory()->forCar($car)->count(5)->create();

    // Прогрев: первый проход платит разовые запросы, которые кешируются
    // (без него второй замер оказывается МЕНЬШЕ первого, и сравнение
    // ловит не рост, а остывший кеш).
    $measure();

    $few = $measure();

    PageView::factory()->count(245)->fromReferrer('yandex.ru')->create(['utm_source' => 'yandex']);
    PageView::factory()->forCar($car)->count(245)->create();

    $many = $measure();

    expect($few)->toBeGreaterThan(0)
        ->and($many)->toBe($few);
});
