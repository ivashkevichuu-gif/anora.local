# План реализации: OAuth Social Login

## Обзор

Поэтапная реализация OAuth 2.0 / OpenID Connect авторизации через Google и Apple для платформы ANORA. Задачи упорядочены по зависимостям: миграция БД → конфигурация → сервис → эндпоинты → фронтенд → интеграция.

## Задачи

- [x] 1. Миграция базы данных и конфигурация
  - [x] 1.1 Создать миграцию `backend/migrations/add_oauth_accounts.php`
    - Создать таблицу `oauth_accounts` с колонками: `id`, `user_id`, `provider` (ENUM: google, apple), `provider_user_id`, `provider_email`, `created_at`
    - Уникальный составной индекс на (`provider`, `provider_user_id`)
    - Внешний ключ `user_id` → `users.id` с каскадным удалением
    - Индекс на `user_id` и составной индекс на (`provider`, `provider_email`)
    - ALTER TABLE `users` — сделать `password` DEFAULT '' для OAuth-only пользователей
    - Миграция должна быть идемпотентной (`CREATE TABLE IF NOT EXISTS`)
    - _Требования: 1.1, 1.2, 1.3, 1.4, 13.1, 13.2, 13.3_

  - [x] 1.2 Обновить `database.sql` — добавить таблицу `oauth_accounts`
    - Добавить CREATE TABLE `oauth_accounts` после таблицы `users`
    - _Требования: 1.1, 1.2, 1.3, 1.4_

  - [x] 1.3 Создать конфигурацию `backend/config/oauth.php`
    - Массив конфигурации для Google и Apple из env vars
    - Google: `client_id`, `client_secret`, `auth_url`, `token_url`, `jwks_url`, `issuer`, `scope`
    - Apple: `client_id`, `team_id`, `key_id`, `private_key_path`, `auth_url`, `token_url`, `jwks_url`, `issuer`, `scope`
    - `redirect_uri` из `OAUTH_REDIRECT_URI`
    - _Требования: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7_

  - [x] 1.4 Обновить `.env.example` — добавить OAuth переменные
    - Добавить `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID`, `APPLE_PRIVATE_KEY_PATH`, `OAUTH_REDIRECT_URI`, `FRONTEND_URL`
    - _Требования: 8.1–8.7_


