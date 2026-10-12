<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\RequestCounter;
use App\Services\VisitRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Учёт запроса: счётчик по классу ответа и просмотр страницы.
 *
 * СТАТИСТИКА НЕ ИМЕЕТ ПРАВА УРОНИТЬ ИЛИ ЗАДЕРЖАТЬ ЗАПРОС — инвариант того же
 * ранга, что «заявка не должна теряться». Из него следует всё устройство:
 *
 * - `handle()` только передаёт запрос дальше. Учёт идёт в `terminate()`:
 *   под php-fpm это после `fastcgi_finish_request()`, посетитель ответ уже
 *   получил;
 * - тело `terminate()` целиком в `try/catch (Throwable)` — сбой Redis
 *   или ошибка в правиле учёта заканчиваются строкой в логе, а не 500;
 * - запросов к PostgreSQL здесь нет: только Redis, в который и так ходит
 *   каждый запрос (сессии, кеш настроек).
 *
 * Сквозное, а не бизнес-правило: что считать просмотром, решает
 * `VisitRecorder`, в какое поле идёт запрос — `RequestCounter`.
 */
final class RecordRequestMetrics
{
    public function __construct(
        private readonly RequestCounter $counter,
        private readonly VisitRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (config('analytics.enabled') !== true) {
            return;
        }

        try {
            $this->counter->hit(
                $this->counter->classify($request->path(), $request->userAgent(), $response->getStatusCode()),
            );

            if ($this->recorder->shouldRecord($request, $response)) {
                $this->recorder->record($request);
            }
        } catch (Throwable $e) {
            // В лог идут только класс и сообщение исключения: ни адреса
            // с параметрами, ни IP, ни User-Agent посетителя.
            Log::warning('[Analytics] учёт запроса не выполнен', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
