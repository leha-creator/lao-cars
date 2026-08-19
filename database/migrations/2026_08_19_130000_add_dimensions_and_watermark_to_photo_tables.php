<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Размеры файла и отметка о вотермарке в обеих таблицах фотографий —
 * веха 4.14.
 *
 * ОДНА МИГРАЦИЯ НА ДВЕ ТАБЛИЦЫ, а не две по одной: колонки одинаковые,
 * заводятся ради одного и того же и заполняются одной командой
 * (`images:restamp`). Разнести их значило бы получить состояние схемы,
 * в котором половина фотографий умеет то, чего не умеет вторая.
 *
 * ЗАЧЕМ РАЗМЕРЫ. Не украшение к «фото не обрезается», а то, без чего
 * лайтбокс врёт: «открыть в полном размере» обязано знать, есть ли
 * у файла размер, которого не видно в карточке. Иначе кнопка зума
 * предлагается и для снимка 800px, который крупнее не станет, —
 * а предложение приблизить без эффекта читается как поломка.
 * Побочно закрывается давняя дыра: `width`/`height` у тега `img`,
 * отсутствие которых прямо названо в комментарии `catalog/show.blade.php`.
 *
 * ЗАЧЕМ ОТМЕТКА. Повторный штамп предотвращается записью в БД, а не
 * догадкой по картинке: распознать «логотип уже вжжён» по пикселям
 * нельзя надёжно, а команда перепрохода без отметки при втором запуске
 * поставит второй штамп поверх первого — и вернуть файл будет неоткуда.
 *
 * NULLABLE ВСЕ ТРИ. У уже загруженных файлов этих данных нет, и заполнит
 * их команда `images:restamp`, а не миграция: читать с диска тысячу
 * файлов внутри миграции значит получить деплой, который висит
 * и не объясняет почему.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = ['car_photos', 'media'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // ПОТОЛОК unsignedSmallInteger — 65535. Для пикселей запас
                // громадный: `images.max_width` равен 1920, и оригинал
                // шире потолка физически не сохраняется. Но если кто-то
                // поднимет IMAGES_MAX_WIDTH выше 65535, запись переполнится
                // и упадёт на вставке — что здесь и нужно: молча обрезанный
                // размер уехал бы в тег `img` и перекосил вёрстку.
                $blueprint->unsignedSmallInteger('width')->nullable();
                $blueprint->unsignedSmallInteger('height')->nullable();

                // Отметка времени, а не булево: «когда штамповали» отвечает
                // и на вопрос «штамповали ли», и на вопрос «до или после
                // того, как поменяли логотип». Булево отвечает только
                // на первый.
                $blueprint->timestamp('watermarked_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['width', 'height', 'watermarked_at']);
            });
        }
    }
};