- [x] 2. Реализация OAuthService
  - [x] 2.1 Создать `backend/includes/oauth_service.php` — класс OAuthService
    - Конструктор принимает массив конфигурации
    - Метод `getAuthorizationUrl(string $provider, string $state, string $nonce): string` — генерация Authorization URL с обязательными параметрами (`response_type`, `client_id`, `redirect_uri`, `scope`, `state`, `nonce`; для Apple — `response_mode=form_post`)
    - Метод `exchangeCode(string $provider, string $code): array` — обмен authorization code на токены через server-to-server POST
    - Метод `verifyIdToken(string $provider, string $idToken, string $expectedNonce): array` — верификация подписи через JWKS (RS256, `openssl_verify`), проверка claims (`iss`, `aud`, `exp`, `nonce`), возврат `{sub, email, name}`
    - Метод `fetchJwks(string $provider): array` — получение JWKS с кешированием (файл/Redis, TTL 24h)
    - Метод `generateAppleClientSecret(): string` — генерация JWT (ES256) для Apple client_secret
    - Метод `findOrCreateUser(PDO $pdo, string $provider, array $claims, string $ip): array` — поиск по `provider_user_id` в `oauth_accounts`, затем по email в `users`, создание нового пользователя при необходимости
    - Валидация обязательных env vars с HTTP 500 и логированием при отсутствии
    - Все URL провайдеров должны использовать HTTPS
    - Логирование через StructuredLogger
    - _Требования: 2.1–2.5, 3.1–3.11, 4.1–4.6, 5.1–5.5, 6.1–6.4, 7.1–7.3, 8.8, 9.1, 9.2, 9.5_

  - [ ]* 2.2 Property-тест: уникальность связки провайдер + provider_user_id
    - **Property 1: Уникальность связки провайдер + provider_user_id**
    - **Validates: Требование 1.2**

  - [ ]* 2.3 Property-тест: каскадное удаление OAuth-аккаунтов
    - **Property 2: Каскадное удаление OAuth-аккаунтов**
    - **Validates: Требование 1.3**

  - [ ]* 2.4 Property-тест: длина и уникальность security-токенов
    - **Property 3: Длина и уникальность security-токенов (state, nonce)**
    - **Validates: Требования 2.1, 2.2**

  - [ ]* 2.5 Property-тест: Authorization URL содержит все обязательные параметры
    - **Property 4: Authorization URL содержит все обязательные параметры**
    - **Validates: Требования 2.4, 2.5**

  - [ ]* 2.6 Property-тест: верификация ID Token — отклонение невалидных claims
    - **Property 6: Верификация ID Token — отклонение невалидных claims**
    - **Validates: Требования 3.7, 3.8, 3.9, 3.10, 3.11**

  - [ ]* 2.7 Property-тест: верификация подписи ID Token через JWKS
    - **Property 7: Верификация подписи ID Token через JWKS**
    - **Validates: Требования 3.5, 3.6**

  - [ ]* 2.8 Property-тест: создание нового пользователя через OAuth
    - **Property 8: Создание нового пользователя через OAuth**
    - **Validates: Требования 4.1, 4.2, 4.3, 4.4**

  - [ ]* 2.9 Property-тест: вход существующего OAuth-пользователя
    - **Property 9: Вход существующего OAuth-пользователя**
    - **Validates: Требования 5.1, 5.2, 5.3, 4.5**

  - [ ]* 2.10 Property-тест: отклонение входа для забаненных и бот-аккаунтов
    - **Property 10: Отклонение входа для забаненных и бот-аккаунтов**
    - **Validates: Требования 5.4, 5.5**

  - [ ]* 2.11 Property-тест: автоматическая привязка OAuth по email
    - **Property 11: Автоматическая привязка OAuth по email**
    - **Validates: Требования 6.1, 6.2, 6.3**

  - [ ]* 2.12 Property-тест: верификация пользователя при OAuth-привязке
    - **Property 12: Верификация пользователя при OAuth-привязке**
    - **Validates: Требование 6.4**

  - [ ]* 2.13 Property-тест: идентификация Apple-пользователей по provider_user_id
    - **Property 13: Идентификация Apple-пользователей по provider_user_id**
    - **Validates: Требование 7.3**

  - [ ]* 2.14 Property-тест: ошибка при отсутствии обязательных env vars
    - **Property 15: Ошибка при отсутствии обязательных env vars**
    - **Validates: Требование 8.8**

  - [ ]* 2.15 Property-тест: HTTPS для всех URL провайдеров
    - **Property 19: HTTPS для всех URL провайдеров**
    - **Validates: Требование 9.2**


- [x] 3. Checkpoint — Проверка сервиса и миграции
  - Убедиться, что все тесты проходят, задать вопросы пользователю при необходимости.

- [x] 4. API-эндпоинты OAuth
  - [x] 4.1 Создать `backend/api/auth/oauth_start.php`
    - Принимает GET-параметр `provider` (google | apple)
    - Генерирует `state` (32 байта, `bin2hex(random_bytes(32))`) и `nonce` (32 байта)
    - Сохраняет `state`, `nonce`, `provider` в `$_SESSION`
    - Вызывает `OAuthService::getAuthorizationUrl()` и возвращает HTTP 302 редирект
    - Валидация провайдера, HTTP 400 при неизвестном
    - Проверка env vars, HTTP 500 при отсутствии
    - Логирование через StructuredLogger
    - _Требования: 2.1, 2.2, 2.3, 2.4, 2.5, 8.8, 9.5_

  - [x] 4.2 Создать `backend/api/auth/oauth_callback.php`
    - Принимает GET (Google) и POST (Apple form_post) запросы
    - Валидация `state` против `$_SESSION['oauth_state']`, HTTP 403 при несовпадении
    - Обмен `code` на токены через `OAuthService::exchangeCode()`
    - Верификация `id_token` через `OAuthService::verifyIdToken()`
    - Поиск/создание пользователя через `OAuthService::findOrCreateUser()`
    - Проверка `is_banned` и `is_bot`, HTTP 403 при блокировке
    - Выдача JWT access_token и refresh_token через JwtService
    - Создание PHP-сессии (`$_SESSION['user_id']`)
    - Удаление `oauth_state`, `oauth_nonce`, `oauth_provider` из сессии после обработки
    - Rate limiting: 10 запросов/минуту с одного IP, HTTP 429 при превышении
    - Редирект на `{FRONTEND_URL}/auth/callback?access_token=...&refresh_token=...&is_new=...`
    - При ошибке: редирект на `{FRONTEND_URL}/auth/callback?error=...&message=...`
    - Логирование всех попыток (провайдер, IP, User-Agent)
    - _Требования: 3.1–3.11, 4.1–4.6, 5.1–5.5, 6.1–6.4, 9.1, 9.3, 9.4, 9.5, 9.6_

  - [x] 4.3 Обновить `backend/api/auth/login.php` — отклонение OAuth-only пользователей
    - Добавить проверку: если `password === ''` (пустая строка), вернуть HTTP 401 с сообщением "Используйте вход через Google или Apple"
    - Проверка должна быть ДО вызова `password_verify()`
    - _Требования: 10.1, 10.2, 10.3_

  - [ ]* 4.4 Property-тест: валидация state параметра при callback
    - **Property 5: Валидация state параметра при callback**
    - **Validates: Требования 3.1, 3.2**

  - [ ]* 4.5 Property-тест: удаление state/nonce из сессии после использования
    - **Property 16: Удаление state/nonce из сессии после использования**
    - **Validates: Требование 9.3**

  - [ ]* 4.6 Property-тест: rate limiting OAuth callback
    - **Property 17: Rate limiting OAuth callback**
    - **Validates: Требование 9.4**

  - [ ]* 4.7 Property-тест: отклонение email/password входа для OAuth-only пользователей
    - **Property 14: Отклонение email/password входа для OAuth-only пользователей**
    - **Validates: Требования 10.1, 10.2, 10.3**

  - [ ]* 4.8 Property-тест: идемпотентность миграции
    - **Property 18: Идемпотентность миграции**
    - **Validates: Требование 13.2**

  - [ ]* 4.9 Property-тест: логирование OAuth-попыток
    - **Property 20: Логирование OAuth-попыток**
    - **Validates: Требование 9.5**

