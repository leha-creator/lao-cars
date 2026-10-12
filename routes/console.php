<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Расписание
|--------------------------------------------------------------------------
|
| Первые задачи планировщика в проекте. Запускает их cron `schedule:run`
| раз в минуту; локально на Windows cron нет — `php artisan schedule:work`
| либо команды руками.
|
| Обе команды оставляют отметку пульса в Redis, а блок служб инфопанели
| показывает её возраст: статистика целиком зависит от планировщика,
| и остановившийся cron иначе выглядел бы как «сегодня никто не заходил».
|
*/

// Перенос просмотров из буфера Redis в базу. `withoutOverlapping()` —
// чтобы запуск, упёршийся в медленную базу, не догнал следующий
// и они не разобрали буфер наперегонки.
Schedule::command('analytics:flush')->everyMinute()->withoutOverlapping();

// 03:10 — ДО бэкапа базы в 03:30 (`deploy/backup.sh` в cron): дамп
// не должен возить строки, которые через двадцать минут будут удалены.
Schedule::command('analytics:prune')->dailyAt('03:10');
