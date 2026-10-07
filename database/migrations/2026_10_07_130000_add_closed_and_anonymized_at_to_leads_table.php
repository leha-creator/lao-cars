<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дата закрытия заявки и отметка об обезличивании.
 *
 * Обе колонки нужны сроку хранения: закрытая заявка хранится три года
 * с даты закрытия и затем обезличивается (раздел 9 политики обработки
 * персональных данных). До этой миграции момент закрытия был только
 * в `leads.log`, а отличить обезличенную заявку от обычной было нечем.
 *
 * **Существующим закрытым заявкам `closed_at` проставляется из `updated_at`.**
 * Точнее даты у них нет: статус — последнее, что меняется у заявки, и время
 * последнего изменения строки — лучшее из доступных приближений. Оставить
 * `null` значило бы, что заявки, закрытые до миграции, не попадут в отбор
 * «срок хранения истёк» никогда, то есть хранились бы бессрочно — ровно то,
 * от чего миграция уводит.
 *
 * Чистый SQL без классов приложения и без енама статуса: миграция обязана
 * исполняться на схеме своего дня и пережить переименование любого класса
 * (правило из докблока `replace_work_hours_with_schedule_setting`).
 *
 * Индекс по `closed_at` не заводится: отбор выполняется раз в год руками,
 * а таблица заявок измеряется тысячами строк.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->timestamp('anonymized_at')->nullable()->after('closed_at');
        });

        DB::table('leads')
            ->where('status', 'closed')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['closed_at', 'anonymized_at']);
        });
    }
};
