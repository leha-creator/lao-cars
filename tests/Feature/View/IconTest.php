<?php

use App\Enums\ServiceCategory;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

/*
 * Набор иконок `x-ui.icon`.
 *
 * Тест сторожит ровно одно — связь между именем и рисунком. Она рвётся тихо
 * с обеих сторон: имя, которого нет в наборе, роняет страницу целиком,
 * а компонент, названный `x-icon`, уезжает в чужой компонент
 * `blade-ui-kit/blade-icons` из зависимостей Filament и падает сообщением
 * про файл, которого мы не открывали.
 *
 * Сами контуры тест не проверяет: как выглядит ключ или шестерня — вопрос
 * глазами, а не сравнением атрибута `d` со строкой в тесте. Такое сравнение
 * краснело бы на каждой правке рисунка и не поймало бы ни одной ошибки.
 */

it('renders every icon of the set', function (string $name): void {
    $html = Blade::render('<x-ui.icon :name="$name" class="size-6" />', ['name' => $name]);

    // Контур обязателен: `<svg>` без содержимого — это пустой кружок,
    // то есть ровно тот тихий отказ, ради которого набор и заведён.
    expect($html)
        ->toContain('<svg')
        ->and($html)
        ->toMatch('/<(path|circle|rect)/')
        // Размер приходит с места вызова и обязан доехать: собственного
        // умолчания у компонента нет намеренно (правило `RULES.md`
        // про две утилиты одного свойства).
        ->and($html)
        ->toContain('size-6')
        // Значки декоративные — рядом всегда есть текст или `aria-label`
        // на родителе, иначе скринридер прочитает подпись дважды.
        ->and($html)
        ->toContain('aria-hidden="true"');
})->with(['wrench', 'wheel', 'sparkle', 'plus-circle', 'gear', 'headset', 'percent', 'phone', 'check-circle', 'close']);

it('fails loudly on an unknown icon name', function (): void {
    // Тихая пустота здесь хуже падения: разметка на месте, ошибок нет,
    // значка нет — и заметит это не разработчик, а заказчик на проде.
    Blade::render('<x-ui.icon name="definitely-not-an-icon" class="size-6" />');
    // Ожидается `ViewException`, а не `InvalidArgumentException`: Blade
    // оборачивает любое исключение из шаблона своим, дописывая путь файла.
    // Проверяется поэтому текст — в нём имя, которого нет в наборе.
})->throws(ViewException::class, 'definitely-not-an-icon');

it('draws an icon for every service category', function (): void {
    // Новая категория в перечислении приходит на страницу автосервиса
    // и на главную сама. Имя иконки, которого нет в наборе, уронит обе —
    // причём не на той вехе, где категорию завели.
    foreach (ServiceCategory::cases() as $category) {
        $html = Blade::render('<x-ui.icon :name="$name" class="size-6" />', ['name' => $category->icon()]);

        expect($html)->toContain('<svg');
    }
});
