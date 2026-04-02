# План реализации: Production Architecture Overhaul

## Обзор

Инкрементальное усиление архитектуры платформы ANORA. Порядок задач определён зависимостями: сначала фундаментальные компоненты (логгер, Redis-клиент), затем зависимые сервисы (JWT, кэш, очереди), далее WebSocket, API Gateway, партиционирование, read replicas, и в конце Docker-контейнеризация.

Стек: PHP 8.4 (backend), Node.js (WebSocket), nginx (API Gateway), Redis, MySQL 8, Docker.

## Задачи

- [x] 1. Structured Logger — базовый компонент логирования
  - [x] 1.1 Создать `backend/includes/structured_logger.php` — класс `StructuredLogger`
    - Реализовать методы: debug(), info(), warning(), error(), critical()
    - JSON-формат вывода: timestamp (ISO 8601), level, message, context, source, data
    - Фильтрация по минимальному уровню через переменную окружения `LOG_LEVEL`
    - Вывод в stdout/stderr (stderr для error/critical)
    - Автоматическое определение source (файл:строка) через debug_backtrace()
    - Поддержка request_id из заголовка X-Request-ID
    - Поддержка audit-логов с полями: action, user_id, ip_address, user_agent, result
    - Stack trace для уровней error/critical при передаче Exception
    - _Требования: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7_

  - [x]* 1.2 Property-тест P15: Structured log JSON format
    - **Property 15: Structured log JSON format with required fields**
    - Создать `backend/tests/StructuredLoggerPropertyTest.php`
    - Для любого сообщения и уровня — вывод валидный JSON с полями: timestamp, level, message, context, source
    - **Validates: Requirements 6.1**

  - [x]* 1.3 Property-тест P16: Log level filtering
    - **Property 16: Log level filtering**
    - Для любой комбинации уровня сообщения L и минимального уровня M — сообщение выводится тогда и только тогда, когда severity(L) >= severity(M)
    - **Validates: Requirements 6.2**

  - [x]* 1.4 Property-тест P17: Domain-specific log entries
    - **Property 17: Domain-specific log entries contain required fields**
    - Финансовые логи содержат: user_id, type, amount, direction, balance_after, reference_id
    - Audit-логи содержат: action, user_id, ip_address, user_agent, result
    - **Validates: Requirements 6.3, 6.7**

  - [x]* 1.5 Property-тест P18: Error logs include stack trace
    - **Property 18: Error logs include stack trace**
    - Для любого Exception на уровне error/critical — JSON содержит поле trace с непустой строкой
    - **Validates: Requirements 6.4**

  - [x]* 1.6 Property-тест P19: Request ID propagation
    - **Property 19: Request ID propagation in logs**
    - Для любого HTTP-контекста с X-Request-ID = V — все лог-записи содержат context.request_id = V
    - **Validates: Requirements 6.6**

- [x] 2. Redis Client и Queue Service — фундамент для очередей и кэша
  - [x] 2.1 Создать `backend/includes/redis_client.php` — singleton-обёртка над phpredis
    - Подключение к Redis через переменные окружения (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD)
    - Graceful degradation: при недоступности Redis — логирование warning, возврат null/false
    - Методы: getInstance(), getConnection(), isAvailable(), ping()
    - Интеграция со StructuredLogger для логирования ошибок подключения
    - _Требования: 3.5_

  - [x] 2.2 Создать `backend/includes/queue_service.php` — класс `QueueService`
    - Методы: addTask(stream, payload), readTasks(stream, group, consumer, count, blockMs), ack(stream, group, messageId), claimPending(stream, group, consumer, minIdleMs)
    - Создание consumer group при первом вызове (XGROUP CREATE ... MKSTREAM)
    - Consumer name: {hostname}-{pid}
    - _Требования: 2.1, 2.3, 2.4_

  - [x]* 2.3 Property-тест P10: Redis unavailability graceful degradation
    - **Property 10: Redis unavailability graceful degradation**
    - Создать `backend/tests/GracefulDegradationPropertyTest.php`
    - При недоступности Redis — cache read возвращает fallback на MySQL, без unhandled exception
    - **Validates: Requirements 3.5**

