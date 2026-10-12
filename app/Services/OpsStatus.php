<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Состояние служб для инфопанели администратора.
 *
 * PHP НИЧЕГО НЕ ИСПОЛНЯЕТ. Бэкапы лежат в каталоге с режимом 0700 от root,
 * сертификат — в `/etc/letsencrypt`, `systemctl` и `docker` пулу php-fpm
 * недоступны намеренно. Всё это приносит файлом `deploy/healthcheck.sh`
 * (cron от root, раз в пять минут), а сервис файл только читает.
 *
 * Формат файла — строки `ключ=значение`, строка `problem=` повторяется:
 *
 *     generated_at=2026-10-07T12:35:01+03:00
 *     problem=воркер laocars-queue не запущен
 *     backup_db_at=2026-10-07T03:30:12+03:00
 *     backup_media_at=2026-10-05T04:30:40+03:00
 *     ssl_not_after=2026-11-12T08:14:00+03:00
 *
 * Панель только показывает: перезапуска служб и запуска бэкапа из веба
 * нет и не будет — это root из браузера. Тревоги шлёт сам `healthcheck.sh`.
 */
final class OpsStatus
{
    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    public function snapshot(): OpsSnapshot
    {
        $status = $this->readStatusFile();
        $deploy = $this->readDeployFile();

        $generatedAt = $this->date($status['generated_at'] ?? null);
        $stale = $generatedAt !== null
            && $generatedAt->diffInMinutes(CarbonImmutable::now(), absolute: false) > (int) config('ops.status_max_age_minutes');

        if ($stale) {
            $this->warnStale($generatedAt);
        }

        $flushedAt = $this->heartbeat->lastBeat(SchedulerHeartbeat::FLUSH);

        return new OpsSnapshot(
            generatedAt: $generatedAt,
            statusStale: $stale,
            // Без распознанного `generated_at` файл считается нечитаемым
            // целиком: проблемы неизвестной давности хуже, чем «нет данных».
            problems: $generatedAt === null ? [] : $status['problems'],
            backupDbAt: $generatedAt === null ? null : $this->date($status['backup_db_at'] ?? null),
            backupMediaAt: $generatedAt === null ? null : $this->date($status['backup_media_at'] ?? null),
            sslNotAfter: $generatedAt === null ? null : $this->date($status['ssl_not_after'] ?? null),
            deploySha: $deploy['sha'],
            deployedAt: $deploy['deployed_at'],
            queueSize: Queue::size(),
            failedJobs: DB::table('failed_jobs')->count(),
            flushedAt: $flushedAt,
            prunedAt: $this->heartbeat->lastBeat(SchedulerHeartbeat::PRUNE),
            // Отметки нет вовсе — тоже «статистика стоит»: команда идёт
            // раз в минуту, и за сутки жизни ключа обязана была отработать.
            heartbeatStale: $flushedAt === null
                || $flushedAt->diffInMinutes(CarbonImmutable::now(), absolute: false) > (int) config('ops.heartbeat_max_age_minutes'),
        );
    }

    /**
     * Разобрать текст файла состояния.
     *
     * Битый файл не роняет панель: строки без `=`, с незнакомым видом ключа
     * и пустые пропускаются. Значение режется по ПЕРВОМУ `=` — в тексте
     * проблемы знак равенства встречается.
     *
     * @return array{problems: list<string>}&array<string, mixed>
     */
    public function parse(string $text): array
    {
        $values = ['problems' => []];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/u', trim($line), $match) !== 1) {
                continue;
            }

            [, $key, $value] = $match;
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            if ($key === 'problem') {
                $values['problems'][] = mb_substr($value, 0, 500);
            } elseif ($key !== 'problems') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array{problems: list<string>}&array<string, mixed>
     */
    private function readStatusFile(): array
    {
        $text = $this->read((string) config('ops.status_file'));

        return $text === null ? ['problems' => []] : $this->parse($text);
    }

    /**
     * @return array{sha: string|null, deployed_at: CarbonImmutable|null}
     */
    private function readDeployFile(): array
    {
        $text = $this->read((string) config('ops.deploy_file'));
        $data = $text === null ? null : json_decode($text, associative: true);

        if (! is_array($data) || ! is_string($data['sha'] ?? null) || preg_match('/^[0-9a-f]{7,40}$/i', $data['sha']) !== 1) {
            return ['sha' => null, 'deployed_at' => null];
        }

        return [
            // Семи символов хватает, чтобы найти ревизию, и не хватает,
            // чтобы занять карточку целиком.
            'sha' => mb_strtolower(substr($data['sha'], 0, 7)),
            'deployed_at' => $this->date($data['deployed_at'] ?? null),
        ];
    }

    /**
     * Файла нет — `null`, и это штатно: локально и в CI его не бывает.
     */
    private function read(string $path): ?string
    {
        try {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $text = file_get_contents($path);
        } catch (Throwable) {
            // `open_basedir` превращает проверку существования в исключение.
            // Для панели это то же «нет данных».
            return null;
        }

        return $text === false ? null : $text;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Предупредить об остановившемся мониторе — не чаще раза в 15 минут.
     *
     * Виджет опрашивает сервис раз в минуту с каждой открытой вкладки:
     * без замка он сам писал бы это предупреждение полторы тысячи раз
     * в сутки.
     */
    private function warnStale(CarbonImmutable $generatedAt): void
    {
        if (! Redis::connection()->set('ops:warned:status-stale', '1', 'EX', 900, 'NX')) {
            return;
        }

        Log::warning('[Ops] файл состояния не обновлялся', [
            'age_minutes' => (int) $generatedAt->diffInMinutes(CarbonImmutable::now(), absolute: false),
        ]);
    }
}
