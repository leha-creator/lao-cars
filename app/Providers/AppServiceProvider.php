<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Service;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Драйвер задаётся здесь, а не в конфиге пакета: моста
        // intervention/image-laravel в проекте нет намеренно — нужен сервис
        // с внедрением через контейнер, а не статический фасад. GD, потому
        // что imagick в сборке нет ни локально, ни в образах setup-php.
        $this->app->singleton(ImageManager::class, fn (): ImageManager => ImageManager::gd());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Полиморфный источник заявки хранится короткими алиасами, а не
        // FQCN: иначе перенос класса в другой namespace задним числом
        // ломает все существующие строки в leads.source_type.
        //
        // Карта именно ENFORCE: модель, которой в ней нет, роняет любое
        // полиморфное отношение исключением, а не пишет в колонку FQCN
        // тихо. Поэтому вехе 4.7 пришлось добавить сюда `user` — он стал
        // полиморфным с двух сторон сразу: `push_subscriptions.subscribable`
        // и `notifications.notifiable` (колокольчик панели). Обе таблицы
        // заведены той же вехой, то есть переписывать существующие
        // значения не понадобилось.
        Relation::enforceMorphMap([
            'car' => Car::class,
            'service' => Service::class,
            'user' => User::class,
        ]);

        $this->configureRateLimiting();
        $this->warnOnUnreachableMediaUrl();
    }

    /**
     * Предупреждение о медиа-URL, который ведёт не туда.
     *
     * Диск `public` отдаёт относительный `/storage`, и промахнуться там
     * нечем. Но ASSET_URL возвращает абсолютный адрес — а его легко
     * оставить от другого стенда, и тогда картинки пропадают молча:
     * файлы на месте, симлинк цел, в консоли браузера только
     * ERR_CONNECTION_REFUSED без единой строки в логе приложения.
     *
     * Только вне прода и только при расхождении: это подсказка
     * разработчику на старте, а не постоянный шум.
     */
    private function warnOnUnreachableMediaUrl(): void
    {
        if ($this->app->isProduction() || $this->app->runningInConsole()) {
            return;
        }

        $configured = (string) config('filesystems.disks.public.url');

        // Относительный URL проверять не от чего: его разрешает браузер.
        if (parse_url($configured, PHP_URL_HOST) === null) {
            return;
        }

        $current = request()->getSchemeAndHttpHost();

        if (str_starts_with($configured, $current)) {
            return;
        }

        Log::warning('[FIX] адрес медиа не совпадает с адресом сайта — картинки не загрузятся', [
            'media_url' => $configured,
            'site_url' => $current,
            'hint' => 'ASSET_URL указывает на другой хост или порт',
        ]);
    }

    /**
     * Лимит на публичные формы заявок.
     *
     * Отказ — редирект назад с ошибкой на форме, а не голый 429: лимит
     * бьёт по IP, а за одним адресом сидит офис или мобильный оператор,
     * и живой человек должен увидеть «попробуйте через минуту» на форме,
     * а не страницу ошибки. Плата честная: бот получает 302 и не отличает
     * отказ от успеха — по той же логике, что и honeypot.
     *
     * Вехой 4.7 у отказа появилась вторая форма — по тем же заголовкам
     * запроса, что и у успеха (`LeadController::accepted()`).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('leads', function (Request $request): Limit {
            return Limit::perMinute((int) config('leads.rate_limit.per_minute'))
                ->by((string) $request->ip())
                // Второй аргумент замыкания — заголовки лимитера
                // (`Retry-After`, `X-RateLimit-*`), которые Laravel сам
                // навешивает только на свой умолчательный ответ. Раз ответ
                // наш, прокинуть их обязаны мы: без `Retry-After` клиенту
                // нечем отличить «через минуту» от «навсегда».
                ->response(function (Request $request, array $headers) {
                    $message = 'Слишком много заявок с вашего адреса. Попробуйте через минуту.';

                    // DEBUG, а не WARN: срабатывание лимита — штатное
                    // событие, но без записи жалоба «форма не
                    // отправляется» приходит вообще без следа в логах.
                    //
                    // Форма ответа в контексте, потому что жалобы
                    // диагностируются по-разному: «форма перезагружается
                    // и ругается» — это HTML-ветка и всё работает,
                    // а «форма молчит» — это JSON-ветка, и смотреть надо
                    // в консоль браузера.
                    Log::channel('leads')->debug('[Lead] сработал лимит заявок', [
                        'ip' => $request->ip(),
                        'expects_json' => $request->expectsJson(),
                    ]);

                    if ($request->expectsJson()) {
                        // Тело в формате ошибок валидации, а не голый 429,
                        // — намеренно: клиент разбирает 422 и 429 одной
                        // веткой и рисует текст под полем «Телефон».
                        // Голый 429 вернул бы нас ровно к тому, что веха
                        // 3.7 отвергла: человек за NAT увидел бы поломку
                        // вместо «попробуйте через минуту».
                        return response()->json([
                            'message' => $message,
                            'errors' => ['phone' => [$message]],
                        ], 429, $headers);
                    }

                    return back()
                        ->withInput()
                        ->withErrors(['phone' => $message]);
                });
        });
    }
}
