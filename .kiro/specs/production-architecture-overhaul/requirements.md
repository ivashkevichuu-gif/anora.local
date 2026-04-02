# Документ требований: Production Architecture Overhaul

## Введение

Платформа ANORA — gambling-платформа (лотерея с provably fair механикой), построенная на PHP 8.4 (без фреймворка, raw PDO) + React 18 + MySQL 8 InnoDB. Текущая архитектура — монолит с уже реализованными ключевыми финансовыми механизмами (ledger, idempotency, FOR UPDATE, HMAC-SHA512 вебхуки, anti-fraud флаги, reconciliation).

Данный документ описывает требования к **инкрементальному усилению архитектуры** — не переписывание с нуля, а систематическое закрытие пробелов: переход на JWT-аутентификацию, внедрение Redis (очереди + кэш), замена PHP-loop воркера на queue-based архитектуру, API Gateway, структурированное логирование, WebSocket для real-time мониторинга, партиционирование БД и подготовка к горизонтальному масштабированию.

## Глоссарий

- **Platform** — серверная часть ANORA (PHP backend + MySQL)
- **Auth_Service** — модуль аутентификации и авторизации (JWT-based)
- **Token_Manager** — компонент Auth_Service, отвечающий за выпуск, валидацию и ротацию JWT-токенов
- **Redis_Broker** — экземпляр Redis, используемый как message broker для очередей задач и pub/sub
- **Redis_Cache** — экземпляр Redis (или логический namespace), используемый для кэширования данных
- **Game_Worker** — фоновый процесс, обрабатывающий игровые раунды из Redis-очереди
- **API_Gateway** — входной слой (nginx-based), маршрутизирующий запросы к backend-сервисам
- **Structured_Logger** — компонент для записи логов в формате JSON с контекстом (user_id, request_id, trace_id)
- **WebSocket_Server** — серверный процесс для push-уведомлений в реальном времени (игровые события, admin-мониторинг)
- **Ledger** — существующая таблица ledger_entries, источник истины для всех балансовых мутаций
- **Admin_Panel** — React-приложение для администрирования платформы
- **Read_Replica** — MySQL read-only реплика для разгрузки SELECT-запросов
- **Partition_Manager** — компонент/скрипт для управления партиционированием таблиц ledger_entries и game_bets

## Требования

### Требование 1: JWT-аутентификация

**User Story:** Как backend-архитектор, я хочу заменить PHP-сессии на JWT-токены, чтобы обеспечить stateless-аутентификацию и подготовить платформу к горизонтальному масштабированию.

#### Критерии приёмки

1. WHEN пользователь успешно проходит логин, THE Auth_Service SHALL выдать пару токенов: access_token (срок жизни 15 минут) и refresh_token (срок жизни 7 дней)
2. WHEN запрос поступает с валидным access_token в заголовке Authorization: Bearer, THE Auth_Service SHALL извлечь user_id и роль из payload без обращения к базе данных
3. WHEN access_token истёк, а refresh_token валиден, THE Token_Manager SHALL выдать новую пару токенов и инвалидировать использованный refresh_token (rotation)
4. IF refresh_token уже был использован повторно (replay attack), THEN THE Token_Manager SHALL инвалидировать все refresh_token данного пользователя и вернуть HTTP 401
5. THE Auth_Service SHALL хранить refresh_token в таблице refresh_tokens с полями: token_hash, user_id, expires_at, revoked_at, device_fingerprint
6. WHEN администратор банит пользователя, THE Auth_Service SHALL добавить user_id в Redis-blacklist, и все последующие запросы с токенами этого пользователя SHALL быть отклонены
7. THE Auth_Service SHALL подписывать JWT с использованием алгоритма HS256 и секретного ключа, хранящегося в конфигурации сервера (не в коде)

### Требование 2: Redis — очереди задач

**User Story:** Как backend-архитектор, я хочу внедрить Redis как message broker для фоновых задач, чтобы заменить PHP while(true) loop на надёжную queue-based архитектуру.

#### Критерии приёмки

1. THE Redis_Broker SHALL использовать Redis Streams (XADD/XREADGROUP) для очередей задач с consumer groups
2. WHEN игровой раунд переходит в статус 'spinning', THE Platform SHALL добавить задачу finish_round в Redis Stream game:rounds с payload {round_id, room, timestamp}
3. WHEN Game_Worker получает задачу из очереди, THE Game_Worker SHALL подтвердить обработку через XACK после успешного завершения
4. IF Game_Worker не подтвердил задачу в течение 60 секунд, THEN THE Redis_Broker SHALL сделать задачу доступной для повторного получения другим воркером (pending entry recovery)
5. THE Game_Worker SHALL обрабатывать задачи идемпотентно: повторная обработка того же round_id не приводит к двойной выплате (существующая защита через payout_status='paid')
6. WHEN Game_Worker завершает обработку раунда, THE Game_Worker SHALL опубликовать событие game:finished в Redis Pub/Sub канал с payload {round_id, winner_id, room}
7. THE Platform SHALL запускать минимум 2 экземпляра Game_Worker в одной consumer group для отказоустойчивости

