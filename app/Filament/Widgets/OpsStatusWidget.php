<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AdminOnly;
use App\Services\OpsSnapshot;
use App\Services\OpsStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Блок «Службы»: монитор, очередь, пульс статистики, бэкап, сертификат,
 * версия выката.
 *
 * Панель только ПОКАЗЫВАЕТ. Перезапуска служб и запуска бэкапа отсюда нет
 * и не будет — это root из браузера; тревоги шлёт `healthcheck.sh`.
 *
 * «Нет данных» — серым, а не нулём и не зелёным: неизвестное состояние
 * обязано отличаться и от плохого, и от хорошего. Локально и в CI файла
 * состояния нет вовсе, и блок целиком серый — это штатно.
 */
class OpsStatusWidget extends StatsOverviewWidget
{
    use AdminOnly;

    /**
     * Раз в минуту, а не умолчательные `5s` трейта `CanPoll`: опрос — это
     * запрос Livewire с каждой открытой вкладки администратора, и восемь
     * виджетов на умолчании сами стали бы главной нагрузкой, которую
     * показывают. Чаще минуты незачем: файл состояния обновляется раз
     * в пять минут, пульс статистики — раз в минуту.
     */
    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Службы';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $ops = app(OpsStatus::class)->snapshot();

        return [
            $this->monitoring($ops),
            $this->queue($ops),
            $this->statistics($ops),
            $this->backup($ops),
            $this->certificate($ops),
            $this->version($ops),
        ];
    }

    private function monitoring(OpsSnapshot $ops): Stat
    {
        if (! $ops->hasStatus()) {
            return Stat::make('Мониторинг', 'Нет данных')
                ->description('Файл состояния не найден')
                ->color('gray');
        }

        // Устаревание важнее содержимого: остановившийся монитор иначе
        // выглядел бы как вечное «всё в порядке».
        if ($ops->statusStale) {
            return Stat::make('Мониторинг', 'Не обновлялся '.$ops->statusAgeMinutes().' мин')
                ->description('Проверки на сервере остановились')
                ->color('danger');
        }

        if ($ops->problems !== []) {
            return Stat::make('Мониторинг', 'Проблем: '.count($ops->problems))
                ->description($ops->problems[0])
                ->color('danger');
        }

        return Stat::make('Мониторинг', 'Всё в порядке')
            ->description('Проверено '.$this->minutesAgo($ops->statusAgeMinutes()))
            ->color('success');
    }

    private function queue(OpsSnapshot $ops): Stat
    {
        $failed = (int) $ops->failedJobs;

        return Stat::make('Очередь', 'Ждут: '.(int) $ops->queueSize)
            ->description($failed > 0 ? 'Упало задач: '.$failed : 'Упавших задач нет')
            ->color($failed > 0 ? 'danger' : 'success');
    }

    private function statistics(OpsSnapshot $ops): Stat
    {
        $age = $ops->flushAgeMinutes();

        if ($age === null) {
            return Stat::make('Статистика', 'Не обновлялась')
                ->description('Планировщик не запускался')
                ->color('danger');
        }

        return Stat::make('Статистика', 'Обновлена '.$this->minutesAgo($age))
            ->description($ops->heartbeatStale ? 'Планировщик остановился' : 'Обновляется раз в минуту')
            ->color($ops->heartbeatStale ? 'danger' : 'success');
    }

    private function backup(OpsSnapshot $ops): Stat
    {
        $hours = $ops->backupDbHoursAgo();

        if ($hours === null) {
            return Stat::make('Бэкап базы', 'Нет данных')->color('gray');
        }

        $media = $ops->backupMediaHoursAgo();

        // 26 часов: бэкап ежедневный, два часа — запас на сам дамп.
        return Stat::make('Бэкап базы', $this->hoursAgo($hours))
            ->description($media === null ? 'Копии медиа нет' : 'Медиа: '.$this->hoursAgo($media))
            ->color($hours > 26 ? 'warning' : 'success');
    }

    private function certificate(OpsSnapshot $ops): Stat
    {
        $days = $ops->sslDaysLeft();

        if ($days === null) {
            return Stat::make('Сертификат', 'Нет данных')->color('gray');
        }

        // 14 дней: certbot продлевает за 30 до конца, и две недели
        // до срока значат, что продление не сработало уже дважды.
        return Stat::make('Сертификат', $days < 0 ? 'Истёк' : 'Осталось '.$days.' дн.')
            ->description('Действует до '.$ops->sslNotAfter->format('d.m.Y'))
            ->color($days < 14 ? 'danger' : 'success');
    }

    private function version(OpsSnapshot $ops): Stat
    {
        if ($ops->deploySha === null) {
            return Stat::make('Версия', 'Нет данных')->color('gray');
        }

        return Stat::make('Версия', $ops->deploySha)
            ->description($ops->deployedAt === null ? 'Дата выката неизвестна' : 'Выкат '.$ops->deployedAt->format('d.m.Y H:i'))
            ->color('gray');
    }

    private function minutesAgo(?int $minutes): string
    {
        return $minutes === null || $minutes < 1 ? 'только что' : $minutes.' мин назад';
    }

    private function hoursAgo(int $hours): string
    {
        return $hours < 1 ? 'Меньше часа назад' : $hours.' ч назад';
    }
}
