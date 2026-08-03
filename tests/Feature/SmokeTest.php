<?php

/*
 * Публичная часть отвечает и собирается.
 *
 * Задел под вехи 3.3 и 4.x: когда появится настоящая главная, проверка
 * доступности страницы и подключения собранных ассетов останется той же.
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
    // Шрифт Instrument Sans с fonts.bunny.net приходил из скелета Laravel и был
    // убран: вехе 4.1 нужны другие шрифты, а внешний CDN — лишняя зависимость
    // на пути рендера.
    $this->get('/')->assertDontSee('fonts.bunny.net');
});
