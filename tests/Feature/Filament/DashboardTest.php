<?php

use App\Enums\AnalyticsPeriod;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\OpsStatusWidget;
use App\Filament\Widgets\RequestsChartWidget;
use App\Filament\Widgets\ServerStatsWidget;
use App\Filament\Widgets\TopCarsWidget;
use App\Filament\Widgets\TopPagesWidget;
use App\Filament\Widgets\TrafficSourcesWidget;
use App\Filament\Widgets\TrafficStatsWidget;
use App\Filament\Widgets\VisitsChartWidget;
use App\Models\Brand;
use App\Models\Car;
use App\Models\Lead;
use App\Models\PageView;
use App\Models\User;
use App\Services\RequestCounter;
use App\Services\SchedulerHeartbeat;
use Filament\Widgets\FilamentInfoWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/*
 * Инфопанель администратора: страница, фильтр периода и восемь виджетов.
 *
 * Виджеты на странице ленивые — в HTML самой страницы их содержимого нет,
 * оно приходит вторым запросом. Поэтому страница проверяется на «открылась
 * и показала форму», а каждый виджет — отдельным компонентом.
 */

/**
 * Карточки виджета-сводки по подписям: значение, описание и цвет.
 *
 * Берутся у самого компонента, а не выискиваются в разметке: цвет карточки
 * в HTML — это класс среди десятка других, и проверка «на странице есть
 * слово danger» не говорит, у какой он карточки.
 *
 * @param  class-string  $widget
 * @param  array<string, mixed>  $filters
 * @return array<string, array{value: string, description: string|null, color: mixed}>
 */
function dashboardStats(string $widget, array $filters = []): array
{
    $component = livewire($widget, ['pageFilters' => $filters])->assertOk()->instance();

    $stats = (fn (): array => $this->getStats())->call($component);

    return collect($stats)->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => [
        'value' => (string) $stat->getValue(),
        'description' => $stat->getDescription() === null ? null : (string) $stat->getDescription(),
        'color' => $stat->getColor(),
    ]])->all();
}

/**
 * Данные графика — те же, что уходят в Chart.js.
 *
 * @param  class-string  $widget
 * @param  array<string, mixed>  $filters
 * @return array<string, mixed>
 */
function dashboardChart(string $widget, array $filters = []): array
{
    $component = livewire($widget, ['pageFilters' => $filters])->assertOk()->instance();

    return (fn (): array => $this->getData())->call($component);
}

beforeEach(function () {
    resetAnalytics();

    Carbon::setTestNow(Carbon::parse('2026-10-07 12:37:00'));

    config([
        'ops.status_file' => base_path('tests/Fixtures/ops/status.txt'),
        'ops.deploy_file' => base_path('tests/Fixtures/ops/deploy.json'),
        'ops.proc_path' => base_path('tests/Fixtures/proc'),
    ]);

    $this->admin = User::factory()->create();
    $this->manager = User::factory()->manager()->create();

    $this->actingAs($this->admin);
});

afterEach(function () {
    Carbon::setTestNow();
});

/*
 * Страница и фильтр периода.
 */

it('opens the dashboard for both roles', function () {
    $this->get('/admin')->assertOk()->assertSee('Инфопанель');

    $this->actingAs($this->manager)->get('/admin')->assertOk()->assertSee('Инфопанель');
});

it('shows the period filter to the administrator only', function () {
    livewire(Dashboard::class)
        ->assertOk()
        ->assertSee('Период')
        ->assertSee('Последний час')
        // Умолчание — «сегодня».
        ->assertSet('filters.period', AnalyticsPeriod::Today->value);

    // Менеджеру фильтровать нечего.
    $this->actingAs($this->manager);

    livewire(Dashboard::class)
        ->assertOk()
        ->assertDontSee('Период')
        ->assertDontSee('Последний час');
});

