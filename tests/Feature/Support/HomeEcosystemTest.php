<?php

use App\Support\HomeEcosystem;
use Illuminate\Support\Facades\Blade;

/*
 * Умолчания и закрытые списки блока «Экосистема» главной.
 *
 * Feature, а не Unit: списки сверяются с набором `x-ui.icon` и с таблицей
 * роутов, а без приложения нет ни Blade, ни роутов.
 *
 * Сторожится связь между тремя местами, которая рвётся молча: имя значка
 * в списке и рисунок в компоненте, адрес в списке и зарегистрированный
 * роут, умолчания и оба списка. Неизвестный значок — единственный
 * из промахов, который роняет главную целиком.
 */

it('offers only icons that the icon component can draw', function (string $name): void {
    $html = Blade::render('<x-ui.icon :name="$name" class="size-6" />', ['name' => $name]);

    expect($html)->toContain('<svg');
})->with(fn (): array => array_keys(HomeEcosystem::icons()));

it('falls back to an icon and an address from its own lists', function (): void {
    // Запасной значок, которого нет в наборе, превратил бы страховку
    // от падения главной в его причину.
    expect(HomeEcosystem::icons())->toHaveKey(HomeEcosystem::DEFAULT_ICON)
        ->and(HomeEcosystem::pageLinks())->toHaveKey(HomeEcosystem::DEFAULT_LINK)
        ->and(HomeEcosystem::pageUrl(HomeEcosystem::DEFAULT_LINK))->toBe('#lead-form');
});

it('keeps the default tiles inside the lists and under the cap', function (): void {
    $tiles = HomeEcosystem::defaults()['tiles'];

    expect($tiles)->not->toBeEmpty()
        ->and(count($tiles))->toBeLessThanOrEqual(HomeEcosystem::MAX_TILES);

    foreach ($tiles as $tile) {
        expect(HomeEcosystem::icons())->toHaveKey($tile['icon']);

        // Адрес — либо блок категории, либо статический из списка.
        if (HomeEcosystem::categorySlug($tile['link']) === null) {
            expect(HomeEcosystem::pageLinks())->toHaveKey($tile['link']);
        }
    }
});

it('resolves every listed page address to a url', function (string $target): void {
    // Адрес без роута выпал бы из плашки в запасной — молча. Здесь это
    // видно до выката: все роуты списка обязаны быть зарегистрированы.
    expect(HomeEcosystem::pageUrl($target))->toBeString()->not->toBe('');
})->with(fn (): array => array_keys(HomeEcosystem::pageLinks()));

it('answers null for an address outside the list', function (): void {
    expect(HomeEcosystem::pageUrl('definitely-not-a-page'))->toBeNull()
        // Адрес категории — не статический: его разрешает `HomeContent`
        // по справочнику.
        ->and(HomeEcosystem::pageUrl(HomeEcosystem::CATEGORY_PREFIX.'maintenance'))->toBeNull();
});

it('reads a category slug only from a category address', function (): void {
    expect(HomeEcosystem::categorySlug('category:detailing'))->toBe('detailing')
        ->and(HomeEcosystem::categorySlug('parts'))->toBeNull()
        // Префикс без слага — не категория, а мусор: якорь из пустой
        // строки дал бы ссылку с голой решёткой.
        ->and(HomeEcosystem::categorySlug('category:'))->toBeNull();
});
