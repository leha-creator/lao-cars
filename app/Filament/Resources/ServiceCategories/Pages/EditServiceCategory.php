<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceCategories\Pages;

use App\Enums\ServicePage;
use App\Filament\Resources\ServiceCategories\Actions\DeleteServiceCategoryAction;
use App\Filament\Resources\ServiceCategories\ServiceCategoryResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditServiceCategory extends EditRecord
{
    protected static string $resource = ServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Именно защищённое действие: голый DeleteAction здесь дал бы
            // 500 на категории с позициями и пустую посадочную страницу
            // на последней категории запчастей — в обход проверок из списка.
            DeleteServiceCategoryAction::make(),
        ];
    }

    /**
     * Смена страницы у последней категории запчастей — тот же отказ,
     * что удаление, другим путём.
     *
     * Без этой проверки администратор обходит запрет удаления, просто
     * переключив выпадающий список: категория остаётся, а посадочная
     * страница подбора запчастей теряет единственный блок.
     */
    protected function beforeSave(): void
    {
        $page = $this->data['page'] ?? null;

        // Состояние формы приходит значением енама, а не объектом:
        // сравнение идёт со `->value`, а не через `instanceof`.
        if ($page === ServicePage::Parts->value) {
            return;
        }

        if (! $this->record->isOnlyPartsCategory()) {
            return;
        }

        Notification::make()
            ->title('Страницу нельзя сменить')
            ->body('Это последняя категория страницы «Запчасти» — страница подбора запчастей останется без единого блока. Сначала заведите другую категорию этой страницы.')
            ->danger()
            ->send();

        $this->halt();
    }
}
