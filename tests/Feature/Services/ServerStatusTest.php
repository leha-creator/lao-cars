<?php

use App\Services\ServerSnapshot;
use App\Services\ServerStatus;
use Illuminate\Support\Facades\Log;

/*
 * Метрики сервера: разбор `/proc` на фикстурах — тест обязан проходить
 * и на Windows, и в CI одинаково.
 */

beforeEach(function () {
    resetAnalytics();

    $this->fixtures = base_path('tests/Fixtures/proc');
});

it('parses meminfo into bytes', function () {
    $memory = (new ServerStatus)->parseMeminfo(file_get_contents($this->fixtures.'/meminfo'));

    expect($memory['MemTotal'])->toBe(2014756.0 * 1024)
        ->and($memory['MemAvailable'])->toBe(1203448.0 * 1024)
        ->and($memory['SwapTotal'])->toBe(1048572.0 * 1024)
        ->and($memory['SwapFree'])->toBe(996348.0 * 1024)
        // Строки без единицы (счётчики страниц) — не память в байтах.
        ->and($memory)->not->toHaveKey('HugePages_Total');
});

it('reports available memory, not free memory', function () {
    $snapshot = (new ServerStatus($this->fixtures))->snapshot();

    // `MemFree` на здоровом Linux почти ноль — память занята кешем,
    // который ядро отдаст по первому требованию. В фикстуре свободно
    // 85 МБ из 2 ГБ, а доступно 1,2 ГБ: по `MemFree` это была бы тревога.
    expect($snapshot->memoryTotal)->toBe(2014756.0 * 1024)
        ->and($snapshot->memoryAvailable)->toBe(1203448.0 * 1024)
        ->and($snapshot->memoryAvailable)->not->toBe(85320.0 * 1024)
        ->and($snapshot->memoryUsedPercent())->toBe(40.3)
        ->and($snapshot->swapTotal)->toBe(1048572.0 * 1024)
        ->and($snapshot->swapFree)->toBe(996348.0 * 1024);
});

it('counts the processor cores', function () {
    expect((new ServerStatus($this->fixtures))->snapshot()->cores)->toBe(2);
});

it('measures the disk the project lives on', function () {
    $snapshot = (new ServerStatus($this->fixtures))->snapshot();

    expect($snapshot->diskTotal)->toBeGreaterThan(0)
        ->and($snapshot->diskFree)->toBeGreaterThan(0)->toBeLessThanOrEqual($snapshot->diskTotal)
        ->and($snapshot->diskUsedPercent())->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

it('returns nulls instead of failing when proc is not there', function () {
    Log::spy();

    $snapshot = (new ServerStatus(base_path('tests/Fixtures/no-such-proc')))->snapshot();

    // «Неизвестно», а не ноль: ноль доступной памяти — это авария.
    expect($snapshot->memoryTotal)->toBeNull()
        ->and($snapshot->memoryAvailable)->toBeNull()
        ->and($snapshot->swapTotal)->toBeNull()
        ->and($snapshot->cores)->toBeNull()
        ->and($snapshot->memoryUsedPercent())->toBeNull()
        ->and($snapshot->isOverloaded())->toBeFalse();

    if (PHP_OS_FAMILY === 'Linux') {
        // По одному предупреждению на метрику — память и ядра.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === '[Ops] метрика сервера недоступна'
                && in_array($context['metric'], ['memory', 'cores'], true))
            ->twice();
    } else {
        // На Windows `/proc` нет по устройству: предупреждение на каждое
        // открытие панели было бы шумом.
        Log::shouldNotHaveReceived('warning');
    }
});

it('does not repeat the warning on every poll', function () {
    Log::spy();

    $status = new ServerStatus(base_path('tests/Fixtures/no-such-proc'));
    $status->snapshot();
    $status->snapshot();
    $status->snapshot();

    // Виджет опрашивает сервис раз в минуту с каждой вкладки.
    Log::shouldHaveReceived('warning')->twice();
})->skip(PHP_OS_FAMILY !== 'Linux', 'Предупреждение о недоступной метрике пишется только на Linux.');

it('computes the disk percentage from the used space', function () {
    $snapshot = new ServerSnapshot(diskTotal: 20 * 1024 ** 3, diskFree: 5 * 1024 ** 3);

    // Занято 15 из 20 — 75%, а не 25%: тем же числом меряет `df`.
    expect($snapshot->diskUsedPercent())->toBe(75.0);
});

it('takes the disk warning threshold from the config', function () {
    $status = new ServerStatus($this->fixtures);

    config(['ops.disk_warning_percent' => 0]);
    $tight = $status->snapshot();

    config(['ops.disk_warning_percent' => 100]);
    $loose = $status->snapshot();

    expect($tight->diskWarningPercent)->toBe(0)
        ->and($loose->diskWarningPercent)->toBe(100)
        ->and($loose->diskIsLow())->toBeFalse();
});

it('warns about the disk strictly above the threshold', function () {
    $gb = 1024 ** 3;

    // Строго больше — как `-gt` в healthcheck.sh: панель и тревога
    // в Telegram меняют мнение на одном проценте.
    expect((new ServerSnapshot(diskTotal: 100 * $gb, diskFree: 15 * $gb, diskWarningPercent: 85))->diskIsLow())->toBeFalse()
        ->and((new ServerSnapshot(diskTotal: 100 * $gb, diskFree: 14 * $gb, diskWarningPercent: 85))->diskIsLow())->toBeTrue()
        // Неизвестный диск — не «заполнен».
        ->and((new ServerSnapshot(diskWarningPercent: 85))->diskIsLow())->toBeFalse();
});

it('reads the load against the number of cores', function () {
    expect((new ServerSnapshot(load: [1.2, 0.8, 0.5], cores: 1))->isOverloaded())->toBeTrue()
        // Та же нагрузка на двух ядрах — уже не очередь.
        ->and((new ServerSnapshot(load: [1.2, 0.8, 0.5], cores: 2))->isOverloaded())->toBeFalse()
        ->and((new ServerSnapshot(load: [1.2, 0.8, 0.5]))->isOverloaded())->toBeFalse();
});

it('keeps the same threshold the health check script alerts on', function () {
    // Одно знание в двух местах: порог панели и порог тревоги в Telegram.
    preg_match('/^DISK_THRESHOLD=(\d+)/m', file_get_contents(base_path('deploy/healthcheck.sh')), $match);

    expect((int) $match[1])->toBe(config('ops.disk_warning_percent'));
});