it('survives any period value', function (mixed $period) {
    // Значение живёт в адресной строке и в сессии — то есть приходит
    // от человека.
    livewire(Dashboard::class)
        ->set('filters.period', $period)
        ->assertOk();

    livewire(TrafficStatsWidget::class, ['pageFilters' => ['period' => $period]])->assertOk();
    livewire(RequestsChartWidget::class, ['pageFilters' => ['period' => $period]])->assertOk();
    livewire(TopPagesWidget::class, ['pageFilters' => ['period' => $period]])->assertOk();
})->with([
    'последний час' => ['last_hour'],
    'вчера' => ['yesterday'],
    '24 часа' => ['last_24_hours'],
    '7 дней' => ['last_7_days'],
    'незнакомое значение' => ['last_year'],
    'пустое значение' => [null],
]);

it('no longer shows the filament info widget', function () {
    // Версия Filament и ссылки на его документацию заказчику не говорят
    // ничего; версию кода показывает карточка «Версия».
    expect(filament()->getWidgets())->not->toContain(FilamentInfoWidget::class);
});

/*
 * Службы.
 */

it('shows the services block from the status file', function () {
    app(SchedulerHeartbeat::class)->beat(SchedulerHeartbeat::FLUSH, 86_400);

    $stats = dashboardStats(OpsStatusWidget::class);

    expect(array_keys($stats))->toBe(['Мониторинг', 'Очередь', 'Статистика', 'Бэкап базы', 'Сертификат', 'Версия'])
        ->and($stats['Мониторинг'])->toBe([
            'value' => 'Проблем: 2',
            'description' => 'воркер laocars-queue не запущен',
            'color' => 'danger',
        ])
        ->and($stats['Очередь']['description'])->toBe('Упавших задач нет')
        ->and($stats['Очередь']['color'])->toBe('success')
        ->and($stats['Статистика']['value'])->toBe('Обновлена только что')
        ->and($stats['Статистика']['color'])->toBe('success')
        ->and($stats['Бэкап базы'])->toBe(['value' => '9 ч назад', 'description' => 'Медиа: 56 ч назад', 'color' => 'success'])
        ->and($stats['Сертификат'])->toBe(['value' => 'Осталось 35 дн.', 'description' => 'Действует до 12.11.2026', 'color' => 'success'])
        ->and($stats['Версия'])->toBe(['value' => '25c631e', 'description' => 'Выкат 07.10.2026 11:02', 'color' => 'gray']);

    livewire(OpsStatusWidget::class)
        ->assertSee('Службы')
        ->assertSee('Проблем: 2')
        ->assertSee('воркер laocars-queue не запущен');
});

it('shows no data in grey when there is no status file', function () {
    config([
        'ops.status_file' => base_path('tests/Fixtures/ops/no-such-file.txt'),
        'ops.deploy_file' => base_path('tests/Fixtures/ops/no-such-file.json'),
    ]);

    $stats = dashboardStats(OpsStatusWidget::class);

    // Локально и в CI файла нет — блок серый, а не красный и не зелёный.
    foreach (['Мониторинг', 'Бэкап базы', 'Сертификат', 'Версия'] as $card) {
        expect($stats[$card]['value'])->toBe('Нет данных')
            ->and($stats[$card]['color'])->toBe('gray');
    }

    $this->get('/admin')->assertOk();
});

it('puts a stopped monitor above its last verdict', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 13:20:00'));

    $monitoring = dashboardStats(OpsStatusWidget::class)['Мониторинг'];

    // Остановившийся монитор иначе выглядел бы как вечное «всё в порядке»
    // — или как вечная авария, которой давно нет.
    expect($monitoring['value'])->toBe('Не обновлялся 44 мин')
        ->and($monitoring['color'])->toBe('danger');
});

it('turns the queue card red on failed jobs', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => now(),
    ]);

    expect(dashboardStats(OpsStatusWidget::class)['Очередь'])->toMatchArray([
        'description' => 'Упало задач: 1',
        'color' => 'danger',
    ]);
});

