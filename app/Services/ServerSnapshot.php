<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Состояние сервера на момент чтения — диск, память, нагрузка.
 *
 * ЛЮБОЕ ПОЛЕ МОЖЕТ БЫТЬ `null`: «не удалось узнать». На Windows нет `/proc`,
 * под `open_basedir` его не видит и Linux. Ноль и «неизвестно» обязаны
 * выглядеть по-разному — ноль свободных гигабайт это авария, а не «нет
 * данных», поэтому умолчаний-нулей здесь нет.
 *
 * Размеры — в байтах.
 */
final readonly class ServerSnapshot
{
    /**
     * @param  array{0: float, 1: float, 2: float}|null  $load  средняя нагрузка за 1, 5 и 15 минут
     */
    public function __construct(
        public ?float $diskTotal = null,
        public ?float $diskFree = null,
        public ?float $memoryTotal = null,
        public ?float $memoryAvailable = null,
        public ?float $swapTotal = null,
        public ?float $swapFree = null,
        public ?array $load = null,
        public ?int $cores = null,
        public int $diskWarningPercent = 85,
    ) {}

    /**
     * Сколько процентов диска ЗАНЯТО.
     *
     * От занятого, а не от свободного: тем же числом меряет `df`
     * и порог `DISK_THRESHOLD` в `deploy/healthcheck.sh`.
     */
    public function diskUsedPercent(): ?float
    {
        if ($this->diskTotal === null || $this->diskFree === null || $this->diskTotal <= 0) {
            return null;
        }

        return round(($this->diskTotal - $this->diskFree) / $this->diskTotal * 100, 1);
    }

    /**
     * Перешёл ли диск порог предупреждения.
     *
     * Строго больше — как `-gt` в `healthcheck.sh`: панель и тревога
     * в Telegram обязаны менять мнение на одном и том же проценте.
     */
    public function diskIsLow(): bool
    {
        $used = $this->diskUsedPercent();

        return $used !== null && $used > $this->diskWarningPercent;
    }

    /**
     * Сколько процентов памяти занято — с учётом того, что кеш отдаётся.
     */
    public function memoryUsedPercent(): ?float
    {
        if ($this->memoryTotal === null || $this->memoryAvailable === null || $this->memoryTotal <= 0) {
            return null;
        }

        return round(($this->memoryTotal - $this->memoryAvailable) / $this->memoryTotal * 100, 1);
    }

    /**
     * Нагрузка выше числа ядер — задачи стоят в очереди к процессору.
     *
     * На машине с одним vCPU нагрузка 1.2 уже значит очередь, а на восьми
     * ядрах — простой, поэтому голое число без ядер ничего не говорит.
     */
    public function isOverloaded(): bool
    {
        return $this->load !== null && $this->cores !== null && $this->load[0] > $this->cores;
    }
}
