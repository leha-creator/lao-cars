<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cars\Concerns;

use App\Enums\CarAttributeType;
use App\Models\Car;
use App\Services\StoredImage;

/**
 * Запись связей автомобиля из формы: галерея и значения характеристик.
 *
 * И `photos`, и `car_attributes` — не колонки таблицы `cars`, поэтому
 * до `$car->fill()` они дойти не должны: `photos` — имя связи, а
 * `attributes` вообще столкнулось бы со служебным свойством Eloquent
 * (именно поэтому ключ состояния называется `car_attributes`).
 *
 * Обе страницы — создания и редактирования — снимают эти ключи
 * в `mutateFormDataBefore*()` и применяют их в `after*()`, когда
 * автомобиль уже получил `id`. Трейт существует, чтобы правило жило
 * в одном месте: продублированное на двух страницах, оно однажды
 * обновится только на одной.
 *
 * Поле `FileUpload` при этом дегидрируется как обычно — не через
 * `dehydrated(false)`. Разница принципиальная: с `dehydrated(false)`
 * пути пришлось бы доставать из внутреннего состояния компонента,
 * а так они приходят обычным ключом `$data` ровно там, где их видно.
 */
trait HandlesCarRelations
{
    /** @var array<int, string>|null */
    private ?array $carPhotoPaths = null;

    /**
     * Что `ImageProcessor` знает о только что загруженных файлах, но чего
     * не видно по пути: размеры кадра и факт наложения логотипа.
     *
     * Состояние `FileUpload` — плоский список путей, поэтому результат
     * обработки иначе теряется между загрузкой и записью связи. Хранить
     * его в памяти компонента безопасно: файлы сохраняются
     * в `beforeStateDehydrated`, то есть в ТОМ ЖЕ запросе, в котором
     * потом отработает `syncCarRelations()`, а не в отдельном запросе
     * загрузки.
     *
     * @var array<string, array{width: ?int, height: ?int, watermarked: bool}>
     */
    private array $carPhotoMeta = [];

    /** @var array<string, mixed>|null */
    private ?array $carAttributeValues = null;

    /**
     * Запомнить результат обработки только что загруженного файла.
     *
     * Публичный, потому что вызывается из колбэка `FileUpload`
     * в `CarForm` через инъекцию `$livewire`: схема формы статическая
     * и до приватного состояния страницы иначе не достаёт.
     */
    public function rememberProcessedPhoto(StoredImage $stored): void
    {
        $this->carPhotoMeta[$stored->path] = [
            'width' => $stored->width,
            'height' => $stored->height,
            'watermarked' => $stored->watermarked,
        ];
    }

    /**
     * Снять связи из данных формы до записи модели.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractCarRelations(array $data): array
    {
        $this->carPhotoPaths = array_values((array) ($data['photos'] ?? []));
        $this->carAttributeValues = (array) ($data['car_attributes'] ?? []);

        unset($data['photos'], $data['car_attributes']);

        return $data;
    }

    /**
     * Применить снятые связи — уже после того, как автомобиль сохранён.
     */
    protected function syncCarRelations(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Car) {
            return;
        }

        if ($this->carPhotoPaths !== null) {
            $record->syncPhotos($this->carPhotoPaths, $this->carPhotoMeta);
        }

        if ($this->carAttributeValues !== null) {
            // Именно syncAttributeValues(), а не запись CarAttributeValue
            // напрямую: строгая проверка на пустоту, удаление пустого
            // значения и проверка по `options` живут внутри метода
            // (веха 3.3), и вторая реализация с ними разъедется.
            $record->syncAttributeValues($this->carAttributeValues);
        }
    }

    /**
     * Подставить в форму текущие значения характеристик.
     *
     * `with('attribute')` обязателен: без него поле на каждую
     * характеристику даёт отдельный запрос.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillCarAttributes(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Car) {
            return $data;
        }

        $values = [];

        foreach ($record->attributeValues()->with('attribute')->get() as $value) {
            $attribute = $value->attribute;

            if ($attribute === null) {
                continue;
            }

            // Для булевой характеристики значение обязано стать `bool`:
            // Toggle, получив строку '0', покажет «Да» — непустая строка
            // в PHP истинна.
            $values[$attribute->key] = $attribute->type === CarAttributeType::Boolean
                ? $attribute->cast($value->value) === true
                : $value->value;
        }

        $data['car_attributes'] = $values;

        return $data;
    }
}
