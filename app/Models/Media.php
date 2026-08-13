<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MediaSettingKeys;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Запись общей медиабиблиотеки.
 *
 * Отдельная сущность от `CarPhoto`, и это решение `ARCHITECTURE.md`:
 * галерее автомобиля нужен порядок внутри карточки и каскадное удаление
 * вместе с автомобилем, библиотеке — переиспользование одного файла
 * в нескольких местах. Слить их в одну таблицу значило бы либо потерять
 * порядок, либо завести таблицу-связку ради галереи из четырёх снимков.
 *
 * Потребители появились вехой 3.5: фото сотрудников (`employees.media_id`),
 * фото авторов отзывов (`reviews.media_id`) и фон промо-блока на главной
 * (ключ настроек `home.promo.image_id`). Четвёртым стали иллюстрации
 * этапов покупки — первая ссылка ВНУТРИ репитера настроек, ради которой
 * реестр `MediaSettingKeys` и разделил ключ настройки с путём внутрь
 * её значения.
 */
#[Fillable(['disk', 'path', 'thumb_path', 'name', 'alt', 'mime', 'size'])]
final class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    /**
     * Места, где запись используется, в человекочитаемом виде.
     *
     * Пустой массив означает «файл свободен, удалять безопасно».
     *
     * Настройки читаются через `Setting::get()`, то есть из кеша, а не
     * JSON-запросом к каждой строке: список медийных ключей заведомо
     * короткий, а кеш всё равно прогрет — страница настроек и подвал
     * сайта обращаются к нему на каждом запросе.
     *
     * @return list<string>
     */
    public function usages(): array
    {
        $usages = [];

        foreach (Employee::query()->where('media_id', $this->getKey())->pluck('name') as $name) {
            $usages[] = "Сотрудник: {$name}";
        }

        foreach (Review::query()->where('media_id', $this->getKey())->pluck('author_name') as $name) {
            $usages[] = "Отзыв: {$name}";
        }

        foreach (MediaSettingKeys::all() as $entry) {
            // Ключ настройки и путь внутрь её значения приходят из реестра
            // раздельно — вывести их из одной строки нельзя: у репитера
            // граница между ними не совпадает с последней точкой.
            // Подробности отказа — в PHPDoc `MediaSettingKeys`.
            $values = data_get(Setting::get($entry['setting']), $entry['path']);

            // Путь с подстановочным знаком отдаёт СПИСОК значений — по
            // одному на элемент репитера, — а путь в объект одно. Обе
            // формы приводятся к списку, и вопрос становится «id есть
            // среди значений», а не «id равен значению».
            foreach (Arr::wrap($values) as $value) {
                if ($value === $this->getKey()) {
                    // Одно упоминание на настройку: изображение, выбранное
                    // сразу в двух этапах, — это по-прежнему одно место,
                    // и повторять его в перечне нечем помочь.
                    $usages[] = $entry['label'];

                    break;
                }
            }
        }

        return $usages;
    }

    /**
     * Публичный URL файла. Не входит в `$appends` — по той же причине,
     * что и в `CarPhoto`: сериализация списка не должна дёргать драйвер
     * диска на каждую запись.
     */
    protected function url(): Attribute
    {
        return Attribute::get(
            fn (): string => Storage::disk($this->disk)->url($this->path),
        );
    }

    /**
     * URL превью с откатом на оригинал, если превью нет.
     */
    protected function thumbUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->thumb_path === null
                ? $this->url
                : Storage::disk($this->disk)->url($this->thumb_path),
        );
    }

    protected static function booted(): void
    {
        // Чистить диск в каждом месте вызова значит однажды забыть:
        // удаление возможно из админки, из tinker и из будущего импорта.
        // `#[ObservedBy]` в проекте прецедента не имеет — событие вешается
        // так же, как в `Setting`.
        self::deleted(static function (self $media): void {
            $disk = Storage::disk($media->disk);

            foreach (array_filter([$media->path, $media->thumb_path]) as $path) {
                if (! $disk->exists($path)) {
                    continue;
                }

                $disk->delete($path);

                // Удалённый по ошибке файл иначе не восстановить
                // и не отследить.
                Log::info('Файл медиабиблиотеки удалён с диска', [
                    'media_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $path,
                ]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
