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
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();

            $table->string('author_name');

            // Подпись-контекст из макета: «Клиент, импорт авто».
            $table->string('author_context')->nullable();

            $table->text('body');

            // Рейтинг в звёздах — из макета, в ТЗ он опционален.
            $table->unsignedTinyInteger('rating')->nullable();

            $table->string('photo_path')->nullable();

            // Публикация по умолчанию выключена: раздел 3.4 ТЗ требует
            // модерации, и отзыв, попадающий на сайт до просмотра
            // администратором, — это дефект, а не удобство.
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_published', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