### Требование 3: Redis — кэширование

**User Story:** Как backend-архитектор, я хочу использовать Redis для кэширования часто запрашиваемых данных, чтобы снизить нагрузку на MySQL.

#### Критерии приёмки

1. THE Redis_Cache SHALL кэшировать текущее состояние игровых раундов (game state) для каждой комнаты с TTL 5 секунд
2. WHEN состояние раунда изменяется (новая ставка, смена статуса, завершение), THE Platform SHALL инвалидировать кэш соответствующей комнаты
3. THE Redis_Cache SHALL кэшировать данные финансового дашборда Admin_Panel с TTL 30 секунд
4. THE Redis_Cache SHALL хранить счётчики rate-limit (ставки, депозиты, выводы) с использованием INCR + EXPIRE вместо текущих COUNT-запросов к MySQL
5. IF Redis недоступен, THEN THE Platform SHALL продолжить работу с прямыми запросами к MySQL (graceful degradation) и записать предупреждение в лог

### Требование 4: WebSocket-сервер для real-time событий

**User Story:** Как backend-архитектор, я хочу внедрить WebSocket-сервер для push-уведомлений, чтобы обеспечить real-time обновления игрового состояния и live-мониторинг в Admin_Panel.

#### Критерии приёмки

1. THE WebSocket_Server SHALL принимать подключения от клиентов и аутентифицировать их по JWT-токену, переданному при handshake
2. WHEN Game_Worker публикует событие в Redis Pub/Sub, THE WebSocket_Server SHALL транслировать событие всем подписанным клиентам соответствующей комнаты
3. THE WebSocket_Server SHALL поддерживать каналы: game:{room} (игровые события), admin:live (мониторинг для администраторов)
4. WHEN новая ставка размещена, THE Platform SHALL опубликовать событие bet:placed в Redis Pub/Sub с payload {round_id, user_id, amount, room}
5. WHEN раунд завершён, THE WebSocket_Server SHALL отправить событие round:finished с данными победителя всем подписчикам комнаты в течение 500 миллисекунд после завершения
6. IF WebSocket-соединение разорвано, THEN THE WebSocket_Server SHALL выполнить cleanup подписок клиента и освободить ресурсы
7. THE WebSocket_Server SHALL ограничивать количество одновременных подключений: 1000 на комнату, 50 на admin:live канал


### Требование 5: API Gateway

**User Story:** Как backend-архитектор, я хочу добавить API Gateway слой на базе nginx, чтобы централизовать маршрутизацию, rate limiting и CORS-обработку.

#### Критерии приёмки

