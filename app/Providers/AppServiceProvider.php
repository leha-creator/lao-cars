<?php

namespace App\Providers;

use App\Models\Car;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
