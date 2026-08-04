<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabels;
use Filament\Support\Contracts\HasLabel;

/**
 * Порядок сортировки выдачи каталога (веха 3.6).
 *
 * Enum, а не строка из GET-параметра: список допустимых сортировок
 * существует ровно в одном месте — том же, откуда шаблон берёт подписи
 * для селекта, — и пользовательский ввод не попадает в `orderByRaw`
 * ни при каком стечении обстоятельств.
 *
 * Само выражение сортировки живёт в `CatalogFilter`, а не здесь:
 * enum-ы проекта — чистые словари (`label()`, `color()`, `cast()`),
 * и Eloquent-билдер в них не ходит.
 */
enum CatalogSort: string implements HasLabel
{
    use HasLabels;

    /**
     * Кейс называется `Newest`, а не `New`: `new` — ключевое слово PHP,
     * и хотя как имя кейса оно синтаксически проходит, читается это
     * как ошибка.
     */
    case Newest = 'new';

    case PriceAsc = 'price_asc';

    case PriceDesc = 'price_desc';

    public function label(): string
    {
        return match ($this) {
            self::Newest => 'Сначала новые',
            self::PriceAsc => 'Сначала дешёвые',
            self::PriceDesc => 'Сначала дорогие',
        };
    }

    /**
     * Умолчательная сортировка — та, которую не нужно писать в адресе.
     *
     * По этому признаку страница решает, считать ли себя отфильтрованной:
     * `?sort=new` — тот же каталог, что и `/catalog`, и уводить его
     * в `noindex` не за что.
     */
    public function isDefault(): bool
    {
        return $this === self::Newest;
    }
}
