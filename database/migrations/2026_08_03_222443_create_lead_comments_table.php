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
        // Отдельная таблица, а не текстовое поле в leads: раздел 4 ТЗ
        // требует «возможность оставлять комментарии» во множественном
        // числе, а одно поле теряет и историю, и авторство.
        Schema::create('lead_comments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: удаление сотрудника не должно уносить
            // историю работы с заявками.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->text('body');

            $table->timestamps();

            // Лента комментариев карточки заявки — выборка по лиду
            // в хронологическом порядке. PostgreSQL индекс под внешний
            // ключ сам не создаёт.
            $table->index(['lead_id', 'created_at']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_comments');
    }
};