it('turns the statistics card red when the scheduler stops', function () {
    // Отметки нет вовсе.
    expect(dashboardStats(OpsStatusWidget::class)['Статистика'])->toMatchArray([
        'value' => 'Не обновлялась',
        'color' => 'danger',
    ]);

    app(SchedulerHeartbeat::class)->beat(SchedulerHeartbeat::FLUSH, 86_400);
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:49:00'));

    expect(dashboardStats(OpsStatusWidget::class)['Статистика'])->toMatchArray([
        'value' => 'Обновлена 12 мин назад',
        'color' => 'danger',
    ]);
});

it('warns about an old backup and an expiring certificate', function () {
    // Через двое суток после бэкапа из фикстуры и за 13 дней до конца
    // сертификата; сам файл при этом «свежий» — время его записи
    // подделано временным файлом.
    $file = tempnam(sys_get_temp_dir(), 'ops');
    file_put_contents($file, implode("\n", [
        'generated_at=2026-10-30T09:00:00+03:00',
        'backup_db_at=2026-10-28T03:30:00+03:00',
        'ssl_not_after=2026-11-12T08:14:00+03:00',
    ]));
    config(['ops.status_file' => $file]);
    Carbon::setTestNow(Carbon::parse('2026-10-30 09:01:00'));

    $stats = dashboardStats(OpsStatusWidget::class);

    @unlink($file);

    expect($stats['Мониторинг']['value'])->toBe('Всё в порядке')
        ->and($stats['Бэкап базы'])->toBe(['value' => '53 ч назад', 'description' => 'Копии медиа нет', 'color' => 'warning'])
        ->and($stats['Сертификат']['value'])->toBe('Осталось 12 дн.')
        ->and($stats['Сертификат']['color'])->toBe('danger');
});

/*
 * Сервер.
 */

it('shows the server block from proc', function () {
    $stats = dashboardStats(ServerStatsWidget::class);

    expect(array_keys($stats))->toBe(['Диск', 'Память', 'Нагрузка'])
        ->and($stats['Диск']['value'])->toStartWith('Свободно ')->toEndWith(' ГБ')
        // 1 203 448 кБ доступно из 2 014 756 кБ — не 85 МБ «свободной».
        ->and($stats['Память'])->toBe([
            'value' => 'Доступно 1,1 ГБ',
            'description' => 'из 1,9 ГБ · swap: свободно 1,0 ГБ из 1,0 ГБ',
            'color' => 'success',
        ]);

    livewire(ServerStatsWidget::class)->assertSee('Сервер')->assertSee('Доступно 1,1 ГБ');
});

it('colours the disk card by the threshold from the config', function () {
    config(['ops.disk_warning_percent' => 100]);

    expect(dashboardStats(ServerStatsWidget::class)['Диск']['color'])->toBe('success');

    // Порог ноль — любой занятый байт уже выше него.
    config(['ops.disk_warning_percent' => 0]);

    expect(dashboardStats(ServerStatsWidget::class)['Диск']['color'])->toBe('danger');
});

it('shows unknown server metrics in grey, not as zeros', function () {
    config(['ops.proc_path' => base_path('tests/Fixtures/no-such-proc')]);

    $stats = dashboardStats(ServerStatsWidget::class);

    // Ноль доступной памяти и неизвестное значение обязаны выглядеть
    // по-разному.
    expect($stats['Память'])->toBe(['value' => 'Нет данных', 'description' => null, 'color' => 'gray']);
});

/*
 * Запросы.
 */

