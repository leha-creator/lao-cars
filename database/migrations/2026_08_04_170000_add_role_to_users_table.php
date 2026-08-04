<?php

use App\Enums\UserRole;
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
        Schema::table('users', function (Blueprint $table): void {
            // Умолчание — меньшая из ролей, и это осознанно. Пользователь,
            // заведённый мимо формы (сидом, из tinker, будущим импортом,
            // штатной `make:filament-user`), не должен молча получить
            // доступ к настройкам сайта: забытая роль обязана урезать
            // права, а не выдать их.
            //
            // Обратная сторона умолчания — первый деплой, где создавать
            // администратора становится нечем; для этого есть команда
            // `laocars:make-admin`.
            //
            // Индекс — под фильтр по роли в списке пользователей.
            $table->string('role')->default(UserRole::Manager->value)->index()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
