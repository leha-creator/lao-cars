<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsPeriod;
use App\Enums\DeviceType;
use Database\Factories\PageViewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Просмотр страницы сайта — строка собственной статистики посещений.
 *
 * Строки пишет только `VisitRecorder::flush()` пачкой из буфера Redis
 * и удаляет только `analytics:prune` через неделю. Ресурса в панели у модели
 * нет — туда она попадает агрегатами через `TrafficReport`, поэтому
 * и политики нет.
 *
 * ДВА ЗАПРЕТА, на которых держится обезличенность статистики:
 *
 * 1. **Колонки IP в таблице нет и не появляется.** Посетитель представлен
 *    `visitor_hash` — хешем с суточной солью (`VisitorHash`). Соль живёт
 *    только в Redis и исчезает через двое суток; после этого сопоставить
 *    хеш с адресом нельзя ничем. User-Agent тоже не хранится — от него
 *    остаётся `device`.
 *
 * 2. **Хеш посетителя не связывается с заявкой.** Ни колонки `visitor_hash`
 *    в `leads`, ни «пути клиента до заявки». Пока связи нет, статистика
 *    обезличена; со связью она становится профилированием конкретного
 *    человека и требует его согласия. «Показать, какие страницы смотрел
 *    клиент перед заявкой» — не улучшение, а нарушение раздела 13 политики.
 */
#[Fillable([
    'viewed_at',
    'path',
    'route_name',
    'car_id',
    'visitor_hash',
    'is_entry',
    'referrer_host',
    'utm_source',
    'utm_medium',
    'utm_campaign',
    'device',
])]
final class PageView extends Model
{
    /** @use HasFactory<PageViewFactory> */
    use HasFactory;

    public $timestamps = false;

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /**
     * Просмотры за период фильтра инфопанели, границы включительно.
     */
    #[Scope]
    protected function within(Builder $query, AnalyticsPeriod $period): void
    {
        [$from, $to] = $period->range();

        $query->whereBetween('viewed_at', [$from, $to]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'is_entry' => 'boolean',
            'device' => DeviceType::class,
        ];
    }
}
