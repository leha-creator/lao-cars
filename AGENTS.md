# AGENTS.md

> Структурная карта проекта для AI-агентов и новых разработчиков. Поддерживайте её в актуальном состоянии при значимых изменениях структуры. Только факты о том, что реально существует.

## Обзор проекта

Сайт «ЛАО КАРС» — импорт автомобилей (Китай, Европа и др.) и автосервисные услуги (ТО, ремонт, шиномонтаж, детейлинг). Основной канал получения заявок: каталог авто и страницы услуг ведут к заявке, все заявки собираются в единый список лидов в админке.

**Текущее состояние: собран каркас (веха 3.1).** Laravel поднят, подключены PostgreSQL и Redis, работает админ-панель Filament, настроены сборка фронтенда, тесты и CI. Прикладных моделей, ресурсов админки и публичных страниц ещё нет — это вехи 3.2 и далее.

Подробности — в [.ai-factory/DESCRIPTION.md](.ai-factory/DESCRIPTION.md), план каркаса — в [.ai-factory/plans/project-scaffold.md](.ai-factory/plans/project-scaffold.md).

## Технологический стек

Версии — фактические, из `composer.lock` и `package.json`.

- **Язык программирования:** PHP 8.5.4 локально (Herd); `composer.json` требует `^8.3`
- **Framework:** Laravel 13.23.0
- **База данных:** PostgreSQL 17 (в Docker)
- **ORM:** Eloquent
- **Админ-панель:** Filament 5.7.5 на Livewire 4.3.5, путь `/admin`
- **Frontend:** Blade + Alpine.js 3 + Tailwind CSS 4, сборка Vite 8, Node 24
- **Очереди и кеш:** Redis 7 (в Docker), клиент `predis` — расширения phpredis нет ни локально, ни в образах CI
- **Тесты:** Pest 4.7.7 с плагинами `laravel` и `livewire`
- **CI:** GitHub Actions — Pint и тесты
- **Интеграции (MVP):** Telegram-бот для уведомлений о заявках (веха 3.7)

## Запуск окружения

В Docker живут только базы: PHP и Node запускаются локально через Herd — bind mount исходников в контейнер на Windows заметно бьёт по I/O.

```bash
docker compose up -d      # PostgreSQL на 5432, Redis на 56379
php artisan migrate
npm run dev               # или npm run build
php artisan serve
php artisan queue:work    # обработка очереди заявок
```

Тесты идут в реальные PostgreSQL и Redis (база `laocars_testing`, `REDIS_DB=1`), поэтому контейнеры должны быть подняты:

```bash
php artisan test
php vendor/bin/pint --test
```

Полный сброс окружения — `docker compose down -v`: init-скрипт, создающий `laocars_testing`, выполняется только на пустом volume.

**Порт Redis смещён на 56379:** штатный 6379 занят нативной службой `redis-server`. Смещение живёт только в `.env`; `.env.example` хранит канонический 6379, потому что из него собирается CI.

## Структура проекта

Стандартный скелет Laravel; ниже — только то, что добавлено или значимо для проекта.

```
laocars/
├── .ai-factory/              # Контекст AI Factory: спецификация, правила, планы, конфиг
│   ├── config.yaml           # Языки (ru/ru), пути артефактов, git-настройки
│   ├── DESCRIPTION.md        # Спецификация проекта и принятые решения по стеку
│   ├── ARCHITECTURE.md       # Layered на конвенциях Laravel
│   ├── ROADMAP.md            # Вехи по этапам ТЗ
│   ├── plans/                # Планы вех
│   └── rules/base.md         # Конвенции кода проекта
├── .claude/
│   ├── agents/               # Определения субагентов AI Factory
│   └── skills/               # Скиллы: встроенные aif-* + установленные под стек
├── .github/workflows/ci.yml  # CI: Pint и тесты на PostgreSQL и Redis
├── app/
│   ├── Models/User.php       # Реализует FilamentUser — доступ в админку
│   └── Providers/Filament/   # AdminPanelProvider: панель /admin, брендинг, локаль
├── config/logging.php        # Канал `leads` — отдельный лог пути заявки
├── docker/postgres/init/     # Init-скрипты Postgres: создание базы laocars_testing
├── resources/
│   ├── css/app.css           # Tailwind v4; @theme пустой — токены в вехе 4.1
│   ├── js/app.js             # Alpine.js
│   └── views/layouts/app.blade.php  # Базовый layout: title, description, canonical
├── tests/
│   ├── Pest.php              # RefreshDatabase для Feature-тестов
│   └── Feature/              # InfrastructureTest, SmokeTest, Filament/AdminPanelTest
├── assets/
│   ├── cars/                 # 46 исходных фото автомобилей (IMG_*.PNG) для каталога
│   └── Макет сайта «ЛАО КАРС»/  # Экспорт макета: десктоп, мобильные, UI Kit
├── compose.yml               # PostgreSQL 17 и Redis 7 — только базы
├── phpunit.xml               # Тестовое окружение: pgsql + redis, а не sqlite и array
├── .mcp.json                 # MCP-серверы: postgres, github, filesystem, playwright, chromeDevtools
├── AGENTS.md                 # Этот файл
└── ТЗ_ЛАО_КАРС.md            # Техническое задание заказчика, версия 1.0
```

