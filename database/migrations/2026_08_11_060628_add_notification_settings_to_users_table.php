<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Настройки уведомлений сотрудника (веха 4.7).
 *
 * Колонки, а не jsonb и не ключи `site_settings`, — и это осознанно
 * противоположно решению вехи 3.5 про настройки сайта.
 *
 * Разница в том, ЧТО делают со значением. Настройки сайта рендерятся:
 * состав задаёт редактор, а читает их шаблон целиком, поэтому jsonb там
 * уместен. По этим же значениям идёт ВЫБОРКА получателей
 * (`where('notify_telegram', true)->whereNotNull('telegram_chat_id')`):
 * условие по ключу jsonb читается хуже и индексируется хуже, а набор
 * настроек здесь фиксирован и меняется миграцией, а не администратором.
 *
 * Умолчания обоих флагов — `true`. Сотрудник, заведённый до этой вехи,
 * не должен молча остаться без уведомлений, а фактическую доставку всё
 * равно ограничивают заполненный `chat_id` и наличие подписки браузера:
 * включённый флаг без адреса никого не разбудит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 64 символа с запасом: id чата Telegram — целое число,
            // у групп отрицательное, а у супергрупп длинное. Строка,
            // а не `bigint`: ведущий минус и возможные будущие форматы
            // адресации в числовую колонку не лягут.
            $table->string('telegram_chat_id', 64)->nullable()->after('role');

            $table->boolean('notify_telegram')->default(true)->after('telegram_chat_id');
            $table->boolean('notify_push')->default(true)->after('notify_telegram');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'notify_telegram', 'notify_push']);
        });
    }
};
