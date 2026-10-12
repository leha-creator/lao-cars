<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PageView;
use App\Services\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Ночная чистка просмотров страниц.
 *
 * Два предохранителя:
 *
 * 1. СРОК. Удаляются строки старше начала дня «сегодня минус
 *    `analytics.retention_days`». Срок назван в политике обработки ПДн —
 *    это обещание посетителю, а не настройка удобства.
 * 2. ПОТОЛОК. Сверх `analytics.max_rows` удаляются самые старые строки:
 *    неделя не гарантирует объёма, если робота с человеческим User-Agent
 *    никто не остановит, а диск на сервере один.
 *
 * Запускается в 03:10 — ДО бэкапа базы в 03:30, чтобы дамп не возил
 * удаляемое.
 */
final class PrunePageViewsCommand extends Command
{
    protected $signature = 'analytics:prune
                            {--dry-run : Только показать, сколько строк будет удалено; база не трогается}';

    protected $description = 'Удалить просмотры страниц старше срока хранения и сверх потолка строк';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $maxRows = max(0, (int) config('analytics.max_rows'));

        // Граница — начало дня, а не «ровно N суток назад»: иначе самый
        // старый день периода таял бы по часам в течение суток.
        $cutoff = CarbonImmutable::now()
            ->startOfDay()
            ->subDays((int) config('analytics.retention_days'));

        $expired = PageView::query()->where('viewed_at', '<', $cutoff);
        $byAge = $dryRun ? $expired->count() : $expired->delete();

        $total = PageView::query()->count() - ($dryRun ? $byAge : 0);
        $excess = max(0, $total - $maxRows);
        $byLimit = 0;

        if ($excess > 0) {
            Log::warning('[Analytics] превышен потолок строк', [
                'rows' => $total,
                'max_rows' => $maxRows,
                'excess' => $excess,
                'dry_run' => $dryRun,
            ]);

            $byLimit = $dryRun ? $excess : PageView::query()
                ->whereIn('id', PageView::query()
                    ->select('id')
                    ->orderBy('viewed_at')
                    ->orderBy('id')
                    ->limit($excess))
                ->delete();
        }

        $remaining = $total - $byLimit;

        if ($dryRun) {
            $this->line("По сроку будет удалено: {$byAge}, сверх потолка: {$byLimit}, останется: {$remaining}.");
            $this->info('Пробный прогон: база не изменена.');

            return self::SUCCESS;
        }

        // Трое суток: команда идёт раз в сутки, и отметка обязана пережить
        // одну пропущенную ночь, чтобы «не запускалась» отличалось
        // от «запускалась позавчера».
        $heartbeat->beat(SchedulerHeartbeat::PRUNE, 3 * 86_400);

        Log::info('[Analytics] чистка завершена', [
            'deleted_by_age' => $byAge,
            'deleted_by_limit' => $byLimit,
            'remaining' => $remaining,
        ]);

        $this->info("Удалено по сроку: {$byAge}, сверх потолка: {$byLimit}, осталось: {$remaining}.");

        return self::SUCCESS;
    }
}