- [x] 3. Redis Cache Service — кэширование и rate limiting
  - [x] 3.1 Создать `backend/includes/cache_service.php` — класс `CacheService`
    - Методы: get(key), set(key, value, ttl), delete(key), exists(key), increment(key, ttl)
    - Кэш game state: ключ `game:state:{room}`, TTL 5s
    - Кэш admin dashboard: ключ `admin:dashboard`, TTL 30s
    - Rate limiting: ratelimit:bet:{user_id} (60s), ratelimit:bet_sec:{user_id} (1s), ratelimit:deposit:{user_id} (3600s), ratelimit:withdraw:{user_id} (3600s)
    - Blacklist: blacklist:user:{user_id}
    - Graceful degradation при недоступности Redis
    - _Требования: 3.1, 3.2, 3.3, 3.4, 3.5_

  - [x]* 3.2 Property-тест P8: Cache invalidation on state change
    - **Property 8: Cache invalidation on state change**
    - Создать `backend/tests/EventPublishingPropertyTest.php`
    - При изменении состояния раунда — ключ game:state:{room} удаляется, EXISTS возвращает 0
    - **Validates: Requirements 3.2**

  - [x]* 3.3 Property-тест P9: Rate limit counter accuracy
    - **Property 9: Rate limit counter accuracy**
    - Создать `backend/tests/RateLimitPropertyTest.php`
    - Для N инкрементов — значение счётчика = N; после TTL — ключ не существует
    - **Validates: Requirements 3.4**

- [x] 4. Checkpoint — базовые компоненты
  - Убедиться, что все тесты проходят. Задать вопросы пользователю, если возникли неясности.

- [x] 5. JWT Authentication — замена PHP-сессий
  - [x] 5.1 Создать таблицу `refresh_tokens` — миграция БД
    - SQL: CREATE TABLE refresh_tokens (id, token_hash, user_id, family_id, device_fingerprint, expires_at, revoked_at, created_at) с индексами
    - Создать файл `backend/migrations/add_refresh_tokens.php` или добавить в database.sql
    - _Требования: 1.5_

  - [x] 5.2 Создать `backend/includes/jwt_service.php` — класс `JwtService`
    - Методы: encode(userId, role), decode(token), refresh(refreshToken), revokeFamily(userId, familyId), isBlacklisted(userId)
    - HS256 подпись, секрет из переменной окружения JWT_SECRET
    - Access token: 15 минут TTL, payload: sub, role, iat, exp, jti
    - Refresh token: random 64 bytes, хранение hash в БД, family_id для replay detection
    - Rotation: при refresh — revoke старый, выпустить новый с тем же family_id
    - Replay detection: если revoked refresh token используется повторно — инвалидация всей семьи
    - Blacklist check через Redis: blacklist:user:{user_id}
    - _Требования: 1.1, 1.2, 1.3, 1.4, 1.6, 1.7_

  - [x] 5.3 Создать `backend/includes/auth_middleware.php` — замена auth.php
    - Функции: requireAuth(), requireAdmin() — JWT-based замена requireLogin()/requireAdmin()
    - Извлечение user_id и role из JWT payload без обращения к БД
    - Проверка blacklist через Redis
    - Обратная совместимость: существующие API-эндпоинты продолжают работать
    - _Требования: 1.2, 1.6_

  - [x] 5.4 Обновить API-эндпоинты аутентификации
    - `backend/api/auth/login.php` — возвращать access_token + refresh_token вместо session
    - `backend/api/auth/logout.php` — revoke refresh token
    - Создать `backend/api/auth/refresh.php` — endpoint для refresh token rotation
    - Обновить `backend/api/auth/me.php` — использовать JWT вместо $_SESSION
    - _Требования: 1.1, 1.3_

  - [x]* 5.5 Property-тест P1: JWT encode/decode round-trip
    - **Property 1: JWT encode/decode round-trip**
    - Создать `backend/tests/JwtServicePropertyTest.php`
    - Для любого user_id и role — encode → decode возвращает те же значения, exp = iat + 900
    - **Validates: Requirements 1.1, 1.2**

  - [x]* 5.6 Property-тест P2: Refresh token rotation
    - **Property 2: Refresh token rotation invalidates old token**
    - После refresh — старый токен revoked_at != null, повторное использование → HTTP 401
    - **Validates: Requirements 1.3**

  - [x]* 5.7 Property-тест P3: Replay attack invalidates family
    - **Property 3: Replay attack invalidates entire token family**
    - При повторном использовании revoked refresh token — все токены с тем же family_id инвалидированы
    - **Validates: Requirements 1.4**

  - [x]* 5.8 Property-тест P4: Blacklisted user tokens rejected
    - **Property 4: Blacklisted user tokens are rejected**
    - Для user_id в Redis blacklist — JWT validation возвращает failure
    - **Validates: Requirements 1.6**

  - [x]* 5.9 Property-тест P5: JWT signature tamper detection
    - **Property 5: JWT signature tamper detection**
    - Модификация любого символа в payload → validation failure
    - **Validates: Requirements 1.7**

