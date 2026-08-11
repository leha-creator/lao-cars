<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Подписки браузеров на push-уведомления (веха 4.7).
 *
 * Миграция опубликована из `laravel-notification-channels/webpush`
 * и дополнена двумя колонками, а не написана заново: модель и канал пакета
 * читают именно эти имена, и вторая таблица рядом с пакетной означала бы,
 * что подписку пишем мы, а доставляет пакет из пустого места.
 *
 * Подписка принадлежит ПОЛЬЗОВАТЕЛЮ, а не браузеру: у одного сотрудника
 * рабочий компьютер, ноутбук и телефон — три строки на одну учётную запись.
 * Ключ — `endpoint`, он же адрес доставки, и он уникален.
 *
 * Связь полиморфная (`subscribable`), а не `user_id`, и это навязано
 * пакетом: трейт `HasPushSubscriptions` строит `morphMany`, а проверка
 * владельца сравнивает `subscribable_type`. Плата — отсутствие внешнего
 * ключа, то есть каскад «удалили сотрудника → ушли его подписки» приходится
 * держать в модели (`User::booted()`), а не в схеме. Второй способ был бы
 * дешевле, но означал бы свой канал доставки вместо пакетного.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('webpush.database_connection'))->create(config('webpush.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');

            // 500 символов — предел пакета и с запасом покрывает адреса
            // FCM и Mozilla. Уникальность обязательна: по этому полю идёт
            // `updateOrCreate`, и без индекса повторная подписка того же
            // браузера плодила бы строки, а каждая заявка тратила бы
            // на них по запросу к чужому API.
            $table->string('endpoint', 500)->unique();

            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();

            // Дополнения к пакетной схеме.
            //
            // `user_agent` — единственное, из чего можно собрать понятную
            // человеку строку в списке устройств кабинета. Без неё список
            // выглядит как три одинаковые строки «Устройство», и отозвать
            // нужную подписку невозможно.
            $table->string('user_agent')->nullable();

            // `last_used_at` — когда на эту подписку последний раз ушло
            // уведомление. Отвечает на вопрос «мне точно приходит?»,
            // на который иначе отвечать нечем: доставка происходит
            // в очереди и следов в интерфейсе не оставляет.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))->dropIfExists(config('webpush.table_name'));
    }
};
