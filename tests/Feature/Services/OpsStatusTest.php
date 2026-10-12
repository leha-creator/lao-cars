<?php

use App\Services\OpsStatus;
use App\Services\SchedulerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * Состояние служб: файл от healthcheck.sh, отметка выката, очередь, пульс.
 */

beforeEach(function () {
    resetAnalytics();

    // Через две минуты после записи файла из фикстуры.
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:37:00'));

    config([
        'ops.status_file' => base_path('tests/Fixtures/ops/status.txt'),
        'ops.deploy_file' => base_path('tests/Fixtures/ops/deploy.json'),
    ]);

    $this->ops = app(OpsStatus::class);

    // Временный файл для тестов, которым нужно своё содержимое.
    $this->scratch = tempnam(sys_get_temp_dir(), 'ops');
});

afterEach(function () {
    Carbon::setTestNow();

    @unlink($this->scratch);
});

it('reads the status file written by the health check', function () {
    $snapshot = $this->ops->snapshot();

    expect($snapshot->hasStatus())->toBeTrue()
        ->and($snapshot->statusStale)->toBeFalse()
        ->and($snapshot->statusAgeMinutes())->toBe(1)
        ->and($snapshot->problems)->toBe([
            'воркер laocars-queue не запущен',
            'контейнер laocars-redis: unhealthy',
        ])
        ->and($snapshot->backupDbAt->format('Y-m-d H:i'))->toBe('2026-10-07 03:30')
        ->and($snapshot->backupDbHoursAgo())->toBe(9)
        ->and($snapshot->backupMediaHoursAgo())->toBe(56)
        ->and($snapshot->sslNotAfter->format('Y-m-d'))->toBe('2026-11-12')
        ->and($snapshot->sslDaysLeft())->toBe(35);
});

it('flags a status file the monitor has stopped updating', function () {
    Log::spy();

    // Шестнадцать минут — порог пятнадцать.
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:51:30'));

    $snapshot = $this->ops->snapshot();

    // Остановившийся cron иначе выглядел бы как вечное «всё в порядке».
    expect($snapshot->statusStale)->toBeTrue()
        ->and($snapshot->statusAgeMinutes())->toBe(16)
        // Данные при этом показываются: они устарели, а не пропали.
        ->and($snapshot->problems)->toHaveCount(2);

    // Виджет опрашивает сервис раз в минуту — предупреждение одно.
    $this->ops->snapshot();
    $this->ops->snapshot();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('[Ops] файл состояния не обновлялся', ['age_minutes' => 16]);
});

