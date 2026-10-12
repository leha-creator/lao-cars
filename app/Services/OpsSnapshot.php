<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Состояние служб на момент чтения: монитор, очередь, бэкапы, сертификат,
 * версия выката, пульс планировщика.
 *
 * Как и у `ServerSnapshot`, `null` значит «неизвестно», а не «ноль»:
 * локально и в CI файла состояния нет вовсе, и это штатно.
 */
final readonly class OpsSnapshot
{
    /**
     * @param  CarbonImmutable|null  $generatedAt  когда `healthcheck.sh` записал файл состояния; `null` — файла нет или он нечитаем
     * @param  bool  $statusStale  файл есть, но монитор давно не обновлял его
     * @param  list<string>  $problems  проблемы, найденные монитором
     * @param  string|null  $deploySha  первые семь символов ревизии выката
     */
    public function __construct(
        public ?CarbonImmutable $generatedAt = null,
        public bool $statusStale = false,
        public array $problems = [],
        public ?CarbonImmutable $backupDbAt = null,
        public ?CarbonImmutable $backupMediaAt = null,
        public ?CarbonImmutable $sslNotAfter = null,
        public ?string $deploySha = null,
        public ?CarbonImmutable $deployedAt = null,
        public ?int $queueSize = null,
        public ?int $failedJobs = null,
        public ?CarbonImmutable $flushedAt = null,
        public ?CarbonImmutable $prunedAt = null,
        public bool $heartbeatStale = false,
    ) {}

    /**
     * Пришёл ли файл состояния вообще.
     */
    public function hasStatus(): bool
    {
        return $this->generatedAt !== null;
    }

    public function statusAgeMinutes(): ?int
    {
        return $this->minutesSince($this->generatedAt);
    }

    public function flushAgeMinutes(): ?int
    {
        return $this->minutesSince($this->flushedAt);
    }

    public function backupDbHoursAgo(): ?int
    {
        return $this->backupDbAt === null ? null : (int) floor($this->minutesSince($this->backupDbAt) / 60);
    }

    public function backupMediaHoursAgo(): ?int
    {
        return $this->backupMediaAt === null ? null : (int) floor($this->minutesSince($this->backupMediaAt) / 60);
    }

    /**
     * Полных дней до конца сертификата; отрицательное — уже истёк.
     */
    public function sslDaysLeft(): ?int
    {
        if ($this->sslNotAfter === null) {
            return null;
        }

        return (int) floor((CarbonImmutable::now()->diffInSeconds($this->sslNotAfter, absolute: false)) / 86_400);
    }

    private function minutesSince(?CarbonImmutable $moment): ?int
    {
        if ($moment === null) {
            return null;
        }

        // Часы сервера и приложения могут разойтись на секунды:
        // отрицательный возраст показывать незачем.
        return max(0, (int) floor($moment->diffInSeconds(CarbonImmutable::now(), absolute: false) / 60));
    }
}
