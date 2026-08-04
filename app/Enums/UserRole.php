<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasColors;
use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Роль сотрудника в админ-панели (раздел 3.5 ТЗ).
 *
 * Ролей ровно две, и различаются они одной фразой ТЗ: менеджер видит
 * заявки и каталог, но не трогает настройки сайта и контент. Пакет прав
 * (`spatie/laravel-permission` + `filament-shield`) дал бы пять таблиц,
 * кеш разрешений и генератор политик ради того, что помещается в `match`
 * на две ветки.
 *
 * Триггер пересмотра конкретный: третья роль с частичными правами
 * *внутри* раздела — например контент-менеджер, который правит услуги,
 * но не трогает каталог. Переезд совместим: политики остаются на месте,
 * меняется только источник ответа внутри них.
 */
enum UserRole: string implements HasColor, HasLabel
{
    use HasColors;
    use HasLabels;

    case Admin = 'admin';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор',
            self::Manager => 'Менеджер',
        };
    }

    /**
     * Цвет бейджа в админке. Значения — из палитры Filament.
     */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'info',
        };
    }

    /**
     * Полный доступ к панели.
     *
     * Проверка живёт на самом enum-е, а не в политиках: политик девять,
     * и девять копий сравнения с кейсом разъедутся при появлении третьей
     * роли. `User::isAdmin()` делегирует сюда.
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
