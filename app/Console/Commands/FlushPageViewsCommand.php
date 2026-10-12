<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SchedulerHeartbeat;
use App\Services\VisitRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Перенос просмотров страниц из буфера Redis в таблицу `page_views`.
 *
 * Запускается планировщиком раз в минуту — отсюда отставание статистики
 * до минуты. Локально на Windows cron нет: статистика появляется после
 * ручного запуска этой команды или под `php artisan schedule:work`.
 *
 * Сбой переноса не ловится: пачку `VisitRecorder` возвращает в буфер сам,
 * а упавшая команда не ставит отметку пульса — и блок служб инфопанели
 * краснеет, что и требуется.
 */
final class FlushPageViewsCommand extends Command
{
    /**
     * Потолок пачек за один запуск.
     *
     * Буфер ограничен `analytics.buffer_limit`, и при умолчаниях он
     * вычерпывается за двадцать пачек. Потолок — страховка от цикла,
     * который не заканчивается, потому что буфер наполняют быстрее,
     * чем команда его переносит: следующий запуск через минуту.
     */
    private const MAX_BATCHES = 50;

    protected $signature = 'analytics:flush';

    protected $description = 'Перенести просмотры страниц из буфера Redis в базу';

    public function handle(VisitRecorder $recorder, SchedulerHeartbeat $heartbeat): int
    {
        $batch = max(1, (int) config('analytics.flush_batch'));
        $rows = 0;

        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $moved = $recorder->flush($batch);
            $rows += $moved;

            // Буфер пуст, когда вынуть было нечего. Сверять с размером
            // пачки нельзя: битые записи пропускаются, и неполная пачка
            // не означает, что за ней ничего нет.
            if ($moved === 0 && $recorder->pending() === 0) {
                break;
            }
        }

        // Отметка ставится и при пустом буфере: она про «планировщик жив»,
        // а не про «были посетители».
        $heartbeat->beat(SchedulerHeartbeat::FLUSH, 86_400);

        // Только когда есть что сообщить — иначе это 1440 пустых строк
        // в сутки в логе, за размером которого инфопанель и следит.
        if ($rows > 0) {
            Log::info('[Analytics] буфер сброшен', ['rows' => $rows]);
        }

        $this->info("Перенесено просмотров: {$rows}.");

        return self::SUCCESS;
    }
}
