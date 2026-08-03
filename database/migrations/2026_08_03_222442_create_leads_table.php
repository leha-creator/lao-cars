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
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->text('message')->nullable();

            // Поля из макета: форма шире описанной в разделе 3 ТЗ.
            $table->string('contact_method')->nullable();
            $table->string('preferred_time')->nullable();

            // Поля подбора запчасти — форма на посадочной «Запчасти».
            // Марка и модель здесь строки, а не ссылка на brands:
            // клиент подбирает деталь к своему автомобилю, которого
            // в каталоге нет и быть не должно.
            $table->string('part_brand')->nullable();
            $table->string('part_model')->nullable();
            $table->string('part_vin', 17)->nullable();

            // Источник: Car, Service или null (общая форма обратной связи).
            $table->nullableMorphs('source');

            $table->string('status')->default('new');
            $table->string('page_url')->nullable();

            $table->timestamps();

            // Менеджер работает со списком «новые сверху» — выборка
            // всегда идёт по статусу с сортировкой по дате.
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
