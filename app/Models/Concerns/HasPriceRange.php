<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Цена одной суммой или диапазоном, с уточнением к ней, — для автомобиля
 * и позиции прайса.
 *
 * Модель обязана иметь колонки `price`, `price_max` и `price_note`:
 * целые рубли, `null` в `price` — «по запросу», `null` в `price_max` —
 * одна сумма, а не диапазон.
 *
 * Формат живёт здесь, а не в шаблонах, по той же причине, по которой
 * `Service::priceLabel()` появился в вехе 4.4: у автомобиля формат был
 * продублирован в двух шаблонах, и «от» с диапазоном пришлось бы
 * дописывать в оба. Фолбэк без цены у сущностей разный («Цена по запросу»
 * на карточке авто, «по запросу» в прайсе), поэтому трейт отдаёт `null`,
 * а подпись фолбэка выбирает модель.
 */
trait HasPriceRange
{
    /**
     * Уточнения к цене, которые ставятся ПЕРЕД суммой.
     *
     * Всё остальное идёт после — см. `formattedPrice()`.
     */
    private const array PRICE_NOTE_PREFIXES = ['от', 'до'];

    /**
     * Неразрывный пробел — см. «Где цена может переноситься»
     * в `formattedPrice()`.
     */
    private const string NBSP = "\u{00A0}";

    /**
     * Диапазон, который не читается как диапазон, снимается при записи.
     *
     * Форма такого не пропустит (`gt('price')` у «Цены до»,
     * `requiredWith` у цены), а сид, импорт или tinker — могут. На выводе
     * такое значение и так игнорируется (`hasPriceRange()`), а WARN нужен,
     * чтобы данные, пришедшие мимо формы, было видно.
     */
    public static function bootHasPriceRange(): void
    {
        static::saving(function (Model $model): void {
            $price = $model->getAttribute('price');
            $max = $model->getAttribute('price_max');

            if ($max === null || ($price !== null && (int) $max > (int) $price)) {
                return;
            }

            Log::warning('[FIX] верхняя граница цены не больше нижней или без цены — диапазон снят', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'price' => $price,
                'price_max' => $max,
            ]);

            $model->setAttribute('price_max', null);
        });
    }

    public function initializeHasPriceRange(): void
    {
        $this->mergeCasts([
            'price' => 'integer',
            'price_max' => 'integer',
        ]);
    }

    /**
     * Есть ли цена — то есть надо ли набирать её акцентом.
     *
     * Метод заведён ради шаблона: сравнивать вывод `priceLabel()` со строкой
     * «по запросу» в Blade значит завести второе место, где живёт эта
     * формулировка, и разойтись с первым при первой же её правке.
     */
    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    public function hasPriceRange(): bool
    {
        return $this->price !== null
            && $this->price_max !== null
            && $this->price_max > $this->price;
    }

    /**
     * Предлог из уточнения («от» или «до») в нижнем регистре или `null`.
     *
     * Нужен не только формату: микроразметка карточки автомобиля выбирает
     * по нему между `Offer` и `AggregateOffer`.
     */
    public function pricePrefix(): ?string
    {
        $note = mb_strtolower(trim((string) $this->price_note));

        return in_array($note, self::PRICE_NOTE_PREFIXES, true) ? $note : null;
    }

    /**
     * Цена строкой без фолбэка: «от 6 500 ₽», «1 200 ₽ за колесо»,
     * «от 6 500 до 9 000 ₽»; `null`, если цены нет.
     *
     * Данные не различают префикс и суффикс, и это осознанно: `price_note` —
     * одна колонка со свободным текстом, а «от» стоит перед суммой,
     * «за колесо» — после. Позиция уточнения выводится из набора предлогов,
     * а не хранится в базе, потому что альтернативы хуже: словарь всех
     * возможных уточнений не выживет (администратор напишет своё
     * и получит пустое место вместо текста), а колонка-переключатель
     * «префикс/суффикс» — это миграция ради вёрстки.
     *
     * Триггер пересмотра назван заранее: третий предлог, попросившийся
     * в `PRICE_NOTE_PREFIXES`, означает, что позицию уточнения пора хранить
     * в данных. До него ошибка стоит одной грамматически неверной строки
     * в прайсе, а не переделки схемы.
     *
     * Предлог приписывается только к ТОЧНОЙ сумме: у диапазона предлоги
     * уже свои, и «от» из уточнения дало бы «от от 6 500 до 9 000 ₽».
     * Суффикс дописывается к любой сумме — «от 1 000 до 2 000 ₽ за колесо».
     *
     * Сравнение идёт по приведённому к нижнему регистру и обрезанному
     * значению, а печатается исходное: администратор напишет «От»,
     * и разница в одной букве не должна утаскивать предлог в конец строки.
     *
     * Где цена может переноситься. Пробелы внутри суммы, перед «₽»
     * и после предлога — неразрывные; обычных два: перед «до» у диапазона
     * и перед припиской после суммы. На «Сервисе» и «Запчастях» цена
     * набрана вдвое крупнее текста и на телефоне переносится, и обычный
     * пробел разорвал бы «35 000» на «35» и «000», оторвал бы «₽» от числа
     * или оставил бы «от» висеть в конце строки. Сравнивать вывод со строкой
     * поэтому надо с `\u{a0}`, а не с пробелом.
     */
    public function formattedPrice(): ?string
    {
        if (! $this->hasPrice()) {
            return null;
        }

        $nbsp = self::NBSP;

        $amount = $this->hasPriceRange()
            ? 'от'.$nbsp.self::rubles($this->price).' до'.$nbsp.self::rubles($this->price_max).$nbsp.'₽'
            : self::rubles($this->price).$nbsp.'₽';

        $note = trim((string) $this->price_note);

        if ($note === '') {
            return $amount;
        }

        if ($this->pricePrefix() === null) {
            return "{$amount} {$note}";
        }

        return $this->hasPriceRange() ? $amount : $note.$nbsp.$amount;
    }

    private static function rubles(int $value): string
    {
        return number_format($value, 0, ',', self::NBSP);
    }
}
