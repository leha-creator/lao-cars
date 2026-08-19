<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Media;
use App\Models\Setting;
use App\Services\ImageProcessor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Иллюстрации этапов покупки: `assets/photo/` → медиабиблиотека → `home.steps`.
 *
 * Заказчик просил блок «Как проходит покупка» считываться картинками,
 * а не читаться текстом («Это сайт, а не книга»), и шесть снимков —
 * его собственный ответ на вопрос, какими именно.
 *
 * Идёт ПОСЛЕ `SiteSettingSeeder` и не может идти раньше: тот пишет
 * `home.steps` целиком и затёр бы проставленные здесь `image_id`.
 * Этот сид, наоборот, дописывает id в уже засеянные этапы, не трогая
 * ни текстов, ни порядка.
 *
 * Два правила — те же, что у `CarPhotoSeeder`, и по тем же причинам:
 *
 * 1. В тестах не выполняется. Шесть исходников весят около 9 МБ,
 *    и прогон `ImageProcessor` на каждом `RefreshDatabase` превратил бы
 *    набор в наказание. Тесты задают `home.steps` явно.
 * 2. Копирование идемпотентно: уже лежащий на диске `.webp`
 *    пропускается, повторный `db:seed` не переобрабатывает файлы заново.
 *
 * Снимок сопоставляется с этапом по НОМЕРУ в имени файла («5. Доставка»
 * → этап `05`), а не по позиции в списке: администратор волен
 * переставить этапы местами, и привязка по индексу после этого
 * раздала бы картинки не тем этапам — молча и правдоподобно.
 */
class StepPhotoSeeder extends Seeder
{
    private const SOURCE_DIR = 'assets/photo';

    private const TARGET_DIR = 'media';

    private const SETTING_KEY = 'home.steps';

    public function run(): void
    {
        if (app()->runningUnitTests()) {
            $this->command?->info('[StepPhotoSeeder] тестовое окружение — иллюстрации этапов пропущены');

            return;
        }

        $sourceDir = base_path(self::SOURCE_DIR);

        if (! is_dir($sourceDir)) {
            $this->command?->warn("[StepPhotoSeeder] каталог {$sourceDir} не найден — этапы остались текстовыми");

            return;
        }

        $steps = Setting::get(self::SETTING_KEY);

        if (! is_array($steps) || $steps === []) {
            $this->command?->warn('[StepPhotoSeeder] этапы покупки не засеяны — сначала SiteSettingSeeder');

            return;
        }

        $media = $this->storeSources($sourceDir);

        if ($media === []) {
            $this->command?->warn("[StepPhotoSeeder] в {$sourceDir} нет изображений с номером в имени");

            return;
        }

        $attached = 0;

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $number = (int) ($step['number'] ?? 0);

            if (! isset($media[$number])) {
                continue;
            }

            $steps[$index]['image_id'] = $media[$number];
            $attached++;
        }

        Setting::set(self::SETTING_KEY, $steps);

        $this->command?->info(
            '[StepPhotoSeeder] иллюстраций в библиотеке: '.count($media).", привязано к этапам: {$attached}"
        );
    }

    /**
     * Обработать исходники и завести записи библиотеки.
     *
     * @return array<int, int> «номер этапа» => «id записи»
     */
    private function storeSources(string $dir): array
    {
        $disk = Storage::disk('public');
        $processor = app(ImageProcessor::class);
        $result = [];

        foreach ($this->sourceFiles($dir) as $source) {
            $original = pathinfo($source, PATHINFO_FILENAME);
            $number = (int) Str::before($original, '.');

            // Имя без номера — оно попадёт в библиотеку, где нумерация
            // этапов ничего не значит: там ищут по «Доставка».
            $title = trim(Str::after($original, '.'));

            if ($number === 0 || $title === '') {
                $this->command?->warn("[StepPhotoSeeder] пропущен {$original}: имя без номера этапа");

                continue;
            }

            // Имя выводится из исходного и потому предсказуемо — на нём
            // и держится идемпотентность, как в `CarPhotoSeeder`.
            $stem = 'step-'.Str::slug($original);
            $path = self::TARGET_DIR.'/'.$stem.'.webp';

            if ($disk->exists($path)) {
                $thumbPath = $processor->thumbPathFor($path);
                $thumbPath = $disk->exists($thumbPath) ? $thumbPath : null;
                $mime = 'image/webp';
                $size = (int) $disk->size($path);
            } else {
                $stored = $processor->storeFile(
                    sourcePath: $source,
                    disk: 'public',
                    directory: self::TARGET_DIR,
                    originalName: basename($source),
                    basename: $stem,
                    // Иллюстрации этапов покупки — служебные изображения,
                    // а не витрина товара (веха 4.14, решение 9).
                    watermark: false,
                );

                $path = $stored->path;
                $thumbPath = $stored->thumbPath;
                $mime = $stored->mime;
                $size = $stored->size;
            }

            // По пути, а не по имени: имя администратор правит на форме
            // редактирования, и второй прогон завёл бы дубль записи
            // на тот же файл.
            $record = Media::firstOrCreate(
                ['path' => $path],
                [
                    'disk' => 'public',
                    'thumb_path' => $thumbPath,
                    'name' => "Этап покупки: {$title}",
                    'alt' => "Этап покупки: {$title}",
                    'mime' => $mime,
                    'size' => $size,
                ],
            );

            $result[$number] = (int) $record->getKey();
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $dir): array
    {
        $files = [];

        foreach (Finder::create()->files()->in($dir)->name('/\.(png|jpe?g|webp)$/i')->sortByName() as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }
}
