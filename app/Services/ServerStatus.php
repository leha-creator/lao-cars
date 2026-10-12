<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Метрики сервера для инфопанели: диск, память, нагрузка.
 *
 * Читается только то, что PHP отдаёт сам, — `/proc` и штатные функции.
 * Ни `exec()`, ни `shell_exec()` здесь нет и быть не должно: пул php-fpm
 * не получает права запускать команды ради страницы админки.
 *
 * Показывается ТЕКУЩЕЕ значение. Графиков во времени нет намеренно: для них
 * нужен постоянный сборщик метрик — это Netdata или Zabbix, а не страница
 * панели.
 *
 * НА ПРОДЕ ПРОВЕРЯТЬ ЧЕРЕЗ САМУ ПАНЕЛЬ, а не `php artisan tinker`: у CLI
 * свой `php.ini`, и «в консоли работает» про FPM с его `open_basedir`
 * и `disable_functions` не говорит ничего.
 */
final class ServerStatus
{
    /**
     * @param  string|null  $procPath  каталог `/proc` — параметр, чтобы тест
     *                                 подставил каталог с фикстурами и разбор
     *                                 проверялся на Windows и в CI одинаково;
     *                                 по умолчанию — `ops.proc_path`
     * @param  string|null  $diskPath  каталог, чей том измеряется; по умолчанию —
     *                                 корень проекта: корень на сервере один,
     *                                 тома Docker и бэкапы лежат на нём же
     */
    public function __construct(
        private readonly ?string $procPath = null,
        private readonly ?string $diskPath = null,
    ) {}

    public function snapshot(): ServerSnapshot
    {
        $memory = $this->memory();
        $disk = $this->disk();

        return new ServerSnapshot(
            diskTotal: $disk['total'],
            diskFree: $disk['free'],
            memoryTotal: $memory['MemTotal'] ?? null,
            memoryAvailable: $memory['MemAvailable'] ?? null,
            swapTotal: $memory['SwapTotal'] ?? null,
            swapFree: $memory['SwapFree'] ?? null,
            load: $this->load(),
            cores: $this->cores(),
            diskWarningPercent: (int) config('ops.disk_warning_percent'),
        );
    }

    /**
     * Разобрать текст `/proc/meminfo` в байты по ключам.
     *
     * Принимает ТЕКСТ, а не путь: так разбор проверяется без зависимости
     * от машины.
     *
     * Потребителю нужен `MemAvailable`, а не `MemFree`: свободной памяти
     * на здоровом Linux почти нет — она занята кешем, который ядро отдаст
     * по первому требованию, — и `MemFree` рисовал бы вечную тревогу.
     *
     * @return array<string, float>
     */
    public function parseMeminfo(string $text): array
    {
        $values = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            // `MemTotal:        2014756 kB` — значения в кибибайтах.
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/i', $line, $match) === 1) {
                $values[$match[1]] = (float) $match[2] * 1024;
            }
        }

        return $values;
    }

    /**
     * @return array{total: float|null, free: float|null}
     */
    private function disk(): array
    {
        $path = $this->diskPath ?? base_path();

        try {
            $total = disk_total_space($path);
            $free = disk_free_space($path);
        } catch (Throwable $e) {
            $this->unavailable('disk', $e->getMessage());

            return ['total' => null, 'free' => null];
        }

        if ($total === false || $free === false) {
            $this->unavailable('disk', 'функция вернула false');

            return ['total' => null, 'free' => null];
        }

        return ['total' => $total, 'free' => $free];
    }

    /**
     * @return array<string, float>
     */
    private function memory(): array
    {
        $text = $this->read('meminfo', 'memory');

        return $text === null ? [] : $this->parseMeminfo($text);
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function load(): ?array
    {
        // На Windows функции нет вовсе, а в `disable_functions` она
        // попадает вместе с остальными «опасными» — оба случая штатны.
        if (! function_exists('sys_getloadavg')) {
            $this->unavailable('load', 'sys_getloadavg недоступна');

            return null;
        }

        try {
            $load = sys_getloadavg();
        } catch (Throwable $e) {
            $this->unavailable('load', $e->getMessage());

            return null;
        }

        if (! is_array($load) || count($load) < 3) {
            $this->unavailable('load', 'функция вернула false');

            return null;
        }

        return [round((float) $load[0], 2), round((float) $load[1], 2), round((float) $load[2], 2)];
    }

    private function cores(): ?int
    {
        $text = $this->read('cpuinfo', 'cores');

        if ($text === null) {
            return null;
        }

        $cores = preg_match_all('/^processor\s*:/m', $text);

        return $cores > 0 ? $cores : null;
    }

    /**
     * Прочитать файл из `/proc` — или `null`, если его нет или он закрыт.
     */
    private function read(string $file, string $metric): ?string
    {
        $path = rtrim($this->procPath ?? (string) config('ops.proc_path'), '/\\').'/'.$file;

        try {
            // Под `open_basedir` и `is_readable()`, и чтение дают
            // предупреждение, которое Laravel превращает в исключение, —
            // отсюда try, а не одна проверка существования.
            $text = is_readable($path) ? file_get_contents($path) : false;
        } catch (Throwable $e) {
            $this->unavailable($metric, $e->getMessage());

            return null;
        }

        if ($text === false) {
            $this->unavailable($metric, "файл {$path} не читается");

            return null;
        }

        return $text;
    }

    /**
     * Предупредить о недоступной метрике — только на Linux и не чаще раза
     * в 15 минут на метрику.
     *
     * На Windows `/proc` нет по устройству: локальная машина иначе писала бы
     * предупреждение на каждое открытие панели. А на проде виджет опрашивает
     * сервис раз в минуту с каждой вкладки — без замка одна закрытая
     * `open_basedir` метрика давала бы полторы тысячи строк в сутки.
     */
    private function unavailable(string $metric, string $reason): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return;
        }

        try {
            if (! Redis::connection()->set('ops:warned:metric:'.$metric, '1', 'EX', 900, 'NX')) {
                return;
            }
        } catch (Throwable) {
            // Redis недоступен — предупреждение важнее экономии строк.
        }

        Log::warning('[Ops] метрика сервера недоступна', [
            'metric' => $metric,
            'reason' => $reason,
        ]);
    }
}