- [x] 5. Checkpoint — Проверка бэкенда
  - Убедиться, что все тесты проходят, задать вопросы пользователю при необходимости.


- [x] 6. Фронтенд — компоненты и интеграция
  - [x] 6.1 Создать компонент `frontend/src/components/ui/OAuthButtons.jsx`
    - Кнопки "Sign in with Google" и "Sign in with Apple"
    - Стилизация согласно Google Brand Guidelines и Apple HIG
    - Доступность: `aria-label`, фокус с клавиатуры, контрастность
    - При клике — перенаправление на `/backend/api/auth/oauth_start.php?provider={google|apple}`
    - _Требования: 11.1, 11.2, 11.3, 11.6_

  - [x] 6.2 Создать страницу `frontend/src/pages/OAuthCallback.jsx`
    - Парсинг query-параметров из URL (`access_token`, `refresh_token`, `is_new`, `error`, `message`)
    - При наличии токенов — сохранение в AuthContext, редирект на `/account`
    - При наличии `error` — отображение сообщения об ошибке, редирект на `/login` через 3 секунды
    - Маппинг кодов ошибок на русскоязычные сообщения
    - Индикатор загрузки во время обработки
    - _Требования: 12.1, 12.2, 12.3, 12.4_

  - [x] 6.3 Обновить `frontend/src/context/AuthContext.jsx`
    - Добавить метод `loginWithTokens(accessToken, refreshToken)` — сохранение токенов и загрузка данных пользователя через `authService.getMe()`
    - _Требования: 11.4, 12.2_

  - [x] 6.4 Обновить `frontend/src/api/client.js` и `frontend/src/services/authService.js`
    - Добавить `oauthStart(provider)` в api client — возвращает URL для редиректа
    - Добавить `oauthStart(provider)` в authService
    - _Требования: 11.3_

  - [x] 6.5 Обновить `frontend/src/App.jsx` — добавить маршрут `/auth/callback`
    - Lazy-загрузка `OAuthCallback` компонента
    - Маршрут вне `PublicLayout` (без Navbar/Footer)
    - _Требования: 12.1_

  - [x] 6.6 Обновить `frontend/src/pages/Login.jsx` и `frontend/src/pages/Register.jsx`
    - Добавить `<OAuthButtons />` под формой входа/регистрации
    - Разделитель "или" между формой и кнопками OAuth
    - _Требования: 11.1, 11.2_

- [x] 7. Final checkpoint — Финальная проверка
  - Убедиться, что все тесты проходят, задать вопросы пользователю при необходимости.

## Примечания

- Задачи с `*` — опциональные (property-тесты), можно пропустить для быстрого MVP
- Каждая задача ссылается на конкретные требования для трассируемости
- Checkpoints обеспечивают инкрементальную валидацию
- Property-тесты валидируют универсальные свойства корректности (Properties 1–20 из дизайн-документа)
- Тесты используют PHPUnit + in-memory SQLite, по аналогии с существующими тестами проекта
