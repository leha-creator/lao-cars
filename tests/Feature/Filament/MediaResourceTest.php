<?php

/*
 * Медиабиблиотека в админке (веха 3.4, дополнена вехой 3.5).
 *
 * Веха 3.4 проверяла само хранилище: список, загрузку через
 * ImageProcessor, правку подписей и удаление вместе с файлами на диске.
 * Веха 3.5 добавила потребителей, а с ними — проверку использования
 * перед удалением и отказ от массового удаления.
 */

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Employee;
use App\Models\Media;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageProcessor;
use Filament\Actions\DeleteAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('shows media in the list', function () {
    $media = Media::factory()->count(3)->create();

    livewire(ListMedia::class)
        ->assertOk()
        ->assertCanSeeTableRecords($media);
});

it('has no create page: a library record without a file is meaningless', function () {
    expect(MediaResource::getPages())
        ->toHaveKeys(['index', 'edit'])
        ->not->toHaveKey('create');
});

it('creates records from an upload, converting files to webp with a thumbnail', function () {
    Storage::fake('public');

    // По имени, а не по классу: UploadMediaAction — фабрика, а не
    // наследник Action, и в таблице действие зарегистрировано как `upload`.
    livewire(ListMedia::class)
        ->callAction('upload', [
            'files' => [
                UploadedFile::fake()->image('Промо баннер.png', 1200, 800),
            ],
        ]);

    $media = Media::query()->sole();

    // Имя — исходное без расширения: по нему библиотека и ищется,
    // а путь состоит из случайного идентификатора.
    expect($media->name)->toBe('Промо баннер')
        ->and($media->mime)->toBe('image/webp')
        ->and($media->path)->toEndWith('.webp')
        ->and($media->thumb_path)->toBe(app(ImageProcessor::class)->thumbPathFor($media->path));

    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists($media->thumb_path);
});

it('edits the name and alt text', function () {
    $media = Media::factory()->create(['name' => 'старое', 'alt' => null]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->fillForm(['name' => 'новое', 'alt' => 'Автомобиль на фоне салона'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh())
        ->name->toBe('новое')
        ->alt->toBe('Автомобиль на фоне салона');
});

it('deletes the record together with its files on disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/x.webp', 'original');
    Storage::disk('public')->put('media/thumbs/x.webp', 'thumb');

    $media = Media::factory()->create([
        'path' => 'media/x.webp',
        'thumb_path' => 'media/thumbs/x.webp',
    ]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();

    Storage::disk('public')->assertMissing('media/x.webp');
    Storage::disk('public')->assertMissing('media/thumbs/x.webp');
});

it('reports where a record is used', function () {
    $media = Media::factory()->create();

    expect($media->usages())->toBe([]);

    Employee::factory()->for($media)->create(['name' => 'Андрей Волков']);
    Review::factory()->for($media)->create(['author_name' => 'Ольга Ким']);
    Setting::set('home.promo', ['title' => 'Промо', 'image_id' => $media->getKey()]);

    expect($media->usages())->toBe([
        'Сотрудник: Андрей Волков',
        'Отзыв: Ольга Ким',
        'Настройки: промо-блок на главной',
    ]);
});

it('reports a record used inside a settings repeater', function () {
    // Ссылка ВНУТРИ репитера — та, на которой прежний разбор пути молчал:
    // `home.steps.*.image_id` он делил по последней точке и спрашивал
    // настройку `home.steps.*`, которой не существует. Сравнение не
    // срабатывало никогда, и файл считался свободным.
    $media = Media::factory()->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Подбор', 'text' => 'Текст.'],
        ['number' => '02', 'title' => 'Проверка', 'text' => 'Текст.', 'image_id' => $media->getKey()],
    ]);

    expect($media->usages())->toBe(['Настройки: этапы покупки на главной']);
});

it('names a record used in two steps at once only once', function () {
    // Одна картинка в двух этапах — это по-прежнему ОДНО место
    // использования: перечень отвечает на вопрос «где», а не «сколько раз».
    $media = Media::factory()->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Подбор', 'image_id' => $media->getKey()],
        ['number' => '02', 'title' => 'Проверка', 'image_id' => $media->getKey()],
    ]);

    expect($media->usages())->toBe(['Настройки: этапы покупки на главной']);
});

it('leaves a record free when repeater steps reference other images', function () {
    // Обратная сторона: подстановочный знак не должен объявлять занятым
    // любой файл при любом заполненном этапе.
    $media = Media::factory()->create();
    $other = Media::factory()->create();

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Подбор', 'image_id' => $other->getKey()],
        ['number' => '02', 'title' => 'Проверка', 'image_id' => null],
    ]);

    expect($media->usages())->toBe([]);
});

it('cancels deletion of a record used only inside a settings repeater', function () {
    // Главный сторож задачи: без починки разбора пути этот файл удалялся
    // бы молча, оставляя в jsonb висячий id без внешнего ключа —
    // восстановить связь было бы нечем.
    Storage::fake('public');
    Storage::disk('public')->put('media/step.webp', 'original');

    $media = Media::factory()->create(['path' => 'media/step.webp']);

    Setting::set('home.steps', [
        ['number' => '01', 'title' => 'Подбор', 'image_id' => $media->getKey()],
    ]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists('media/step.webp');
});

it('cancels deletion of a record that is still in use', function () {
    // Блокировка, а не предупреждение: у сотрудников и отзывов есть
    // nullOnDelete(), а ссылка внутри jsonb настроек внешнего ключа
    // не имеет и стала бы висячим id.
    Storage::fake('public');
    Storage::disk('public')->put('media/used.webp', 'original');

    $media = Media::factory()->create(['path' => 'media/used.webp']);
    Employee::factory()->for($media)->create();

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Media::query()->whereKey($media->id)->exists())->toBeTrue();
    Storage::disk('public')->assertExists('media/used.webp');
});

it('deletes a record once it is no longer used', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/freed.webp', 'original');

    $media = Media::factory()->create(['path' => 'media/freed.webp']);
    $employee = Employee::factory()->for($media)->create();

    $employee->update(['media_id' => null]);

    livewire(EditMedia::class, ['record' => $media->getRouteKey()])
        ->callAction(DeleteAction::class);

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
    Storage::disk('public')->assertMissing('media/freed.webp');
});

it('offers no bulk deletion', function () {
    // DeleteBulkAction удаляет записи мимо before() и обошёл бы проверку
    // использования целиком — та же причина, по которой его нет у марок.
    //
    // Проверяется набор массовых действий таблицы, а не имя действия:
    // `delete` есть и у строкового действия, и по имени эти два
    // не различить.
    Media::factory()->count(2)->create();

    $table = livewire(ListMedia::class)->assertOk()->instance()->getTable();

    expect($table->getFlatBulkActions())->toBe([]);
});