1. THE API_Gateway SHALL маршрутизировать запросы по префиксам: /api/auth/* → Auth_Service, /api/game/* → Game endpoints, /api/account/* → Wallet endpoints, /api/webhook/* → Payment endpoints, /api/admin/* → Admin endpoints
2. THE API_Gateway SHALL применять глобальный rate limit: 100 запросов в минуту на IP-адрес для неаутентифицированных запросов
3. THE API_Gateway SHALL добавлять заголовок X-Request-ID (UUID v4) к каждому входящему запросу для сквозной трассировки
4. THE API_Gateway SHALL обрабатывать CORS-заголовки централизованно, заменив текущую обработку в backend/includes/cors.php
5. IF backend-сервис не отвечает в течение 10 секунд, THEN THE API_Gateway SHALL вернуть HTTP 504 Gateway Timeout с JSON-телом {error: "Service unavailable"}
6. THE API_Gateway SHALL проксировать WebSocket-соединения на /ws/* к WebSocket_Server с поддержкой upgrade-заголовков
7. THE API_Gateway SHALL логировать каждый запрос в формате JSON: timestamp, method, path, status_code, response_time_ms, client_ip, request_id

### Требование 6: Структурированное логирование

**User Story:** Как backend-архитектор, я хочу заменить текущее файловое логирование (error_log) на структурированное JSON-логирование с контекстом, чтобы обеспечить централизованный сбор и анализ логов.

#### Критерии приёмки

1. THE Structured_Logger SHALL записывать каждое лог-сообщение в формате JSON с полями: timestamp (ISO 8601), level (debug/info/warning/error/critical), message, context (user_id, request_id, trace_id), source (имя файла и строка)
2. THE Structured_Logger SHALL поддерживать уровни логирования: debug, info, warning, error, critical — с возможностью фильтрации по минимальному уровню через конфигурацию
3. WHEN финансовая операция выполняется (ledger entry), THE Structured_Logger SHALL записать лог уровня info с полями: user_id, type, amount, direction, balance_after, reference_id
4. WHEN ошибка происходит в критическом пути (платежи, выплаты, игровой движок), THE Structured_Logger SHALL записать лог уровня error с полным stack trace и контекстом операции
5. THE Structured_Logger SHALL записывать логи в stdout/stderr для совместимости с Docker и системами сбора логов (fluentd, filebeat)
6. THE Structured_Logger SHALL включать request_id (из X-Request-ID заголовка API_Gateway) во все лог-записи в рамках одного HTTP-запроса
7. WHEN пользователь выполняет действие, связанное с безопасностью (логин, смена пароля, вывод средств), THE Structured_Logger SHALL записать audit-лог с полями: action, user_id, ip_address, user_agent, result (success/failure)

### Требование 7: Партиционирование базы данных

**User Story:** Как backend-архитектор, я хочу внедрить партиционирование для быстрорастущих таблиц, чтобы обеспечить стабильную производительность при увеличении объёма данных.

#### Критерии приёмки

1. THE Partition_Manager SHALL партиционировать таблицу ledger_entries по диапазону created_at с интервалом 1 месяц
2. THE Partition_Manager SHALL партиционировать таблицу game_bets по диапазону created_at с интервалом 1 месяц
3. THE Partition_Manager SHALL автоматически создавать партиции на 3 месяца вперёд через cron-задачу, запускаемую еженедельно
4. WHEN партиция старше 12 месяцев, THE Partition_Manager SHALL архивировать партицию в отдельную таблицу и удалить из основной (с подтверждением администратора)
5. THE Partition_Manager SHALL выполнять миграцию существующих данных в партиционированную структуру без простоя (online DDL)
6. WHEN запрос к ledger_entries содержит фильтр по created_at, THE Platform SHALL использовать partition pruning для обращения только к релевантным партициям

### Требование 8: Read Replicas

**User Story:** Как backend-архитектор, я хочу настроить MySQL read replicas, чтобы разгрузить master-сервер от SELECT-запросов аналитики и Admin_Panel.

#### Критерии приёмки

1. THE Platform SHALL поддерживать конфигурацию двух PDO-подключений: $pdo_write (master) и $pdo_read (replica)
2. THE Platform SHALL направлять все SELECT-запросы Admin_Panel (дашборд, аналитика, ledger explorer) на $pdo_read
3. THE Platform SHALL направлять все финансовые операции (ledger entries, payouts, deposits) исключительно на $pdo_write
4. IF read replica недоступна, THEN THE Platform SHALL автоматически перенаправить SELECT-запросы на master и записать предупреждение в лог
5. THE Platform SHALL предоставить конфигурационный файл backend/config/db.php с параметрами подключения к master и replica, включая таймауты и retry-логику
6. WHILE данные реплицируются с задержкой более 5 секунд, THE Platform SHALL направлять критические SELECT-запросы (проверка баланса при ставке) на master

### Требование 9: Горизонтальное масштабирование Game_Worker

**User Story:** Как backend-архитектор, я хочу обеспечить возможность запуска нескольких экземпляров Game_Worker, чтобы платформа выдерживала рост нагрузки без единой точки отказа.

#### Критерии приёмки

1. THE Game_Worker SHALL использовать Redis consumer group, где каждый экземпляр получает уникальный consumer name (hostname + PID)
2. WHEN несколько экземпляров Game_Worker запущены, THE Redis_Broker SHALL распределять задачи между ними равномерно (каждая задача обрабатывается ровно одним воркером)
3. THE Game_Worker SHALL реализовать graceful shutdown: при получении SIGTERM завершить текущую задачу, подтвердить через XACK и остановиться
4. THE Game_Worker SHALL отправлять heartbeat в Redis каждые 10 секунд (SETEX worker:{name}:heartbeat 30 alive)
5. IF воркер не отправил heartbeat в течение 30 секунд, THEN THE Platform SHALL считать воркер мёртвым и перераспределить его pending-задачи через XCLAIM
6. THE Game_Worker SHALL логировать метрики обработки: количество обработанных раундов, среднее время обработки, количество ошибок — через Structured_Logger

### Требование 10: Docker-контейнеризация

**User Story:** Как backend-архитектор, я хочу контейнеризировать все компоненты платформы, чтобы обеспечить воспроизводимость окружения и упростить деплой.

#### Критерии приёмки

1. THE Platform SHALL предоставить Dockerfile для PHP backend (PHP 8.4-fpm + необходимые расширения: pdo_mysql, redis, pcntl)
2. THE Platform SHALL предоставить Dockerfile для Game_Worker (PHP 8.4-cli + расширения: pdo_mysql, redis, pcntl)
3. THE Platform SHALL предоставить docker-compose.yml с сервисами: nginx (API_Gateway), php-fpm (backend), game-worker (2 реплики), redis, mysql, websocket-server
4. THE Platform SHALL использовать Docker volumes для персистентных данных: MySQL data, Redis AOF, логи
5. THE Platform SHALL предоставить .env.example с переменными окружения для всех конфигурируемых параметров (DB credentials, Redis host, JWT secret, NOWPayments keys)
6. WHEN docker-compose up выполняется на чистой машине, THE Platform SHALL запуститься полностью в течение 120 секунд с автоматическим применением миграций БД