it('charts the request counter by class of response', function () {
    $counter = app(RequestCounter::class);

    foreach (['site_2xx', 'site_2xx', 'site_3xx', 'site_4xx', 'site_5xx', 'bot', 'bot', 'panel', 'system'] as $field) {
        $counter->hit($field);
    }

    $chart = dashboardChart(RequestsChartWidget::class, ['period' => 'today']);
    $sums = collect($chart['datasets'])->mapWithKeys(fn (array $set): array => [$set['label'] => array_sum($set['data'])]);

    // 00:00 … 12:00.
    expect($chart['labels'])->toHaveCount(13)
        ->and($chart['labels'][12])->toBe('12:00')
        ->and($sums->all())->toBe([
            'Сайт: успешно' => 3,
            'Сайт: ошибки посетителя (4xx)' => 1,
            'Сайт: ошибки сервера (5xx)' => 1,
            'Роботы' => 2,
            'Панель и служебные' => 2,
        ]);

    livewire(RequestsChartWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('Запросы к приложению')
        ->assertSee('Всего: 9')
        ->assertSee('ошибок сервера (5xx): 1');
});

it('follows the period in the request chart', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 15:00:00'));
    app(RequestCounter::class)->hit('site_2xx');

    Carbon::setTestNow(Carbon::parse('2026-10-07 12:37:00'));
    app(RequestCounter::class)->hit('site_2xx');
    app(RequestCounter::class)->hit('site_2xx');

    $total = fn (string $period): int => array_sum(dashboardChart(RequestsChartWidget::class, ['period' => $period])['datasets'][0]['data']);

    expect($total('today'))->toBe(2)
        ->and($total('yesterday'))->toBe(1)
        ->and($total('last_7_days'))->toBe(3)
        // Неделя — по дням, подписи датой.
        ->and(dashboardChart(RequestsChartWidget::class, ['period' => 'last_7_days'])['labels'])
        ->toBe(['01.10', '02.10', '03.10', '04.10', '05.10', '06.10', '07.10']);
});

/*
 * Посещаемость.
 */

it('shows the traffic totals with the honest visitors label', function () {
    PageView::factory()->count(3)->create(['viewed_at' => '2026-10-07 10:00:00']);
    PageView::factory()->mobile()->create(['viewed_at' => '2026-10-07 11:00:00']);
    PageView::factory()->create(['viewed_at' => '2026-10-06 11:00:00']);
    Lead::factory()->create(['created_at' => '2026-10-07 11:30:00']);

    $stats = dashboardStats(TrafficStatsWidget::class, ['period' => 'today']);

    expect($stats['Просмотры']['value'])->toBe('4')
        // «По дням», а не «уникальные»: оговорка стоит у самого числа.
        ->and($stats)->toHaveKey('Посетители (по дням)')
        ->and($stats['Посетители (по дням)']['value'])->toBe('4')
        ->and($stats['Посетители (по дням)']['description'])->toContain('посчитан за каждый день')
        ->and($stats['Заявки']['value'])->toBe('1')
        ->and($stats['Конверсия']['value'])->toBe('25,0%')
        ->and($stats['С мобильных']['value'])->toBe('25,0%');

    // Смена периода меняет числа.
    expect(dashboardStats(TrafficStatsWidget::class, ['period' => 'yesterday'])['Просмотры']['value'])->toBe('1')
        ->and(dashboardStats(TrafficStatsWidget::class, ['period' => 'last_7_days'])['Просмотры']['value'])->toBe('5');
});

it('shows zeros and dashes on empty statistics', function () {
    $stats = dashboardStats(TrafficStatsWidget::class);

    expect($stats['Просмотры']['value'])->toBe('0')
        ->and($stats['Посетители (по дням)']['value'])->toBe('0')
        // «—», а не «0%»: доля при нуле посетителей не определена.
        ->and($stats['Конверсия']['value'])->toBe('—')
        ->and($stats['С мобильных']['value'])->toBe('—');

    $chart = dashboardChart(VisitsChartWidget::class);

    expect(array_sum($chart['datasets'][0]['data']))->toBe(0)
        ->and($chart['labels'])->toHaveCount(13);

    expect(array_sum(dashboardChart(RequestsChartWidget::class)['datasets'][0]['data']))->toBe(0);
});

