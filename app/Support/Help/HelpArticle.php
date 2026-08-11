<?php

declare(strict_types=1);

namespace App\Support\Help;

/**
 * Одна статья справки — запись реестра, а не запись в базе.
 *
 * Текст лежит отдельным файлом в `resources/help/`, здесь только его
 * описание: где статья стоит в списке, кому видна и с чем связана.
 */
final readonly class HelpArticle
{
    /**
     * @param  string  $slug  Часть адреса статьи; он же имя файла с текстом.
     * @param  string  $title  Заголовок, который печатает страница (в самом файле заголовка первого уровня нет).
     * @param  string  $summary  Одна фраза под заголовком и на карточке в списке.
     * @param  class-string|null  $gate  Ресурс или страница Filament, у которых спрашивается видимость статьи; `null` — видно всем сотрудникам.
     * @param  list<string>  $related  Слаги связанных статей для блока «См. также».
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public HelpSection $section,
        public ?string $gate = null,
        public array $related = [],
    ) {}

    /**
     * Имя файла с текстом, а НЕ путь к нему.
     *
     * Путь — это диск, а диск живёт в сервисе (`App\Services\HelpContent`):
     * реестр обязан оставаться чистыми данными, иначе `app/Support/`
     * перестаёт быть слоем без внешнего мира.
     */
    public function file(): string
    {
        return "{$this->slug}.md";
    }
}
