<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('car_photos', function (Blueprint $table): void {
            // Превью. `null` означает одно из двух: обработка не удалась
            // (битый файл сохранён как есть) либо фотография залита до
            // вехи 3.4. Аксессор `thumbUrl` в обоих случаях откатывается
            // на оригинал — сломанной картинки в шаблоне не будет.
            //
            // Путь хранится, а не вычисляется на лету: файл превью может
            // не существовать, и `syncPhotos()` пишет сюда значение только
            // после проверки диска.
            $table->string('thumb_path')->nullable()->after('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('car_photos', function (Blueprint $table): void {
            $table->dropColumn('thumb_path');
        });
    }
};
