<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Actions;

use App\Models\Media;
use App\Services\ImageProcessor;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
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

    public static function make(): Action
    {
        return Action::make('upload')
            ->label('Загрузить')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading('Загрузка изображений')
            ->modalSubmitActionLabel('Загрузить')
            ->schema([
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
     */
    private static function store(TemporaryUploadedFile $file, ImageProcessor $processor): string
    {
        $stored = $processor->store($file, self::DISK, self::DIRECTORY);

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
        // картинка» разбирается по логам.
        Log::info('Загрузка в медиабиблиотеку', [
            'count' => count($paths),
            'size' => $size,
        ]);

        Notification::make()
            ->title('Изображения загружены')
            ->body(count($paths).' шт., '.Number::fileSize($size, precision: 1))
            ->success()
            ->send();
    }
}