- [x] 6. Checkpoint — JWT аутентификация
  - Убедиться, что все тесты проходят. Задать вопросы пользователю, если возникли неясности.

- [x] 7. WebSocket Server — real-time события
  - [x] 7.1 Создать `websocket/package.json` и `websocket/server.js`
    - Зависимости: ws, ioredis, jsonwebtoken
    - JWT-аутентификация при handshake (query param token)
    - Подписка на Redis Pub/Sub каналы: game:finished, bet:placed, admin:events
    - Каналы WebSocket: game:{room} (1, 10, 100), admin:live
    - Лимиты подключений: 1000 на game:{room}, 50 на admin:live
    - Cleanup подписок при disconnect
    - Формат событий: JSON {event, data}
    - Blacklist check через Redis при подключении
    - _Требования: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7_

  - [x] 7.2 Интегрировать публикацию событий в PHP backend
    - В `backend/api/game/bet.php` — PUBLISH bet:placed после размещения ставки
    - В `backend/game_worker.php` (после переработки) — PUBLISH game:finished после завершения раунда
    - Инвалидация кэша game:state:{room} при каждом событии
    - _Требования: 2.6, 4.4, 4.5, 3.2_

  - [x]* 7.3 Property-тест P7: State change events payload
    - **Property 7: State change events published with correct payload**
    - Добавить в `backend/tests/EventPublishingPropertyTest.php`
    - Каждое событие содержит: round_id (positive int), room (1/10/100), event-specific fields
    - **Validates: Requirements 2.2, 2.6, 4.4**

  - [x]* 7.4 Property-тест P11: WebSocket JWT authentication
    - **Property 11: WebSocket JWT authentication**
    - Создать `backend/tests/WebSocketPropertyTest.php`
    - Валидный JWT → подключение принято; невалидный/expired/blacklisted → close code 4001
    - **Validates: Requirements 4.1**

  - [x]* 7.5 Property-тест P12: Event routing to correct room
    - **Property 12: Event routing to correct room subscribers**
    - Событие game:{room} → получают только подписчики этой комнаты
    - **Validates: Requirements 4.2**

  - [x]* 7.6 Property-тест P13: WebSocket connection cleanup
    - **Property 13: WebSocket connection cleanup on disconnect**
    - После disconnect — клиент удалён из всех subscription sets
    - **Validates: Requirements 4.6**

  - [x]* 7.7 Property-тест P14: WebSocket connection limits
    - **Property 14: WebSocket connection limits enforcement**
    - При достижении лимита — следующее подключение отклонено, count не превышает лимит
    - **Validates: Requirements 4.7**

- [x] 8. Game Worker Overhaul — Redis Streams вместо while(true)
  - [x] 8.1 Переработать `backend/game_worker.php` — Redis Streams архитектура
    - Заменить while(true) + MySQL polling на XREADGROUP BLOCK 5000
    - Consumer name: {hostname}-{pid}
    - Цикл: XREADGROUP → finishRound() → XACK → PUBLISH game:finished
    - Heartbeat: SETEX worker:{name}:heartbeat 30 alive каждые 10 секунд
    - Graceful shutdown: pcntl_signal(SIGTERM) → завершить текущую задачу → XACK → exit
    - Логирование метрик через StructuredLogger: обработанные раунды, время обработки, ошибки
    - _Требования: 2.1, 2.3, 2.5, 2.6, 9.1, 9.2, 9.3, 9.4, 9.6_

  - [x] 8.2 Создать `backend/cron/worker_recovery.php` — dead worker recovery
    - Проверка heartbeat ключей worker:*:heartbeat
    - Если heartbeat отсутствует > 30s → XCLAIM pending задач мёртвого воркера
    - Логирование recovery через StructuredLogger
    - _Требования: 2.4, 9.5_

  - [x] 8.3 Обновить `backend/api/game/bet.php` — добавить XADD в Redis Stream
    - При переходе раунда в 'spinning' — XADD game:rounds {round_id, room, timestamp}
    - Сохранить существующую логику GameEngine без изменений
    - _Требования: 2.2_

  - [x]* 8.4 Property-тест P6: Game worker idempotent processing
    - **Property 6: Game worker idempotent processing**
    - Создать `backend/tests/GameWorkerIdempotencyPropertyTest.php`
    - Двойной вызов finishRound() → ровно один набор ledger entries
    - **Validates: Requirements 2.5**

  - [x]* 8.5 Property-тест P25: Dead worker detection and task reclaim
    - **Property 25: Dead worker detection and task reclaim**
    - Создать `backend/tests/WorkerHealthPropertyTest.php`
    - Expired heartbeat + pending messages → XCLAIM переносит задачи к активному воркеру
    - **Validates: Requirements 9.5**

