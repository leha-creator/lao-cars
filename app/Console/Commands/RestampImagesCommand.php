<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CarPhoto;
use App\Models\Media;
use App\Models\Service;
use App\Services\ImageProcessor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Разовый проход по уже загруженным фотографиям: наложить логотип
 * и заполнить размеры (веха 4.14).
 *
 * КОМАНДА, А НЕ МИГРАЦИЯ. Миграция перезаписывает файлы на диске,
 * а откат миграции файлы не вернёт — `down()` оказался бы враньём.
 * Команда запускается руками, отчитывается числами и по умолчанию
 * не трогает то, у чего отметка уже стоит.
 *
 * ФАЙЛЫ ПЕРЕЗАПИСЫВАЮТСЯ БЕЗ РЕЗЕРВНОЙ КОПИИ, и это сказано вслух
 * трижды: здесь, в подтверждении перед стартом и в `docs/deploy.md`.
 * Копия на диске удвоила бы медиа-хранилище; штатный откат —
 * восстановление из бэкапа тома.
 *
 * ЧТО ОТБИРАЕТСЯ. Фотографии автомобилей — все без отметки. Из библиотеки
 * — только те, что используются как фото услуги или запчасти
 * (`services.media_id`): всё остальное там служебное — портреты
 * сотрудников, аватары отзывов, фоны, иллюстрации этапов, — и логотип
 * компании на портрете менеджера был бы не исправлением, а порчей.
 */
final class RestampImagesCommand extends Command
{
    protected $signature = 'images:restamp
                            {--dry-run : Только показать, что будет сделано; диск не трогается}
                            {--only= : Ограничить обход: cars или media}
                            {--limit= : Обработать не больше N записей}
                            {--force : Перештамповать даже то, у чего отметка уже стоит}';

    protected $description = 'Наложить логотип на ранее загруженные фотографии и заполнить их размеры. ВНИМАНИЕ: файлы перезаписываются без резервной копии';

    public function handle(ImageProcessor $processor): int
    {
        $only = $this->option('only');

        if ($only !== null && ! in_array($only, ['cars', 'media'], strict: true)) {
            $this->error('--only принимает только cars или media.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = $this->option('limit') === null ? null : max(0, (int) $this->option('limit'));

        $targets = $this->targets($only, $force, $limit);
        $total = array_sum(array_map('count', $targets));

        if ($total === 0) {
            $this->info('Нечего обрабатывать: все фотографии уже отмечены.');

            return self::SUCCESS;
        }

        // Сколько тронет — команда сообщает ВСЕГДА, а не только
        // в `--dry-run`: число, увиденное до старта, — единственный
        // момент, когда «упс, не ту базу» ещё поправимо.
        $this->line('К обработке: '.$total.' шт. ('
            .'автомобили: '.count($targets['cars']).', библиотека: '.count($targets['media']).')');

        if ($dryRun) {
            $this->info('Пробный прогон: диск не изменён.');

            return self::SUCCESS;
        }

        if (! $this->confirmed($total)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $stats = ['processed' => 0, 'skipped' => 0, 'bytes' => 0];

        foreach ($targets as $rows) {
            foreach ($rows as $row) {
                $this->restamp($row, $processor, $stats);
            }
        }

        $this->newLine();
        $this->info("Обработано: {$stats['processed']}, пропущено: {$stats['skipped']}.");

        // Без итоговой записи «команду запускали или нет» через неделю
        // не установить — а спрашивать об этом будут именно тогда.
        Log::info('[images:restamp] проход завершён', [
            'processed' => $stats['processed'],
            'skipped' => $stats['skipped'],
            'bytes_after' => $stats['bytes'],
            'only' => $only,
            'force' => $force,
            'limit' => $limit,
        ]);

        return self::SUCCESS;
    }

    /**
     * Что обрабатывать, разложенное по видам.
     *
     * @return array{cars: list<CarPhoto>, media: list<Media>}
     */
    private function targets(?string $only, bool $force, ?int $limit): array
    {
        $unstamped = static fn (Builder $query): Builder => $force
            ? $query
            : $query->whereNull('watermarked_at');

        $cars = $only === 'media' ? [] : $unstamped(CarPhoto::query())
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $q): Builder => $q->limit($limit))
            ->get()
            ->all();

        // Остаток лимита, а не лимит заново: `--limit=10` означает десять
        // записей всего, а не десять на каждый вид.
        $rest = $limit === null ? null : max(0, $limit - count($cars));

        $media = $only === 'cars' || $rest === 0 ? [] : $unstamped(Media::query())
            ->whereIn('id', Service::query()->whereNotNull('media_id')->select('media_id'))
            ->orderBy('id')
            ->when($rest !== null, fn (Builder $q): Builder => $q->limit($rest))
            ->get()
            ->all();

        return ['cars' => $cars, 'media' => $media];
    }

    /**
     * Подтверждение перед живым прогоном.
     *
     * `--no-interaction` его пропускает — иначе команда не годится
     * для деплой-скрипта, ради которого она и написана.
     */
    private function confirmed(int $total): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        $this->warn('Файлы будут ПЕРЕЗАПИСАНЫ на диске. Резервной копии команда не делает:');
        $this->warn('вернуть исходники можно только из бэкапа тома.');

        return confirm("Обработать {$total} шт.?", default: false);
    }

    /**
     * Перештамповать одну запись: оригинал, превью, размеры, отметка.
     *
     * @param  CarPhoto|Media  $row
     * @param  array{processed: int, skipped: int, bytes: int}  $stats
     */
    private function restamp(Model $row, ImageProcessor $processor, array &$stats): void
    {
        $disk = Storage::disk($row->disk);

        try {
            if (! $disk->exists($row->path)) {
                throw new \RuntimeException('файла нет на диске');
            }

            // Файл кладётся во временный путь, потому что `ImageProcessor`
            // работает с локальной файловой системой, а диск может быть
            // и не локальным.
            $temporary = tempnam(sys_get_temp_dir(), 'restamp');
            file_put_contents($temporary, $disk->get($row->path));

            // ТА ЖЕ обработка, что у загрузки из админки: второй путь
            // наложения означал бы второе место, где размер и положение
            // штампа могут разойтись с первым.
            $stored = $processor->storeFile(
                sourcePath: $temporary,
                disk: $row->disk,
                directory: dirname($row->path),
                originalName: basename($row->path),
                // Имя сохраняется: запись указывает на этот путь, и на него
                // же ссылаются страницы. Новое имя означало бы осиротевший
                // файл и запись, ведущую в никуда.
                basename: pathinfo($row->path, PATHINFO_FILENAME),
            );

            @unlink($temporary);

            $row->forceFill([
                'thumb_path' => $stored->thumbPath,
                'width' => $stored->width,
                'height' => $stored->height,
                // Отметка ставится ПО ФАКТУ наложения. Не встал логотип
                // (файл штампа потерян, кадр уже порога) — записи нет,
                // и следующий прогон попробует снова.
                'watermarked_at' => $stored->watermarked ? now() : null,
            ]);

            if ($row instanceof Media) {
                $row->size = $stored->size;
            }

            $row->save();

            $stats['processed']++;
            $stats['bytes'] += $stored->size;

            $this->output->write('.');
        } catch (Throwable $e) {
            // Одна битая строка не должна отменять проход по тысяче целых.
            $stats['skipped']++;

            $this->newLine();
            $this->warn("Пропущено {$row->path}: {$e->getMessage()}");

            Log::warning('[images:restamp] запись пропущена', [
                'model' => $row::class,
                'id' => $row->getKey(),
                'path' => $row->path,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
