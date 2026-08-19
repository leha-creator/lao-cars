<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Actions;

use App\Models\Media;
use App\Services\ImageProcessor;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Загрузка пачки изображений в библиотеку.
 *
 * Запись создаётся прямо в `saveUploadedFileUsing()`, а не в `action()`,
 * и это вынужденно: исходное имя файла и метаданные обработки
 * (`thumb_path`, `mime`, `size`) существуют только внутри этого колбэка.
 * Наружу `FileUpload` отдаёт плоский массив путей — восстановить по пути
 * человеческое имя вроде «BMW X5 спереди» уже невозможно, а именно по
 * нему библиотека и ищется.
 */
final class UploadMediaAction
{
    private const DISK = 'public';

    private const DIRECTORY = 'media';

    /**
     * Назначение файла: витрина (со штампом) или служебное (без).
     *
     * Строки, а не булево: «да/нет» на форме потребовало бы подписи
     * «Ставить логотип?», то есть вопроса о механике вместо вопроса
     * о том, что за файл. Администратор знает второе и не обязан
     * держать в голове первое.
     */
    public const string PURPOSE_CATALOG = 'catalog';

    public const string PURPOSE_UTILITY = 'utility';

    public static function make(): Action
    {
        return Action::make('upload')
            ->label('Загрузить')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Загрузка изображений')
            ->modalSubmitActionLabel('Загрузить')
            ->schema([
                // ОБЯЗАТЕЛЬНЫЙ ВЫБОР БЕЗ УМОЛЧАНИЯ (веха 4.14, решение 9).
                //
                // Ответ заказчика — «штамповать всё, кроме медиабиблиотеки»
                // — буквально невыполним: фотографии услуг и запчастей,
                // которые штамповать нужно, физически лежат здесь же.
                // Развилку разрешает назначение файла, а не место хранения.
                //
                // Переключатель с умолчанием отклонён в ОБЕ стороны:
                // умолчание «да» ставит логотип компании на аватар автора
                // отзыва, умолчание «нет» оставляет фото услуги без штампа,
                // и оба промаха видны только на сайте и только тому, кто
                // заметит. Обязательный выбор — единственная форма, которая
                // не может сработать молча.
                //
                // `live()` здесь НЕ НУЖЕН, и это проверено, а не принято
                // на веру: файлы сохраняются в `beforeStateDehydrated`,
                // то есть при отправке формы, когда значение переключателя
                // уже приехало на сервер вместе с остальным состоянием.
                // `live()` добавил бы запрос на каждый клик по радиокнопке
                // и не изменил бы ничего.
                Radio::make('purpose')
                    ->label('Назначение файлов')
                    ->options([
                        self::PURPOSE_CATALOG => 'Фото услуги, запчасти или автомобиля',
                        self::PURPOSE_UTILITY => 'Служебное изображение',
                    ])
                    ->descriptions([
                        self::PURPOSE_CATALOG => 'На фото будет автоматически поставлен логотип компании.',
                        self::PURPOSE_UTILITY => 'Без логотипа: портрет сотрудника, аватар автора отзыва, фон блока, иллюстрация этапа покупки.',
                    ])
                    ->required(),

                FileUpload::make('files')
                    ->label('Файлы')
                    ->hiddenLabel()
                    ->multiple()
                    ->image()
                    ->panelLayout('grid')
                    ->disk(self::DISK)
                    ->directory(self::DIRECTORY)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    // Ровно потолок Livewire: больший maxSize() отвалится его
                    // валидацией с сообщением, по которому причину не понять.
                    ->maxSize((int) config('images.max_upload_kb'))
                    ->saveUploadedFileUsing(self::store(...)),
            ])
            ->action(self::report(...));
    }

    /**
     * Обработать файл и завести запись библиотеки. Возвращает путь —
     * его `FileUpload` кладёт в состояние формы.
     *
     * Назначение читается из состояния соседнего поля через `$get`.
     * Сравнение СТРОГОЕ и с «витриной», а не с «служебным»: незаполненное
     * поле (валидация до сюда не дошла бы, но код обязан пережить
     * и это) даёт `null`, и `null !== PURPOSE_CATALOG` означает
     * «не штамповать». Ошибиться в сторону отсутствующего логотипа
     * безопаснее, чем в сторону логотипа на портрете сотрудника:
     * первое чинится командой `images:restamp`, второе — только
     * перезаливкой файла.
     */
    private static function store(TemporaryUploadedFile $file, ImageProcessor $processor, Get $get): string
    {
        $stored = $processor->store(
            $file,
            self::DISK,
            self::DIRECTORY,
            watermark: $get('purpose') === self::PURPOSE_CATALOG,
        );

        Media::create([
            'disk' => self::DISK,
            'path' => $stored->path,
            'thumb_path' => $stored->thumbPath,
            // Исходное имя без расширения: «IMG_1760», а не
            // «p7miyani-6a71dbad.webp». Правится на форме редактирования.
            'name' => Str::of($file->getClientOriginalName())->beforeLast('.')->trim()->value(),
            'alt' => null,
            'mime' => $stored->mime,
            'size' => $stored->size,
            // Размеры и отметка о штампе (веха 4.14). Отметка ставится
            // по факту наложения, а не по намерению: логотип может
            // не встать — файл штампа не найден, кадр уже порога, —
            // и тогда `images:restamp` обязан такую запись увидеть.
            'width' => $stored->width,
            'height' => $stored->height,
            'watermarked_at' => $stored->watermarked ? now() : null,
        ]);

        return $stored->path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function report(array $data): void
    {
        /** @var array<int, string> $paths */
        $paths = $data['files'] ?? [];

        if ($paths === []) {
            return;
        }

        $size = (int) Media::query()->whereIn('path', $paths)->sum('size');

        // Медиа переиспользуется между разделами, и вопрос «куда делась
        // картинка» разбирается по логам. Назначение и число заштампованных
        // файлов пишутся туда же (веха 4.14): вопрос «почему на этом фото
        // нет логотипа» разбирается по логам, а не по памяти администратора.
        Log::info('Загрузка в медиабиблиотеку', [
            'count' => count($paths),
            'size' => $size,
            'purpose' => $data['purpose'] ?? null,
            'watermarked' => Media::query()->whereIn('path', $paths)->whereNotNull('watermarked_at')->count(),
        ]);

        Notification::make()
            ->title('Изображения загружены')
            ->body(count($paths).' шт., '.Number::fileSize($size, precision: 1))
            ->success()
            ->send();
    }
}
