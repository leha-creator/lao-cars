<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AnalyticsPeriod;
use App\Enums\DeviceType;
use App\Models\Lead;
use App\Models\PageView;
use App\Support\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Отчёт о посещаемости для инфопанели: всё чтение `page_views` в одном месте.
 *
 * Виджеты запросов не пишут — они зовут методы отсюда. Так счёт уникальных
 * существует в одном выражении, а не в шести похожих.
 *
 * КАК СЧИТАЮТСЯ ПОСЕТИТЕЛИ. Хеш посетителя уникален ВНУТРИ ДНЯ (суточная
 * соль, см. `VisitorHash`), поэтому за период считается
 * `count(distinct (дата, хеш))`: человек, заходивший три дня подряд,
 * посчитан трижды. Это честная обратная сторона обезличивания, и подпись
 * в панели обязана её повторять — «Посетители (по дням)», а не «Уникальные
 * за неделю».
 *
 * Границы дня — в часовом поясе приложения: `viewed_at` — `timestamp` без
 * пояса, и пишется он уже московским временем, поэтому `viewed_at::date` —
 * московская дата без пересчёта.
 *
 * Сервис без состояния и без побочных эффектов, поэтому не логирует.
 */
final class TrafficReport
{
    /** Посетитель за период: пара «день, хеш». */
    private const VISITORS = 'count(distinct (viewed_at::date, visitor_hash))';

    /**
     * Итоги периода.
     *
     * @return array{views: int, visitors: int, leads: int, conversion: float|null, mobile_share: float|null}
     */
    public function summary(AnalyticsPeriod $period): array
    {
        $row = $this->views($period)
            ->selectRaw('count(*) as views')
            ->selectRaw(self::VISITORS.' as visitors')
            ->selectRaw('count(*) filter (where device = ?) as mobile', [DeviceType::Mobile->value])
            ->toBase()
            ->first();

        $views = (int) $row->views;
        $visitors = (int) $row->visitors;

        // Заявки за тот же период — по времени создания. С просмотрами они
        // не связаны ничем, кроме периода: хеш посетителя к заявке
        // не привязывается (запрет в PHPDoc `PageView`).
        $leads = Lead::query()->whereBetween('created_at', $period->range())->count();

        return [
            'views' => $views,
            'visitors' => $visitors,
            'leads' => $leads,
            // `null`, а не ноль: «0%» при нуле посетителей — утверждение
            // «никто не оставил заявку», а его не на чем основать.
            'conversion' => $visitors === 0 ? null : round($leads / $visitors * 100, 1),
            'mobile_share' => $views === 0 ? null : round((int) $row->mobile / $views * 100, 1),
        ];
    }

