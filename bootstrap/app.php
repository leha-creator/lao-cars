<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Условие `expectsJson()` добавлено вехой 4.7 и обязательно.
        //
        // Без него обработчик отдавал HTML или редирект ЛЮБОМУ клиенту,
        // потому что маршрутов `api/*` в проекте нет вовсе — то есть
        // условие всегда было ложным. Форма заявки, отправленная через
        // `fetch`, получала на ошибке валидации 302 на главную вместо 422:
        // `fetch` по умолчанию следует за редиректом, отдаёт 200 с HTML,
        // разбор такого ответа как JSON падает, и симптом читается как
        // «форма зависла», а не как «телефон не заполнен».
        //
        // `api/*` оставлен: он описывает намерение на будущий API,
        // и убирать его вместе с починкой незачем.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
