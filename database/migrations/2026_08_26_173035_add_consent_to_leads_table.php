<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Согласие на обработку персональных данных, зафиксированное вместе
 * с заявкой (веха 4.17).
 *
 * **Обе колонки nullable, и это не мягкость.** `null` здесь означает ровно
 * одно и означает честно: «согласие через форму сайта не давалось». Такие
 * заявки существуют — все, заведённые до этой вехи, — и появятся снова:
 * `LeadService::capture()` вызывается ещё из теста, консольной команды
 * и будущего импорта. `NOT NULL` с умолчанием `now()` проставил бы старым
 * строкам согласие, которого никто не давал, то есть подделал бы
 * доказательство, ради которого колонка и заводится.
 *
 * **`consented_at` отдельно от `created_at`**, хотя для веб-заявки значения
 * совпадут до секунды. Совпадение — факт, а не правило: `created_at`
 * отвечает на вопрос «когда появилась строка», `consented_at` — «когда
 * человек согласился», и `null` в одном при заполненном другом различает
 * эти два случая. Считать согласие по `created_at` значит утверждать, что
 * каждая строка в таблице получена с согласием.
 *
 * Длина `consent_policy_version` — 16 символов, и ровно столько же стоит
 * ограничением на поле редакции в форме настроек. Правило проекта: значение
 * не должно приходить в колонку длиннее её, иначе вместо сообщения
 * администратору получится ошибка драйвера на приёме заявки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('consented_at')->nullable()->after('page_url');
            $table->string('consent_policy_version', 16)->nullable()->after('consented_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn(['consented_at', 'consent_policy_version']);
        });
    }
};
