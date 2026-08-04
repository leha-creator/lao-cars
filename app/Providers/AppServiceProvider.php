<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\Relation;
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
    }
}
