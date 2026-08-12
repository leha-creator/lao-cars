<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CarStatus;
use App\Enums\DriveType;
use App\Models\Car;
use App\Models\CarPhoto;
use Illuminate\Support\Facades\Log;

/**
 * Микроразметка карточки автомобиля (веха 4.3).
 *
 * JSON-LD, а не `itemprop` по вёрстке, и это решение, а не вкус:
 * микроформаты атрибутами привязывают SEO к раскладке — любая переверстка
 * молча ломает разметку, а заметить это можно только в Search Console
 * через недели. JSON-LD живёт одним тегом и переживает переверстку целиком.
 *
 * Сервисом это стало по тому же признаку, что и `HomeContent`: данные надо
 * привести к форме, прежде чем отдать их в Blade, — выбросить пустые ключи,
 * перевести статус в словарь schema.org, собрать вложенные объекты.
 * Собирать это в шаблоне значило бы держать словари в разметке.
 *
 * Собственных запросов сервис не делает и работает поверх связей,
 * загруженных контроллером: `brand`, `photos` и `attributeValues.attribute`.
 * Без них каждая карточка добавит по запросу на связь — то же самое
 * предупреждение стоит в PHPDoc `Car::cardAttributes()`.
 */
final class CarStructuredData
{
    /**
     * Разметка страницы карточки: объект автомобиля и хлебные крошки.
     *
     * Оба узла уходят одним тегом списком — это валидная форма JSON-LD
     * и повод не заводить второй `<script>` ради шести строк.
     *
     * @return list<array<string, mixed>>
     */
    public function for(Car $car): array
    {
        return [
            $this->vehicle($car),
            $this->breadcrumbs($car),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicle(Car $car): array
    {
        $photos = $car->photos;

        if ($photos->isEmpty()) {
            // Последствие за пределами внешнего вида: разметка уходит
            // без `image`, и карточка перестаёт быть кандидатом
            // на расширенный сниппет. Уровень WARN и канал умолчательный —
            // это конфигурационная ошибка, которая чинится один раз
            // в админке.
            Log::warning('[Каталог] у автомобиля нет ни одного фото — микроразметка уходит без image', [
                'car_id' => $car->id,
                'slug' => $car->slug,
            ]);
        }

        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            // `Car ⊂ Vehicle ⊂ Product` в schema.org, поэтому «двойной»
            // тип не нужен: одного `Car` достаточно и для Product,
            // и для Vehicle, а два типа только запутают.
            '@type' => 'Car',
            'name' => $this->name($car),
            'url' => route('catalog.show', $car),
            'brand' => [
                '@type' => 'Brand',
                'name' => $car->brand->name,
            ],
            'model' => $car->model,
            'vehicleModelDate' => (string) $car->year,
            // `url()` здесь обязателен: `$photo->url` относительный (диск
            // `public` в config/filesystems.php), а schema.org требует
            // абсолютный адрес картинки — относительный робот не заберёт.
            // Для абсолютного значения (ASSET_URL с CDN) хелпер вернёт его
            // как есть, так что обёртка безопасна в обоих случаях.
            'image' => $photos->map(fn (CarPhoto $photo): string => url($photo->url))->all(),
            'description' => $car->description,
            // Пробег `null` — это автомобиль под заказ, у которого пробега
            // нет вовсе (комментарий миграции вехи 3.2), то есть новый.
            'itemCondition' => $car->mileage === null
                ? 'https://schema.org/NewCondition'
                : 'https://schema.org/UsedCondition',
            'fuelType' => $car->engine_type->label(),
            'vehicleEngine' => $this->engine($car),
            'driveWheelConfiguration' => $this->driveWheelConfiguration($car->drive),
            'mileageFromOdometer' => $car->mileage === null ? null : [
                '@type' => 'QuantitativeValue',
                'value' => $car->mileage,
                // KMT — код километра в UN/CEFACT. Без единицы число
                // читается как мили: у части агрегатов это умолчание.
                'unitCode' => 'KMT',
            ],
            // Тип кузова живёт в динамических характеристиках, а не
            // в колонке: отсутствующая характеристика просто не попадает
            // в разметку.
            'bodyType' => $this->text($car->attributeValue('body_type')),
            'offers' => $this->offers($car),
        ]);
    }