    /**
     * Просмотры и посетители по часам или дням, с нулями на пустых корзинах.
     *
     * @return list<array{at: CarbonImmutable, views: int, visitors: int}>
     */
    public function series(AnalyticsPeriod $period): array
    {
        $unit = $period->granularity();
        $format = $unit === 'day' ? 'Y-m-d' : 'Y-m-d H';

        // Имя единицы — из перечисления, не из ввода: в запрос оно идёт
        // привязкой, а не подстановкой в строку.
        $rows = $this->views($period)
            ->selectRaw('date_trunc(?, viewed_at) as bucket', [$unit])
            ->selectRaw('count(*) as views')
            ->selectRaw('count(distinct visitor_hash) as visitors')
            ->groupByRaw('1')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row): string => CarbonImmutable::parse($row->bucket)->format($format));

        return array_map(static fn (CarbonImmutable $bucket): array => [
            'at' => $bucket,
            'views' => (int) ($rows[$bucket->format($format)]->views ?? 0),
            'visitors' => (int) ($rows[$bucket->format($format)]->visitors ?? 0),
        ], $period->buckets());
    }

    /**
     * Самые просматриваемые страницы.
     *
     * @return list<array{path: string, views: int, visitors: int}>
     */
    public function topPages(AnalyticsPeriod $period, int $limit = 10): array
    {
        return $this->views($period)
            ->select('path')
            ->selectRaw('count(*) as views')
            ->selectRaw(self::VISITORS.' as visitors')
            ->groupBy('path')
            ->orderByDesc('views')
            ->orderBy('path')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'path' => (string) $row->path,
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    /**
     * Откуда приходят: только заходы, без переходов внутри сайта.
     *
     * @return list<array{host: string|null, label: string, entries: int}>
     */
    public function sources(AnalyticsPeriod $period, int $limit = 10): array
    {
        return $this->views($period)
            ->where('is_entry', true)
            ->select('referrer_host')
            ->selectRaw('count(*) as entries')
            ->groupBy('referrer_host')
            ->orderByDesc('entries')
            // Прямой заход — `NULL` в колонке источника. При равном счёте
            // он идёт после названных источников; без явного указания
            // PostgreSQL ставил бы его то первым, то последним.
            ->orderByRaw('referrer_host asc nulls last')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'host' => $row->referrer_host,
                'label' => TrafficSource::label($row->referrer_host),
                'entries' => (int) $row->entries,
            ])
            ->all();
    }

    /**
     * Рекламные метки: просмотры, пришедшие с `utm_source`.
     *
     * @return list<array{source: string, medium: string|null, campaign: string|null, entries: int}>
     */
    public function campaigns(AnalyticsPeriod $period, int $limit = 10): array
    {
        return $this->views($period)
            ->whereNotNull('utm_source')
            ->where('utm_source', '!=', '')
            ->select(['utm_source', 'utm_medium', 'utm_campaign'])
            ->selectRaw('count(*) as entries')
            ->groupBy(['utm_source', 'utm_medium', 'utm_campaign'])
            ->orderByDesc('entries')
            ->orderBy('utm_source')
            ->orderByRaw('utm_medium asc nulls last')
            ->orderByRaw('utm_campaign asc nulls last')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'source' => (string) $row->utm_source,
                'medium' => $row->utm_medium,
                'campaign' => $row->utm_campaign,
                'entries' => (int) $row->entries,
            ])
            ->all();
    }

    /**
     * Самые просматриваемые автомобили — с маркой и моделью одним запросом.
     *
     * `join`, а не связь: десять строк через `car.brand` — это запрос
     * на строку. Просмотры удалённых автомобилей (`car_id` обнулён) в топ
     * не попадают — показывать там нечего.
     *
     * @return list<array{car_id: int, title: string, views: int, visitors: int}>
     */
    public function topCars(AnalyticsPeriod $period, int $limit = 10): array
    {
        return $this->views($period)
            ->join('cars', 'cars.id', '=', 'page_views.car_id')
            ->join('brands', 'brands.id', '=', 'cars.brand_id')
            ->select(['page_views.car_id', 'brands.name as brand', 'cars.model', 'cars.year'])
            ->selectRaw('count(*) as views')
            ->selectRaw(self::VISITORS.' as visitors')
            ->groupBy(['page_views.car_id', 'brands.name', 'cars.model', 'cars.year'])
            ->orderByDesc('views')
            ->orderBy('page_views.car_id')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'car_id' => (int) $row->car_id,
                'title' => trim($row->brand.' '.$row->model.' '.$row->year),
                'views' => (int) $row->views,
                'visitors' => (int) $row->visitors,
            ])
            ->all();
    }

    /**
     * Доли устройств по просмотрам — все три типа, включая нулевые.
     *
     * @return list<array{device: DeviceType, views: int, share: float|null}>
     */
    public function devices(AnalyticsPeriod $period): array
    {
        $counts = $this->views($period)
            ->select('device')
            ->selectRaw('count(*) as views')
            ->groupBy('device')
            ->toBase()
            ->pluck('views', 'device');

        $total = (int) $counts->sum();

        return array_map(static fn (DeviceType $device): array => [
            'device' => $device,
            'views' => (int) ($counts[$device->value] ?? 0),
            'share' => $total === 0 ? null : round((int) ($counts[$device->value] ?? 0) / $total * 100, 1),
        ], DeviceType::cases());
    }

    /**
     * @return Builder<PageView>
     */
    private function views(AnalyticsPeriod $period): Builder
    {
        [$from, $to] = $period->range();

        // Колонка названа с таблицей: в `topCars()` к запросу
        // присоединяются таблицы, и голое имя стало бы двусмысленным
        // при первой же одноимённой колонке.
        return PageView::query()->whereBetween('page_views.viewed_at', [$from, $to]);
    }
}
