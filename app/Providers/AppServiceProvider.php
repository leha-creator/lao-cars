<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Service;
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
        Relation::enforceMorphMap([
            'car' => Car::class,
            'service' => Service::class,
        ]);

        $this->configureRateLimiting();
    }

    /**
     * Лимит на публичные формы заявок.
     *
     * Отказ — редирект назад с ошибкой на форме, а не голый 429: лимит
     * бьёт по IP, а за одним адресом сидит офис или мобильный оператор,
     * и живой человек должен увидеть «попробуйте через минуту» на форме,
     * а не страницу ошибки. Плата честная: бот получает 302 и не отличает
     * отказ от успеха — по той же логике, что и honeypot.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('leads', function (Request $request): Limit {
            return Limit::perMinute((int) config('leads.rate_limit.per_minute'))
                ->by((string) $request->ip())
                ->response(function () use ($request) {
                    // DEBUG, а не WARN: срабатывание лимита — штатное
                    // событие, но без записи жалоба «форма не
                    // отправляется» приходит вообще без следа в логах.
                    Log::channel('leads')->debug('[Lead] сработал лимит заявок', [
                        'ip' => $request->ip(),
                    ]);

                    return back()
                        ->withInput()
                        ->withErrors(['phone' => 'Слишком много заявок с вашего адреса. Попробуйте через минуту.']);
                });
        });
    }
}