it('does not call a fresh file stale at the threshold itself', function () {
    Log::spy();
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:50:01'));

    expect($this->ops->snapshot()->statusStale)->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

it('reports no data when the status file is absent', function () {
    config([
        'ops.status_file' => base_path('tests/Fixtures/ops/no-such-file.txt'),
        'ops.deploy_file' => base_path('tests/Fixtures/ops/no-such-file.json'),
    ]);
    Log::spy();

    $snapshot = $this->ops->snapshot();

    // Локально и в CI файла нет — это штатно, без предупреждений.
    expect($snapshot->hasStatus())->toBeFalse()
        ->and($snapshot->statusStale)->toBeFalse()
        ->and($snapshot->problems)->toBe([])
        ->and($snapshot->backupDbHoursAgo())->toBeNull()
        ->and($snapshot->sslDaysLeft())->toBeNull()
        ->and($snapshot->deploySha)->toBeNull()
        ->and($snapshot->deployedAt)->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('skips the lines it cannot read instead of failing', function () {
    file_put_contents($this->scratch, implode("\n", [
        'мусор без знака равенства',
        '=значение без ключа',
        'generated_at=2026-10-07T12:35:01+03:00',
        'problem=диск заполнен: used=91%',
        'problem=',
        'backup_db_at=не дата',
        'ssl_not_after=2026-11-12T08:14',
        "\x00\x01\x02",
        'UPPER=игнорируется',
    ]));
    config(['ops.status_file' => $this->scratch]);

    $snapshot = $this->ops->snapshot();

    expect($snapshot->hasStatus())->toBeTrue()
        // Значение режется по первому `=`: в тексте проблемы он встречается.
        ->and($snapshot->problems)->toBe(['диск заполнен: used=91%'])
        ->and($snapshot->backupDbAt)->toBeNull()
        ->and($snapshot->sslDaysLeft())->toBe(35);
});

it('treats a file without a readable timestamp as no data', function () {
    file_put_contents($this->scratch, "problem=воркер не запущен\nbackup_db_at=2026-10-07T03:30:12+03:00\n");
    config(['ops.status_file' => $this->scratch]);

    $snapshot = $this->ops->snapshot();

    // Проблемы неизвестной давности хуже, чем «нет данных».
    expect($snapshot->hasStatus())->toBeFalse()
        ->and($snapshot->problems)->toBe([])
        ->and($snapshot->backupDbAt)->toBeNull();
});

it('survives a file cut off in the middle of a line', function () {
    file_put_contents($this->scratch, "generated_at=2026-10-07T12:35:01+03:00\nproblem=воркер laocars-qu");
    config(['ops.status_file' => $this->scratch]);

    expect($this->ops->snapshot()->problems)->toBe(['воркер laocars-qu']);
});

it('shows the deployed revision cut to seven characters', function () {
    $snapshot = $this->ops->snapshot();

    expect($snapshot->deploySha)->toBe('25c631e')
        ->and($snapshot->deployedAt->format('Y-m-d H:i'))->toBe('2026-10-07 11:02');
});

it('ignores a deploy stamp that is not a revision', function (string $content) {
    file_put_contents($this->scratch, $content);
    config(['ops.deploy_file' => $this->scratch]);

    expect($this->ops->snapshot()->deploySha)->toBeNull();
})->with([
    'не json' => ['выкат состоялся'],
    'нет ревизии' => ['{"deployed_at":"2026-10-07T11:02:44+03:00"}'],
    // Значение уходит в разметку панели — пропускается только хеш.
    'разметка вместо ревизии' => ['{"sha":"<script>alert(1)</script>"}'],
    'пустой файл' => [''],
]);

it('counts the queue on the real redis queue', function () {
    // Без Queue::fake(): с подделкой размер очереди ничего не значит.
    $before = Queue::size();

    dispatch(function (): void {});

    expect($this->ops->snapshot()->queueSize)->toBe($before + 1);

    Queue::pop()?->delete();

    expect($this->ops->snapshot()->queueSize)->toBe($before);
});

it('counts the failed jobs', function () {
    expect($this->ops->snapshot()->failedJobs)->toBe(0);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'RuntimeException: Telegram недоступен',
        'failed_at' => now(),
    ]);

    expect($this->ops->snapshot()->failedJobs)->toBe(1);
});

it('tells a living scheduler from a stopped one by the heartbeat', function () {
    // Отметки нет вовсе — планировщик ни разу не отработал.
    expect($this->ops->snapshot()->heartbeatStale)->toBeTrue()
        ->and($this->ops->snapshot()->flushAgeMinutes())->toBeNull();

    app(SchedulerHeartbeat::class)->beat(SchedulerHeartbeat::FLUSH, 86_400);

    Carbon::setTestNow(Carbon::parse('2026-10-07 12:47:00'));

    expect($this->ops->snapshot()->heartbeatStale)->toBeFalse()
        ->and($this->ops->snapshot()->flushAgeMinutes())->toBe(10);

    // Больше десяти минут тишины при запуске раз в минуту — стоит.
    Carbon::setTestNow(Carbon::parse('2026-10-07 12:48:30'));

    expect($this->ops->snapshot()->heartbeatStale)->toBeTrue()
        ->and($this->ops->snapshot()->flushAgeMinutes())->toBe(11);
});
