<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Services\ServerSnapshot;
use App\Services\ServerStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Блок «Сервер»: диск, память, нагрузка — текущие значения.
 *
 * Графиков во времени нет намеренно: для них нужен постоянный сборщик
 * метрик, а это отдельная система, а не страница панели.
 *
 * «Нет данных» — серым, а не нулём: ноль свободных гигабайт и неизвестное
 * значение обязаны выглядеть по-разному. На Windows `/proc` нет, и память
 * с нагрузкой локально серые всегда.
 */
class ServerStatsWidget extends StatsOverviewWidget
{
    use AdminOnly;

    /**
     * Раз в минуту, а не умолчательные `5s`: каждый опрос — запрос Livewire
     * с каждой открытой вкладки. Диск и память за минуту не меняются так,
     * чтобы это стоило двенадцати запросов.
     */
    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Сервер';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $server = app(ServerStatus::class)->snapshot();

        return [
            $this->disk($server),
            $this->memory($server),
            $this->load($server),
        ];
    }

    private function disk(ServerSnapshot $server): Stat
    {
        $used = $server->diskUsedPercent();

        if ($used === null) {
            return Stat::make('Диск', 'Нет данных')->color('gray');
        }

        return Stat::make('Диск', 'Свободно '.$this->gigabytes($server->diskFree))
            ->description('из '.$this->gigabytes($server->diskTotal).' · занято '.$this->percent($used))
            ->color($server->diskIsLow() ? 'danger' : 'success');
    }

    private function memory(ServerSnapshot $server): Stat
    {
        $used = $server->memoryUsedPercent();

        if ($used === null) {
            return Stat::make('Память', 'Нет данных')->color('gray');
        }

        $swap = $server->swapTotal === null || $server->swapFree === null || $server->swapTotal <= 0
            ? 'swap не подключён'
            : 'swap: свободно '.$this->gigabytes($server->swapFree).' из '.$this->gigabytes($server->swapTotal);

        // 90%: доступная память уже учитывает кеш, который ядро отдаст,
        // так что это настоящая нехватка, а не обычное состояние Linux.
        return Stat::make('Память', 'Доступно '.$this->gigabytes($server->memoryAvailable))
            ->description('из '.$this->gigabytes($server->memoryTotal).' · '.$swap)
            ->color($used > 90 ? 'warning' : 'success');
    }

    private function load(ServerSnapshot $server): Stat
    {
        if ($server->load === null) {
            return Stat::make('Нагрузка', 'Нет данных')->color('gray');
        }

        $values = implode(' / ', array_map(
            static fn (float $value): string => number_format($value, 2, ',', ' '),
            $server->load,
        ));

        return Stat::make('Нагрузка', $values)
            ->description('за 1, 5 и 15 минут · ядер: '.($server->cores ?? 'неизвестно'))
            ->color($server->isOverloaded() ? 'warning' : 'success');
    }

    private function gigabytes(?float $bytes): string
    {
        return number_format((float) $bytes / 1024 ** 3, 1, ',', ' ').' ГБ';
    }

    private function percent(float $value): string
    {
        return number_format($value, 0, ',', ' ').'%';
    }
}
