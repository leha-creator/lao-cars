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
        Schema::create('car_photos', function (Blueprint $table): void {
            $table->id();

            // Фото не существуют отдельно от автомобиля — каскад.
            $table->foreignId('car_id')->constrained()->cascadeOnDelete();

            // Диск хранится в строке: перенос медиа на S3 в вехе 7.1
            // не должен требовать миграции данных для уже залитых фото.
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('alt')->nullable();

            // Порядок задаётся перетаскиванием в админке (веха 3.4).
            // Первое по этому полю фото и является главным.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['car_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_photos');
    }
};
