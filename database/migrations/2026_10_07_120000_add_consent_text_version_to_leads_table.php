<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Редакция текста согласия, под которым клиент поставил отметку.
 *
 * Отдельной колонкой рядом с `consent_policy_version`, а не вместо неё:
 * согласие стало самостоятельным документом со своей нумерацией редакций
 * (закон требует оформлять его отдельно от политики), и одна колонка на оба
 * документа хранила бы «1.0», про которую нельзя сказать, чья она.
 *
 * Nullable по той же причине, что соседние колонки: `null` честно означает
 * «согласие по отдельному документу не давалось» — так выглядят все заявки,
 * принятые до этой миграции, и всё, что заведено мимо формы сайта.
 *
 * Длина 16 — то же ограничение стоит на поле редакции в форме настроек.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('consent_text_version', 16)->nullable()->after('consent_policy_version');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('consent_text_version');
        });
    }
};
