<?php

/*
 * Публичная часть отвечает и собирается.
 *
 * Проверки намеренно не привязаны к содержимому главной: её собирает
 * веха 4.2, а каркас, сборка ассетов и SEO-теги обязаны работать и до, и
 * после этого. Подробные проверки шапки, подвала и шрифтов живут
 * в `tests/Feature/Http/LayoutTest.php`.
 */

it('serves the home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('ЛАО КАРС', escape: false);
});

it('renders the page in russian with seo tags from the layout', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('<html lang="ru">', escape: false)
        ->assertSee('<meta name="description"', escape: false)
        ->assertSee('rel="canonical"', escape: false);
});

it('does not load fonts from an external cdn', function () {
    // Шрифт Instrument Sans с fonts.bunny.net приходил из скелета Laravel
    // и был убран вехой 3.1. Веха 4.1 подключила Unbounded и Manrope
    // самохостингом через сборку, а не тегом <link> с Google Fonts,
    // как в макете: внешний CDN — лишняя зависимость на пути рендера.
    $this->get('/')
        ->assertDontSee('fonts.bunny.net')
        ->assertDontSee('fonts.googleapis.com')
        ->assertDontSee('fonts.gstatic.com');
});