    /**
     * Двигатель: объём и мощность.
     *
     * Мощность отдаётся с `unitText`, а не с кодом единицы: русские
     * «л.с.» — метрическая лошадиная сила, а документированный в примерах
     * Google код `BHP` означает тормозную, которая на полтора процента
     * другая. Соврать в коде единицы хуже, чем не указать его.
     *
     * @return ?array<string, mixed>
     */
    private function engine(Car $car): ?array
    {
        $engine = $this->withoutEmpty([
            '@type' => 'EngineSpecification',
            'fuelType' => $car->engine_type->label(),
            // У электромобиля объёма нет вовсе — `hasVolume()` знает
            // об этом, и «0.0 л» в разметке не появляется.
            'engineDisplacement' => $car->engine_type->hasVolume() && $car->engine_volume !== null
                ? [
                    '@type' => 'QuantitativeValue',
                    'value' => (float) $car->engine_volume,
                    'unitCode' => 'LTR',
                ]
                : null,
            'enginePower' => $car->engine_power === null ? null : [
                '@type' => 'QuantitativeValue',
                'value' => $car->engine_power,
                'unitText' => 'л.с.',
            ],
        ]);

        // Один только `@type` — это не объект двигателя, а пустая
        // обёртка: если ни объёма, ни мощности нет, ключа быть не должно.
        return count($engine) > 2 ? $engine : null;
    }

    /**
     * Предложение о продаже.
     *
     * Автомобиль без цены не получает `offers` вовсе: `Offer` без `price`
     * невалиден, а выдуманный ноль означает «отдаём даром» — и именно так
     * его прочитает агрегатор. «Цена по запросу» — это отсутствие
     * предложения в терминах schema.org, а не предложение с нулевой ценой.
     *
     * @return ?array<string, mixed>
     */
    private function offers(Car $car): ?array
    {
        if ($car->price === null) {
            return null;
        }

        return [
            '@type' => 'Offer',
            'url' => route('catalog.show', $car),
            'price' => (string) (int) $car->price,
            'priceCurrency' => 'RUB',
            'availability' => match ($car->status) {
                CarStatus::InStock => 'https://schema.org/InStock',
                CarStatus::OnOrder => 'https://schema.org/PreOrder',
                // `BackOrder`, а не напрашивающийся `PreOrder`: разница
                // в словаре содержательная. `PreOrder` — товар, который
                // ещё не выпущен и получить его нельзя в принципе;
                // `BackOrder` — товар заказан и придёт позже. Автомобиль
                // в пути уже куплен и физически едет, то есть это второе.
                // `PreOrder` остаётся у «Под заказ», где он и верен.
                CarStatus::InTransit => 'https://schema.org/BackOrder',
                CarStatus::Sold => 'https://schema.org/SoldOut',
            },
        ];
    }

    /**
     * Хлебные крошки «Каталог / <марка> <модель>, <год>».
     *
     * Крошка есть в макете обеих версий, то есть верстается всё равно,
     * а разметка к ней даёт в выдаче «laocars.ru › Каталог › …» вместо
     * голого URL.
     *
     * @return array<string, mixed>
     */
    private function breadcrumbs(Car $car): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Каталог',
                    'item' => route('catalog.index'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $this->name($car),
                    'item' => route('catalog.show', $car),
                ],
            ],
        ];
    }

    private function name(Car $car): string
    {
        return $car->brand->name.' '.$car->model.', '.$car->year;
    }

    private function driveWheelConfiguration(?DriveType $drive): ?string
    {
        return match ($drive) {
            DriveType::Front => 'https://schema.org/FrontWheelDriveConfiguration',
            DriveType::Rear => 'https://schema.org/RearWheelDriveConfiguration',
            DriveType::Full => 'https://schema.org/AllWheelDriveConfiguration',
            null => null,
        };
    }

    /**
     * Значение характеристики строкой — или `null`, если её нет.
     *
     * `attributeValue()` отдаёт значение уже приведённым к типу, поэтому
     * булева характеристика придёт сюда как `true`, а не как строка.
     */
    private function text(string|int|float|bool|null $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Выбросить пустые ключи: ключ со значением `null` в JSON-LD хуже
     * отсутствующего — валидаторы читают его как «поле есть и пустое».
     *
     * Проверка перечисляет пустые значения явно, а не полагается
     * на `array_filter()` без колбэка: тот выбросил бы и `0`, и `'0'`,
     * то есть нулевой пробег вместе с настоящими пустотами.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
