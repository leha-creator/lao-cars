<?php

use App\Http\Middleware\RecordRequestMetrics;
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
        // Куда отправлять гостя, попавшего под `auth` (веха 4.7).
        //
        // Штатное умолчание — `route('login')`, а такого имени в проекте
        // нет: вход живёт в панели и зовётся `filament.admin.auth.login`.
        // Без этой строки единственный маршрут под `auth` за пределами
        // панели — эндпоинт подписки на push — отдал бы гостю не редирект,
        // а `RouteNotFoundException`, то есть 500 вместо страницы входа.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Учёт запросов и посещений для инфопанели администратора.
        //
        // ГЛОБАЛЬНО, а не в группе `web`: панель Filament собирает свой
        // стек middleware и в группу `web` не входит, а счётчик обязан
        // видеть и её — иначе опрос колокольчика нельзя отделить
        // от посещаемости сайта. Глобальный стек видит и 404 без маршрута.
        //
        // Вся работа — в `terminate()`, уже после отправки ответа:
        // статистика не имеет права задержать или уронить запрос.
        //
        // Запросы за время `artisan down` сюда не доходят: их отбивает
        // `PreventRequestsDuringMaintenance`, стоящий в стеке раньше.
        // Поэтому окно выката не рисует на графике ложный всплеск 5xx.
        $middleware->append(RecordRequestMetrics::class);
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