it('charts views and visitors', function () {
    PageView::factory()->byVisitor(str_repeat('a', 32))->count(2)->create(['viewed_at' => '2026-10-07 09:10:00']);
    PageView::factory()->create(['viewed_at' => '2026-10-07 09:40:00']);

    $chart = dashboardChart(VisitsChartWidget::class, ['period' => 'today']);

    expect($chart['datasets'][0]['label'])->toBe('Просмотры')
        ->and($chart['datasets'][0]['data'][9])->toBe(3)
        ->and($chart['datasets'][1]['label'])->toBe('Посетители')
        ->and($chart['datasets'][1]['data'][9])->toBe(2);
});

/*
 * Таблицы.
 */

it('lists the top pages in order', function () {
    PageView::factory()->count(3)->create(['path' => '/catalog']);
    PageView::factory()->count(2)->create(['path' => '/services']);
    PageView::factory()->create(['path' => '/about']);

    livewire(TopPagesWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSee('Страницы')
        ->assertSeeInOrder(['/catalog', '/services', '/about'])
        // Адрес — ссылкой на страницу сайта.
        ->assertSeeHtml('href="'.url('/catalog').'"');
});

it('shows a hint instead of an empty table', function () {
    livewire(TopPagesWidget::class)
        ->assertSee('Данных за период нет')
        ->assertSee('Статистика обновляется раз в минуту.');
});

it('lists the sources and hides the tags block until there are tags', function () {
    PageView::factory()->fromReferrer('yandex.ru')->count(2)->create();
    PageView::factory()->entry()->create();

    livewire(TrafficSourcesWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSeeInOrder(['Источники', 'Яндекс', 'Прямой заход'])
        // Заголовок блока над пустотой читался бы как поломка.
        ->assertDontSee('Рекламные метки');

    PageView::factory()->entry()->create(['utm_source' => 'yandex', 'utm_medium' => 'cpc', 'utm_campaign' => 'autumn']);
    PageView::factory()->entry()->create(['utm_source' => 'vk']);

    livewire(TrafficSourcesWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSeeInOrder(['Источники', 'Яндекс', 'Рекламные метки', 'vk', 'yandex / cpc / autumn']);
});

it('lists the top cars with a link to the card in the panel', function () {
    $zeekr = Car::factory()->for(Brand::factory()->create(['name' => 'Zeekr']))->create(['model' => '001', 'year' => 2024]);
    $lixiang = Car::factory()->for(Brand::factory()->create(['name' => 'Lixiang']))->create(['model' => 'L9', 'year' => 2025]);

    PageView::factory()->forCar($zeekr)->count(2)->create();
    PageView::factory()->forCar($lixiang)->mobile()->create();
    PageView::factory()->mobile()->create();

    livewire(TopCarsWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertSeeInOrder(['Zeekr 001 2024', 'Lixiang L9 2025'])
        ->assertSeeHtml('/admin/cars/'.$zeekr->id.'/edit')
        // Доли устройств — строкой в описании таблицы.
        ->assertSee('Устройства: компьютеры 50%, телефоны 50%, планшеты 0%');
});

it('keeps the top cars alive after a car is deleted', function () {
    $kept = Car::factory()->for(Brand::factory()->create(['name' => 'Zeekr']))->create(['model' => '001', 'year' => 2024]);
    $deleted = Car::factory()->for(Brand::factory()->create(['name' => 'Voyah']))->create(['model' => 'Free', 'year' => 2023]);

    PageView::factory()->forCar($kept)->create();
    PageView::factory()->forCar($deleted)->count(3)->create();

    $deleted->delete();

    livewire(TopCarsWidget::class, ['pageFilters' => ['period' => 'today']])
        ->assertOk()
        ->assertSee('Zeekr 001 2024')
        ->assertDontSee('Voyah');
});

it('says nothing about devices when there are no views', function () {
    livewire(TopCarsWidget::class)->assertOk()->assertDontSee('Устройства:');
});
