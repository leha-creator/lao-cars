<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Верхняя граница цены у автомобилей и услуг и уточнение к цене
 * у автомобиля.
 *
 * `price_max` — вторая граница диапазона («от 6 500 до 9 000 ₽»), `null`
 * означает одну сумму. Нижней границей остаётся `price`: на неё уже
 * завязаны сортировка каталога, фильтр `price_to`, частичный индекс
 * `cars_available_price_index` и быстрый подбор на главной, и переносить
 * их на новую колонку незачем — «от 3 млн» при бюджете 3,5 млн подходит.
 *
 * `cars.price_note` — то же уточнение, что у услуг с вехи 3.2 («от»,
 * «с НДС»). Отдельной колонки-флага «цена от» нет осознанно: «от» уже
 * задаётся уточнением, и второй способ сказать то же самое разошёлся бы
 * с первым на первой же позиции, где заполнили оба.
 *
 * ОЧИСТКА ОСИРОТЕВШИХ УТОЧНЕНИЙ. До этой правки поле уточнения в форме
 * услуги скрывалось при пустой цене, а скрытое поле Filament на
 * редактировании не сохраняет: очистка цены оставляла уточнение в базе.
 * На сайте его не видно (без цены выводится «по запросу»), но форма теперь
 * показывает поле всегда и требует цену при заполненном уточнении, — и
 * первая же правка такой позиции упёрлась бы в ошибку по полю, которого
 * администратор не трогал. Очистка вынесена в публичный метод, чтобы тест
 * мог проверить её на своих строках: на пустой тестовой базе миграции
 * чистить нечего.
 *
 * Работа идёт через `DB`, а не через модель, — по тому же основанию,
 * что в миграции вехи 4.13: модель к следующей правке будет знать только
 * про новую схему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table): void {
            // Целые рубли, как `price`; null — одна сумма, а не диапазон.
            $table->unsignedBigInteger('price_max')->nullable()->after('price');

            // Уточнение к цене: «от», «с НДС». Правило вывода то же,
            // что у услуг: «от» и «до» перед суммой, остальное — после.
            $table->string('price_note')->nullable()->after('price_max');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_max')->nullable()->after('price');
        });

        $this->clearOrphanNotes();
    }

    public function down(): void
    {
        // Очистку не откатываем: восстанавливать нечего — снятые
        // уточнения на сайт не выводились.
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('price_max');
        });

        Schema::table('cars', function (Blueprint $table): void {
            $table->dropColumn(['price_max', 'price_note']);
        });
    }

    /**
     * Снять уточнения у позиций без цены.
     */
    public function clearOrphanNotes(): int
    {
        $count = DB::table('services')
            ->whereNull('price')
            ->whereNotNull('price_note')
            ->update(['price_note' => null]);

        Log::info('[FIX] сняты уточнения у позиций без цены', ['count' => $count]);

        return $count;
    }
};