- [x] 9. Checkpoint — WebSocket и Game Worker
  - Убедиться, что все тесты проходят. Задать вопросы пользователю, если возникли неясности.

- [x] 10. API Gateway — nginx конфигурация
  - [x] 10.1 Создать `docker/nginx/nginx.conf` — полная конфигурация API Gateway
    - Маршрутизация: /api/* → php-fpm:9000, /ws/* → websocket:8080, /* → static files
    - Rate limiting: limit_req_zone $binary_remote_addr, 100 req/min для неаутентифицированных
    - X-Request-ID: генерация через $request_id
    - CORS: централизованная обработка (Access-Control-Allow-Origin, Methods, Headers)
    - WebSocket proxy: upgrade headers для /ws/*
    - Таймаут upstream: 10s, HTTP 504 с JSON {"error": "Service unavailable"}
    - JSON access log format: timestamp, method, path, status, response_time, client_ip, request_id
    - _Требования: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7_

  - [x] 10.2 Удалить/пометить как deprecated `backend/includes/cors.php`
    - CORS теперь обрабатывается в nginx
    - Обновить API-эндпоинты, убрав include cors.php
    - _Требования: 5.4_

- [x] 11. Partition Manager — партиционирование БД
  - [x] 11.1 Создать `backend/cron/partition_manager.php`
    - Партиционирование ledger_entries и game_bets по RANGE COLUMNS(created_at), интервал 1 месяц
    - Автоматическое создание партиций на 3 месяца вперёд
    - Архивация партиций старше 12 месяцев (с логированием, без автоудаления)
    - Имена партиций: p{YYYY}_{MM}
    - Обработка ошибок: skip если партиция уже существует, error-лог при DDL ошибке
    - Логирование через StructuredLogger
    - _Требования: 7.1, 7.2, 7.3, 7.4, 7.5_

  - [x] 11.2 Создать миграцию для партиционирования существующих таблиц
    - `backend/migrations/partition_tables.php`
    - ALTER TABLE ledger_entries — DROP FK, PARTITION BY RANGE COLUMNS(created_at)
    - ALTER TABLE game_bets — DROP FK, PARTITION BY RANGE COLUMNS(created_at)
    - Application-level enforcement вместо FK constraints (уже существует в LedgerService и GameEngine)
    - _Требования: 7.5, 7.6_

  - [x]* 11.3 Property-тест P20: Partition boundary generation
    - **Property 20: Partition boundary generation**
    - Создать `backend/tests/PartitionManagerPropertyTest.php`
    - Для любой даты D — партиция покрывает ровно 1 месяц, имя p{YYYY}_{MM}, граница = первый день следующего месяца
    - **Validates: Requirements 7.1, 7.2**

  - [x]* 11.4 Property-тест P21: Partition lifecycle date calculations
    - **Property 21: Partition lifecycle date calculations**
    - Для любой даты D — создаются партиции D, D+1, D+2, D+3; архивируются партиции старше D-12; множества не пересекаются
    - **Validates: Requirements 7.3, 7.4**

- [x] 12. Read Replicas — PDO split
  - [x] 12.1 Обновить `backend/config/db.php` — два PDO-подключения
    - $pdo_write (master), $pdo_read (replica) из переменных окружения
    - Fallback: если replica недоступна → $pdo_read = $pdo_write + warning в лог
    - Replication lag check: SHOW SLAVE STATUS → Seconds_Behind_Master > 5 → критические SELECT на master
    - Обратная совместимость: $pdo = $pdo_write
    - _Требования: 8.1, 8.4, 8.5, 8.6_

  - [x] 12.2 Обновить admin API-эндпоинты — использовать $pdo_read
    - `backend/api/admin/finance_dashboard.php`, `games_analytics.php`, `ledger.php`, `transactions.php`, `users.php` — SELECT на $pdo_read
    - Финансовые операции (ledger, payouts) остаются на $pdo_write
    - _Требования: 8.2, 8.3_

  - [x]* 12.3 Property-тест P22: Query routing by operation type
    - **Property 22: Query routing by operation type**
    - Создать `backend/tests/QueryRoutingPropertyTest.php`
    - Financial writes → $pdo_write, admin reads → $pdo_read
    - **Validates: Requirements 8.2, 8.3**

  - [x]* 12.4 Property-тест P23: Read replica fallback on failure
    - **Property 23: Read replica fallback on failure**
    - Добавить в `backend/tests/GracefulDegradationPropertyTest.php`
    - При PDOException на $pdo_read — retry на $pdo_write, результат валиден
    - **Validates: Requirements 8.4**

  - [x]* 12.5 Property-тест P24: Replication lag routing
    - **Property 24: Replication lag routing**
    - Добавить в `backend/tests/QueryRoutingPropertyTest.php`
    - Lag > 5s → критические SELECT на $pdo_write; lag <= 5s → на $pdo_read
    - **Validates: Requirements 8.6**

- [x] 13. Checkpoint — API Gateway, партиционирование, read replicas
  - Убедиться, что все тесты проходят. Задать вопросы пользователю, если возникли неясности.

- [x] 14. Docker-контейнеризация — финальная сборка
  - [x] 14.1 Создать `.env.example` — шаблон переменных окружения
    - DB_WRITE_HOST, DB_READ_HOST, DB_USER, DB_PASS, DB_NAME
    - REDIS_HOST, REDIS_PORT, REDIS_PASSWORD
    - JWT_SECRET
    - NOWPAYMENTS_API_KEY, NOWPAYMENTS_IPN_SECRET
    - LOG_LEVEL
    - WS_PORT
    - _Требования: 10.5_

  - [x] 14.2 Создать `docker/php-fpm/Dockerfile` — PHP 8.4-fpm
    - Базовый образ: php:8.4-fpm
    - Расширения: pdo_mysql, redis, pcntl
    - Копирование backend/ в контейнер
    - Composer install
    - _Требования: 10.1_

  - [x] 14.3 Создать `docker/game-worker/Dockerfile` — PHP 8.4-cli
    - Базовый образ: php:8.4-cli
    - Расширения: pdo_mysql, redis, pcntl
    - CMD: php game_worker.php
    - _Требования: 10.2_

  - [x] 14.4 Создать `docker/nginx/Dockerfile` — nginx с конфигурацией
    - Копирование nginx.conf из задачи 10.1
    - Копирование frontend build (static files)
    - _Требования: 10.3_

  - [x] 14.5 Создать `docker/websocket/Dockerfile` — Node.js WebSocket
    - Базовый образ: node:20-alpine
    - npm install, CMD: node server.js
    - _Требования: 10.3_

  - [x] 14.6 Создать `docker-compose.yml` — оркестрация всех сервисов
    - Сервисы: nginx, php-fpm, game-worker (2 реплики), redis, mysql, websocket
    - Volumes: mysql_data, redis_data, logs
    - Networks: internal network для сервисов
    - Healthchecks для каждого сервиса
    - Переменные окружения из .env
    - _Требования: 10.3, 10.4, 10.6_

  - [x] 14.7 Создать `backend/migrations/init.php` — автоматическое применение миграций при старте
    - Проверка и создание таблицы refresh_tokens
    - Проверка и создание таблицы audit_logs
    - Запуск из docker entrypoint
    - _Требования: 10.6_

- [x] 15. Финальный checkpoint — полная интеграция
  - Убедиться, что все тесты проходят. Задать вопросы пользователю, если возникли неясности.

## Примечания

- Задачи с `*` — опциональные (property-based и unit-тесты), можно пропустить для ускорения MVP
- Каждая задача ссылается на конкретные требования для трассируемости
- Property-тесты валидируют универсальные свойства корректности из дизайн-документа
- Checkpoints обеспечивают инкрементальную валидацию на каждом этапе
- Существующие механизмы (ledger idempotency, FOR UPDATE, HMAC-SHA512, anti-fraud) сохраняются без изменений