Каталоги `app/Enums/`, `app/Services/`, `app/Jobs/`, `app/Policies/` из `ARCHITECTURE.md` появятся вместе с первыми классами в вехах 3.2 и далее — пустых папок с `.gitkeep` в проекте нет.

## Ключевые точки входа

| Файл | Назначение |
| :---- | :---- |
| ТЗ_ЛАО_КАРС.md | Первоисточник требований: разделы сайта, состав админки, сроки, риски |
| .ai-factory/DESCRIPTION.md | Спецификация: стек, решения по развилкам ТЗ, границы MVP |
| .ai-factory/config.yaml | Конфиг AI Factory: языки, пути, git |
| routes/web.php | Публичные маршруты |
| app/Providers/Filament/AdminPanelProvider.php | Конфигурация админ-панели: путь, брендинг, ресурсы |
| compose.yml | Локальные PostgreSQL и Redis |
| phpunit.xml | Тестовое окружение — переопределяет драйверы скелета |
| config/logging.php | Канал `leads`: приём заявки и доставка уведомлений |
| .mcp.json | Конфигурация MCP-серверов проекта |
| assets/cars/ | Исходные фото для наполнения каталога |

## Документация

| Документ | Путь | Описание |
| :---- | :---- | :---- |
| Техническое задание | ТЗ_ЛАО_КАРС.md | Требования заказчика: структура разделов, админка, оценка сроков, риски |
| Спецификация проекта | .ai-factory/DESCRIPTION.md | Стек, архитектурные заметки, что входит и не входит в MVP |
| Правила кода | .ai-factory/rules/base.md | Именование, структура модулей, ошибки, логирование, тесты |
| Роадмап | .ai-factory/ROADMAP.md | Вехи по этапам ТЗ и что уже закрыто |

README в проекте — из поставки Laravel; проектный ещё не написан (`/aif-docs`).

## AI-контекст

| Файл | Назначение |
| :---- | :---- |
| AGENTS.md | Структурная карта проекта — этот файл |
| .ai-factory/DESCRIPTION.md | Что за проект, какой стек и почему, границы MVP |
| .ai-factory/ARCHITECTURE.md | Layered на конвенциях Laravel: структура папок, правила зависимостей, антипаттерны |
| .ai-factory/rules/base.md | Конвенции кода, обязательные к соблюдению |

## Скиллы проекта

| Скилл | Когда применяется |
| :---- | :---- |
| laocars-leads | Заявки: модель Lead, формы, статусы, Telegram-уведомления через очередь |
| laravel-specialist | Модели, миграции, очереди, общие паттерны Laravel |
| filament-pro | Админка: ресурсы, формы, таблицы, виджеты |
| laravel-security | Защита публичных форм, авторизация, роли, загрузка файлов |
| seo | Микроразметка Vehicle/Product, meta-теги, sitemap каталога |
| tailwind-css-patterns | Адаптивная вёрстка |

## Правила для агентов

- **Не объединять shell-команды через `&&`** — выполнять по одной, чтобы сбой был виден и диагностируем.
  - Неправильно: `composer install && php artisan migrate`
  - Правильно: сначала `composer install`, затем отдельно `php artisan migrate`
- **Python в этом окружении — только `py -3`.** Команда `python3` резолвится в заглушку Microsoft Store из `WindowsApps`, которая печатает «Python» и выходит с кодом 49, не выполнив скрипт. Скан безопасности через `python3` даст ложное «успешно».
- **Git инициализирован, базовая ветка `master`** (`git.enabled: true`). Ветки под планы автоматически не создаются (`create_branches: false`) — работа идёт в `master`.
- **Тесты не подменяют драйверы.** `phpunit.xml` намеренно уводит их в реальные PostgreSQL и Redis. Не возвращайте `sqlite`, `array` и `sync`: с ними прогон зеленеет, не проверяя инфраструктуру, а `Queue::size()` перестаёт что-либо значить. По той же причине в проверках очереди не используется `Queue::fake()`.
- **Конфигурация Tailwind — только через `@theme` в `resources/css/app.css`.** `tailwind.config.js` в стиле v3 не создавать: в v4 его нет, и токены вехи 4.1 из него не подхватятся.
- **Внешние шрифты с CDN не подключать** — блок `bunny()` из скелета убран осознанно.
- **Ассеты Filament (`public/css|js|fonts/filament`) в git не попадают** — их публикует `filament:upgrade` на каждом `composer install`.
- **Заявка не должна теряться** — ключевой инвариант проекта: запись лида в БД первична, уведомление вторично. Детали в скилле `laocars-leads`.
- **Русский — в UI, контенте и пояснениях; английский — в коде и доменных терминах** (`Lead`, `Car`, `Service`).
