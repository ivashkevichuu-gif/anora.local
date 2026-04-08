# ANORA Platform

Gambling-платформа с provably fair лотереей, crypto-платежами (NOWPayments), ledger-based финансовой системой и real-time WebSocket событиями.

## Стек

| Компонент | Технология |
|-----------|-----------|
| Backend API | PHP 8.4-FPM (raw PDO, без фреймворка) |
| Game Worker | PHP 8.4-CLI (Redis Streams consumer) |
| WebSocket | Node.js 20 (ws + ioredis) |
| API Gateway | nginx (rate limit, CORS, X-Request-ID) |
| Database | MySQL 8.0 InnoDB |
| Cache / Queue | Redis 7 (Streams + Pub/Sub + Cache) |
| Frontend | React 18 + Vite + Tailwind CSS |
| Контейнеризация | Docker + docker-compose |

## Архитектура

```
┌─────────────┐     ┌─────────────┐
│  React SPA  │────▶│   nginx     │
│  Admin Panel│     │  API Gateway│
└─────────────┘     └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │ PHP-FPM  │ │WebSocket │ │  Static  │
        │ /api/*   │ │ /ws/*    │ │  files   │
        └────┬─────┘ └────┬─────┘ └──────────┘
             │            │
             ▼            ▼
        ┌──────────────────────┐
        │       Redis 7        │
        │ Streams│Cache│Pub/Sub│
        └──────────┬───────────┘
                   │
        ┌──────────┼──────────┐
        ▼          ▼          ▼
  ┌──────────┐ ┌────────┐ ┌────────┐
  │Game Worker│ │Game    │ │Worker  │
  │ #1       │ │Worker#2│ │Recovery│
  └────┬─────┘ └───┬────┘ └────────┘
       │           │
       ▼           ▼
  ┌────────────────────┐
  │   MySQL 8 Master   │
  │   (Read Replica)   │
  └────────────────────┘
```

## Развёртывание на Hetzner Cloud (anora.bet)

Пошаговая инструкция для запуска платформы на VPS Hetzner с доменом `anora.bet`. Проверена на Ubuntu 22.04/24.04.

### 1. Создать сервер в Hetzner Cloud

1. Зайти в [Hetzner Cloud Console](https://console.hetzner.cloud/)
2. Create Server:
   - Location: Helsinki (или ближайший к аудитории)
   - Image: Ubuntu 22.04 (или 24.04)
   - Type: CX21 минимум (2 vCPU, 4 GB RAM) — рекомендуется CX31 (2 vCPU, 8 GB)
   - SSH Key: добавить свой публичный ключ
3. Запомнить IP-адрес сервера

### 2. Настроить DNS

В панели регистратора домена `anora.bet` добавить A-записи:

```
anora.bet       A    <server-ip>
www.anora.bet   A    <server-ip>
```

Подождать 5-15 минут на распространение DNS.

### 3. Подключиться к серверу и установить зависимости

```bash
ssh root@<server-ip>

# Обновить систему
apt update && apt upgrade -y

# Установить Docker и запустить
curl -fsSL https://get.docker.com | sh
systemctl start docker
systemctl enable docker

# Установить docker-compose
apt install -y docker-compose

# Установить nginx (системный, как reverse proxy) + certbot
apt install -y nginx certbot python3-certbot-nginx

# Установить Node.js 20 (для сборки frontend)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Проверить
docker --version
docker-compose --version
nginx -v
node --version
```

### 4. Клонировать проект

```bash
mkdir -p /var/www
cd /var/www
git clone <repo-url> anora
cd anora
```

### 5. Собрать frontend

```bash
cd /var/www/anora/frontend
npm ci
npm run build
cd /var/www/anora
```

Vite настроен выводить билд в корень проекта (`../`). После `npm run build` файлы `index.html` и `assets/` уже на месте — копировать не нужно.

Проверить:

```bash
ls /var/www/anora/assets/index-*.js
```

### 6. Настроить переменные окружения

```bash
cd /var/www/anora
cp .env.example .env
nano .env
```

Заполнить (пароль БД без спецсимволов `/+=$` — они ломают shell-команды):

```dotenv
DB_WRITE_HOST=mysql
DB_READ_HOST=mysql
DB_USER=anora
DB_PASS=MyStr0ngPassw0rd2026
DB_NAME=anora

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=

JWT_SECRET=<вставить результат: openssl rand -hex 32>

NOWPAYMENTS_API_KEY=<ваш-ключ>
NOWPAYMENTS_IPN_SECRET=<ваш-секрет>
NOWPAYMENTS_API_BASE=https://api.nowpayments.io/v1
NOWPAYMENTS_SANDBOX=false

LOG_LEVEL=info
WS_PORT=8080
CORS_ORIGIN=https://anora.bet
```

Генерация секретов:

```bash
# JWT Secret (64 hex символа)
openssl rand -hex 32

# DB Password (безопасный, без спецсимволов)
openssl rand -hex 16
```

### 7. Запустить Docker-контейнеры

```bash
cd /var/www/anora
docker-compose up -d --build
```

Первый запуск занимает 2-5 минут (сборка образов + инициализация MySQL). Подождать 40 секунд после запуска, затем проверить:

```bash
sleep 40
docker-compose ps
```

Должно быть 7 контейнеров: mysql (healthy), redis (healthy), php-fpm (healthy), game-worker x2, websocket (healthy), nginx. Docker nginx слушает на порту 8080.

Если MySQL не healthy — подождать ещё 30 секунд (первая инициализация с `database.sql` занимает время).

Проверить логи при проблемах:

```bash
docker-compose logs --tail=30 mysql
docker-compose logs --tail=30 php-fpm
docker-compose logs --tail=30 game-worker
docker-compose logs --tail=30 websocket
docker-compose logs --tail=30 nginx
```

### 8. Применить миграции

```bash
docker exec anora_php-fpm_1 php migrations/init.php
```

Должно вывести: `Table refresh_tokens: OK`, `Table audit_logs: OK`.

### 9. Настроить системный nginx как reverse proxy

Docker nginx слушает на порту 8080. Системный nginx на порту 80/443 проксирует к нему.

```bash
cat > /etc/nginx/sites-available/anora.bet << 'EOF'
server {
    listen 80;
    server_name anora.bet www.anora.bet;

    client_max_body_size 50M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 10s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
    }

    location /ws/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
EOF

ln -sf /etc/nginx/sites-available/anora.bet /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
```

Проверить: `curl -I http://anora.bet` — должен вернуть 200.

### 10. Установить SSL-сертификат (Let's Encrypt)

```bash
certbot --nginx -d anora.bet -d www.anora.bet
```

После certbot — перезаписать конфиг, чтобы HTTPS блок тоже проксировал к Docker (certbot иногда добавляет `root` вместо `proxy_pass`):

```bash
cat > /etc/nginx/sites-available/anora.bet << 'EOF'
server {
    listen 80;
    server_name anora.bet www.anora.bet;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name anora.bet www.anora.bet;

    ssl_certificate /etc/letsencrypt/live/anora.bet/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/anora.bet/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    client_max_body_size 50M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 10s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
    }

    location /ws/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }
}
EOF

nginx -t && systemctl reload nginx
```

Проверить: `curl -I https://anora.bet` — должен вернуть 200.

Автообновление сертификата:

```bash
certbot renew --dry-run
```

### 11. Настроить Cron-задачи

```bash
crontab -e
```

Добавить:

```cron
# Reconciliation — проверка финансовых инвариантов (каждые 5 минут)
*/5 * * * * docker exec anora_php-fpm_1 php cron/reconciliation.php >> /var/log/anora-reconciliation.log 2>&1

# Cleanup — удаление старых данных (ежедневно в 3:00)
0 3 * * * docker exec anora_php-fpm_1 php cron/cleanup.php >> /var/log/anora-cleanup.log 2>&1

# Worker Recovery — восстановление задач мёртвых воркеров (каждую минуту)
* * * * * docker exec anora_php-fpm_1 php cron/worker_recovery.php >> /var/log/anora-worker-recovery.log 2>&1

# Partition Manager — создание партиций БД (воскресенье 2:00)
0 2 * * 0 docker exec anora_php-fpm_1 php cron/partition_manager.php >> /var/log/anora-partition.log 2>&1

# Bot Runner (каждую минуту)
* * * * * for i in 0 2 4 12; do sleep $i; docker exec anora_php-fpm_1 php bot_runner.php; done >> /var/log/anora-bot.log 2>&1
```

### 12. Настроить файрвол (UFW)

```bash
ufw allow 22/tcp    # SSH
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw enable
ufw status
```

Порт 8080 не открывать наружу — он только для внутреннего проксирования.

### 13. Проверить работу платформы

```bash
# Сайт (должен вернуть 200)
curl -I https://anora.bet

# API (должен вернуть JSON с game state)
curl https://anora.bet/api/game/status?room=1

# Docker статус (все контейнеры Up/healthy)
docker-compose ps

# Логи game worker (не должно быть "Redis unavailable")
docker-compose logs --tail=5 game-worker

# Redis
docker exec anora_redis_1 redis-cli ping
```

### 14. Партиционирование БД (опционально, для production)

```bash
docker exec anora_php-fpm_1 php migrations/partition_tables.php
```

### Обновление платформы

```bash
cd /var/www/anora
git pull

# Пересобрать frontend (если менялся)
cd frontend && npm ci && npm run build && cd ..

# Пересобрать и перезапустить контейнеры
docker-compose up -d --build

# Применить новые миграции (если есть)
docker exec anora_php-fpm_1 php migrations/init.php
```

### Полный сброс БД (если нужно начать с нуля)

```bash
cd /var/www/anora
docker-compose down
docker volume rm anora_mysql_data
docker-compose up -d --build
sleep 40
docker exec anora_php-fpm_1 php migrations/init.php
```

### Мониторинг и логи

```bash
# Все логи Docker
docker-compose logs -f

# Конкретный сервис
docker-compose logs -f game-worker

# Reconciliation (последний результат)
docker exec anora_php-fpm_1 cat logs/reconciliation_latest.json

# Использование ресурсов
docker stats

# Место на диске
df -h
docker system df
```

### Бэкап базы данных

```bash
# Создать бэкап (подставить пароль из .env)
mkdir -p /var/backups
docker exec anora_mysql_1 mysqldump -u root -p'<DB_PASS>' anora > /var/backups/anora_$(date +%Y%m%d_%H%M%S).sql

# Автоматический ежедневный бэкап (добавить в crontab)
0 4 * * * docker exec anora_mysql_1 mysqldump -u root -p'<DB_PASS>' anora | gzip > /var/backups/anora_$(date +\%Y\%m\%d).sql.gz 2>&1
```

### Troubleshooting (Hetzner)

| Проблема | Решение |
|----------|---------|
| `port 80 already in use` | Системный nginx занимает порт 80. Docker nginx слушает на 8080, системный проксирует к нему. Не менять порт Docker'а |
| `container unhealthy` | `docker-compose logs <service>`. MySQL: подождать 30-40с на первую инициализацию |
| `502 Bad Gateway` | PHP-FPM не запустился: `docker-compose logs php-fpm`. Часто: ошибка в конфиге БД |
| `500 Internal Server Error` после certbot | Certbot сломал nginx конфиг. Перезаписать конфиг из шага 10 (полный HTTPS блок с proxy_pass) |
| `rewrite or internal redirection cycle` | HTTPS server block не содержит proxy_pass. Перезаписать конфиг из шага 10 |
| `Table not found` после первого запуска | MySQL volume создан до монтирования database.sql. Решение: `docker-compose down && docker volume rm anora_mysql_data && docker-compose up -d` |
| `Access denied for user` | Пароль MySQL зафиксирован при первом создании volume. Удалить volume и пересоздать (см. "Полный сброс БД") |
| `Redis unavailable` в game-worker | Проверить `REDIS_HOST=redis` в `.env`. Перезапустить: `docker-compose restart game-worker` |
| `WebSocket не подключается` | Проверить блок `location /ws/` с upgrade headers в системном nginx |
| `npm ci` fails в websocket | Нет `package-lock.json`. Dockerfile использует `npm install --omit=dev` |
| `SSL не работает` | `certbot --nginx -d anora.bet`. Убедиться что DNS A-запись указывает на IP сервера |
| `Нет места на диске` | `docker system prune -a` — удалить неиспользуемые образы |
| `Game Worker не обрабатывает раунды` | `docker-compose logs game-worker`. Проверить Redis и MySQL подключение |

## Быстрый старт (Docker)

### 1. Клонировать репозиторий

```bash
git clone <repo-url> anora
cd anora
```

### 2. Настроить переменные окружения

```bash
cp .env.example .env
```

Отредактировать `.env`:

```dotenv
# Обязательно сменить:
DB_PASS=<strong-password>
JWT_SECRET=<openssl rand -hex 32>
NOWPAYMENTS_API_KEY=<your-key>
NOWPAYMENTS_IPN_SECRET=<your-secret>

# Для production:
NOWPAYMENTS_SANDBOX=false
CORS_ORIGIN=https://yourdomain.com
LOG_LEVEL=info
```

Генерация JWT-секрета:

```bash
openssl rand -hex 32
```

### 3. Собрать frontend

```bash
cd frontend
npm ci
npm run build
cd ..

# Скопировать билд в корень для nginx
cp -r frontend/dist/* .
```

### 4. Запустить платформу

```bash
docker-compose up -d --build
```

Это поднимет 6 сервисов:
- `mysql` — база данных (автоматически применяет `database.sql`)
- `redis` — кэш, очереди, pub/sub
- `php-fpm` — PHP backend API
- `game-worker` — 2 реплики обработчика игровых раундов
- `websocket` — WebSocket сервер для real-time событий
- `nginx` — API Gateway (порт 80)

### 5. Применить миграции

```bash
docker-compose exec php-fpm php migrations/init.php
```

Создаёт таблицы `refresh_tokens` и `audit_logs` (идемпотентно).

### 6. Проверить статус

```bash
docker-compose ps
docker-compose logs --tail=20 php-fpm
docker-compose logs --tail=20 game-worker
docker-compose logs --tail=20 websocket
```

Платформа доступна на `http://localhost`.

## Установка без Docker (bare metal)

### Требования

- PHP 8.4+ с расширениями: pdo_mysql, redis (pecl), pcntl
- MySQL 8.0+
- Redis 7+
- Node.js 20+
- nginx
- Composer 2

### 1. База данных

```bash
mysql -u root -p -e "CREATE DATABASE anora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p anora < database.sql
```

### 2. Backend

```bash
cd backend
composer install --no-dev --optimize-autoloader
php migrations/init.php
```

### 3. Frontend

```bash
cd frontend
npm ci
npm run build
```

### 4. WebSocket сервер

```bash
cd websocket
npm ci --production
```

### 5. nginx

Скопировать `docker/nginx/nginx.conf` в конфигурацию nginx. Адаптировать upstream-адреса:

```nginx
upstream php-fpm {
    server 127.0.0.1:9000;  # или unix:/var/run/php-fpm.sock
}

upstream websocket {
    server 127.0.0.1:8080;
}
```

Обновить `server_name`, `root`, пути к SSL-сертификатам.

### 6. Переменные окружения

Экспортировать переменные из `.env` или задать в конфигурации PHP-FPM pool:

```ini
; /etc/php/8.4/fpm/pool.d/anora.conf
env[DB_WRITE_HOST] = localhost
env[DB_READ_HOST] = localhost
env[DB_USER] = anora
env[DB_PASS] = <password>
env[DB_NAME] = anora
env[REDIS_HOST] = 127.0.0.1
env[REDIS_PORT] = 6379
env[JWT_SECRET] = <secret>
env[LOG_LEVEL] = info
```

Для WebSocket и Game Worker — задать через systemd environment или `.env` файл.

### 7. Запуск сервисов

```bash
# PHP-FPM
systemctl start php8.4-fpm

# nginx
systemctl start nginx

# WebSocket (systemd unit или screen/tmux)
cd /path/to/websocket && node server.js

# Game Worker (запустить 2 экземпляра)
cd /path/to/backend && php game_worker.php &
cd /path/to/backend && php game_worker.php &
```

## Cron-задачи

Добавить в crontab:

```cron
# Reconciliation — проверка финансовых инвариантов (каждые 5 минут)
*/5 * * * * php /path/to/backend/cron/reconciliation.php >> /var/log/anora/reconciliation.log 2>&1

# Cleanup — удаление старых данных (ежедневно в 3:00)
0 3 * * * php /path/to/backend/cron/cleanup.php >> /var/log/anora/cleanup.log 2>&1

# Worker Recovery — восстановление задач мёртвых воркеров (каждую минуту)
* * * * * php /path/to/backend/cron/worker_recovery.php >> /var/log/anora/worker_recovery.log 2>&1

# Partition Manager — создание партиций БД (еженедельно, воскресенье 2:00)
0 2 * * 0 php /path/to/backend/cron/partition_manager.php >> /var/log/anora/partition.log 2>&1

# Bot Runner — автоматические ставки (каждую минуту)
* * * * * for i in 0 2 4 12; do sleep $i; php /path/to/backend/bot_runner.php; done >> /var/log/anora/bot.log 2>&1
```

## Партиционирование БД

Для таблиц с большим объёмом данных (ledger_entries, game_bets) — запустить миграцию один раз:

```bash
php backend/migrations/partition_tables.php
```

После этого `partition_manager.php` (cron) автоматически создаёт новые партиции на 3 месяца вперёд и логирует кандидатов на архивацию (старше 12 месяцев).

## Read Replicas

Если настроена MySQL-реплика, указать её хост:

```dotenv
DB_WRITE_HOST=mysql-master
DB_READ_HOST=mysql-replica
```

Админ-панель (dashboard, analytics, ledger) автоматически использует реплику для SELECT-запросов. При недоступности реплики — fallback на master. При replication lag > 5 секунд — критические SELECT (баланс при ставке) идут на master.

## Развёртывание на shared-хостинге (DirectAdmin + phpMyAdmin)

Эта инструкция для хостинга с DirectAdmin панелью, где нет root-доступа и Docker. Платформа работает через Apache/nginx хостинга, PHP запускается как модуль или через PHP-FPM, а Redis и Node.js требуют SSH-доступа.

### Требования к хостингу

- PHP 8.1+ (рекомендуется 8.4) с расширениями: pdo_mysql, pcntl, json, mbstring
- MySQL 8.0+ (доступ через phpMyAdmin)
- SSH-доступ (для Redis, Node.js, cron, composer)
- Возможность установить Redis (или доступ к внешнему Redis)
- Node.js 20+ (для WebSocket сервера)
- Composer 2

Если хостинг не поддерживает Redis — платформа работает без него (graceful degradation), но без real-time событий, очередей и кэширования.

### Шаг 1: Создать домен в DirectAdmin

1. Войти в DirectAdmin → Domain Setup
2. Добавить домен (например `anora.bet`)
3. Убедиться что PHP версия 8.1+ (User Level → PHP Version Manager или Custom HTTPD → PHP-FPM)
4. Запомнить путь к document root: обычно `/home/<username>/domains/anora.bet/public_html/`

### Шаг 2: Создать базу данных через phpMyAdmin

1. DirectAdmin → MySQL Management → Create New Database
2. Создать БД, например: `username_anora`
3. Создать пользователя БД с полными правами
4. Запомнить: DB_NAME, DB_USER, DB_PASS
5. Открыть phpMyAdmin → выбрать созданную БД
6. Вкладка Import → загрузить файл `database.sql`
7. Нажать Go — все таблицы будут созданы

Если файл слишком большой для phpMyAdmin:

```bash
# Через SSH:
mysql -u username_anora -p username_anora < /home/<username>/domains/anora.bet/public_html/database.sql
```

### Шаг 3: Загрузить файлы

Через SSH (рекомендуется):

```bash
cd /home/<username>/domains/anora.bet/public_html/
git clone <repo-url> .
# или загрузить архив и распаковать
```

Или через File Manager в DirectAdmin / FTP-клиент.

Структура в `public_html/` должна быть:

```
public_html/
├── backend/
├── frontend/
├── websocket/
├── assets/          ← билд frontend (JS/CSS)
├── index.html       ← React SPA entry point
├── favicon.svg
├── .htaccess
├── database.sql
└── .env.example
```

### Шаг 4: Собрать frontend

```bash
cd /home/<username>/domains/anora.bet/public_html/frontend
npm ci
npm run build

# Скопировать билд в public_html
cp -r dist/* ../
```

### Шаг 5: Установить PHP-зависимости

```bash
cd /home/<username>/domains/anora.bet/public_html/backend
composer install --no-dev --optimize-autoloader
```

Если `composer` не установлен глобально:

```bash
curl -sS https://getcomposer.org/installer | php
php composer.phar install --no-dev --optimize-autoloader
```

### Шаг 6: Настроить конфигурацию

Вариант A — через переменные окружения (если хостинг поддерживает `.env`):

```bash
cd /home/<username>/domains/anora.bet/public_html/
cp .env.example .env
nano .env
```

```dotenv
DB_WRITE_HOST=localhost
DB_READ_HOST=localhost
DB_USER=username_anora
DB_PASS=<your-db-password>
DB_NAME=username_anora
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
JWT_SECRET=<openssl rand -hex 32>
NOWPAYMENTS_API_KEY=<your-key>
NOWPAYMENTS_IPN_SECRET=<your-secret>
NOWPAYMENTS_SANDBOX=false
LOG_LEVEL=info
```

Вариант B — прямое редактирование конфигов (если `.env` не подхватывается):

Отредактировать `backend/config/db.php` — заменить значения по умолчанию:

```php
define('DB_HOST', getenv('DB_WRITE_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'username_anora');
define('DB_PASS', getenv('DB_PASS') ?: '<your-password>');
define('DB_NAME', getenv('DB_NAME') ?: 'username_anora');
```

Отредактировать `backend/config/nowpayments.php`:

```php
return [
    'api_key'     => '<your-nowpayments-api-key>',
    'ipn_secret'  => '<your-ipn-secret>',
    'api_base_url'=> 'https://api.nowpayments.io/v1',
    'sandbox_mode'=> false,
    'manual_approval_threshold' => 500.00,
];
```

Задать JWT_SECRET — добавить в начало `backend/config/db.php` или создать `backend/config/env.php`:

```php
<?php
// backend/config/env.php — загружается перед всеми скриптами
putenv('JWT_SECRET=<your-64-char-hex-secret>');
putenv('REDIS_HOST=127.0.0.1');
putenv('LOG_LEVEL=info');
```

И добавить `require_once __DIR__ . '/env.php';` в начало `backend/config/db.php`.

### Шаг 7: Применить миграции

```bash
cd /home/<username>/domains/anora.bet/public_html/backend
php migrations/init.php
```

Или через phpMyAdmin — выполнить SQL из `migrations/add_refresh_tokens.php` вручную:

```sql
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    token_hash          VARCHAR(64)  NOT NULL UNIQUE,
    user_id             INT          NOT NULL,
    family_id           VARCHAR(36)  NOT NULL,
    device_fingerprint  VARCHAR(64)  DEFAULT NULL,
    expires_at          DATETIME     NOT NULL,
    revoked_at          DATETIME     DEFAULT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id    (user_id),
    INDEX idx_family_id  (family_id),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    timestamp   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    level       ENUM('debug','info','warning','error','critical') NOT NULL,
    action      VARCHAR(64) NOT NULL,
    user_id     INT DEFAULT NULL,
    ip_address  VARCHAR(45) DEFAULT NULL,
    user_agent  TEXT DEFAULT NULL,
    request_id  VARCHAR(36) DEFAULT NULL,
    result      ENUM('success','failure') DEFAULT NULL,
    context     JSON DEFAULT NULL,
    INDEX idx_user_id   (user_id),
    INDEX idx_action    (action),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Шаг 8: Проверить .htaccess

Файл `.htaccess` в корне `public_html/` уже настроен:

```apache
Options -MultiViews
RewriteEngine On
RewriteBase /

# Реальные файлы и директории — пропускать
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# PHP backend — пропускать
RewriteRule ^backend/ - [L]

# Всё остальное → React SPA
RewriteRule ^ /index.html [L]
```

Убедиться что `mod_rewrite` включён (обычно включён по умолчанию на DirectAdmin).

### Шаг 9: Установить и запустить Redis (SSH)

Если Redis не установлен на сервере:

```bash
# Проверить наличие
redis-cli ping

# Если нет — попросить хостера установить, или использовать внешний Redis
# (например Redis Cloud — https://redis.com/try-free/)
```

Если Redis недоступен — платформа работает без него. Game worker будет использовать fallback на MySQL polling (legacy `game_worker_cron.php`).

Установить PHP-расширение redis (если не установлено):

```bash
# Проверить
php -m | grep redis

# Если нет — через pecl (нужен SSH)
pecl install redis
echo "extension=redis.so" >> $(php -i | grep "Loaded Configuration" | awk '{print $NF}')
```

### Шаг 10: Запустить фоновые процессы

Game Worker (через screen или nohup):

```bash
cd /home/<username>/domains/anora.bet/public_html/backend

# Вариант 1: screen
screen -dmS game-worker-1 php game_worker.php
screen -dmS game-worker-2 php game_worker.php

# Вариант 2: nohup
nohup php game_worker.php >> /home/<username>/game_worker_1.log 2>&1 &
nohup php game_worker.php >> /home/<username>/game_worker_2.log 2>&1 &
```

Если Redis недоступен — использовать legacy cron-воркер:

```bash
# В crontab (каждую минуту):
* * * * * for i in $(seq 0 59); do sleep $i; php /home/<username>/domains/anora.bet/public_html/backend/game_worker_cron.php >> /home/<username>/game_worker.log 2>&1; done
```

WebSocket сервер (через screen или nohup):

```bash
cd /home/<username>/domains/anora.bet/public_html/websocket
npm ci --production

# Запуск
screen -dmS websocket node server.js
# или
nohup node server.js >> /home/<username>/websocket.log 2>&1 &
```

Для WebSocket на shared-хостинге нужен проксирование через nginx. Если хостинг использует Apache — WebSocket может не работать напрямую. В этом случае WebSocket доступен по отдельному порту (8080) и фронтенд подключается напрямую к `wss://anora.bet:8080`.

### Шаг 11: Настроить Cron Jobs в DirectAdmin

DirectAdmin → Cron Jobs → добавить:

```
# Reconciliation (каждые 5 минут)
*/5 * * * * php /home/<username>/domains/anora.bet/public_html/backend/cron/reconciliation.php >> /home/<username>/reconciliation.log 2>&1

# Cleanup (ежедневно в 3:00)
0 3 * * * php /home/<username>/domains/anora.bet/public_html/backend/cron/cleanup.php >> /home/<username>/cleanup.log 2>&1

# Worker Recovery (каждую минуту, если Redis доступен)
* * * * * php /home/<username>/domains/anora.bet/public_html/backend/cron/worker_recovery.php >> /home/<username>/worker_recovery.log 2>&1

# Partition Manager (воскресенье 2:00)
0 2 * * 0 php /home/<username>/domains/anora.bet/public_html/backend/cron/partition_manager.php >> /home/<username>/partition.log 2>&1

# Bot Runner (каждую минуту)
* * * * * for i in 0 2 4 12; do sleep $i; php /home/<username>/domains/anora.bet/public_html/backend/bot_runner.php; done >> /home/<username>/bot.log 2>&1
```

### Шаг 12: Настроить nginx (если хостинг использует nginx)

Если DirectAdmin использует nginx + Apache (OpenLiteSpeed) или чистый nginx, добавить кастомную конфигурацию.

DirectAdmin → Custom HTTPD Configurations → nginx:

```nginx
# WebSocket proxy (если Node.js запущен на порту 8080)
location /ws/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 86400s;
}
```

Если хостинг не позволяет кастомизировать nginx — WebSocket будет доступен по прямому порту.

### Шаг 13: Проверить работу

```bash
# Проверить что сайт открывается
curl -I https://anora.bet

# Проверить API
curl https://anora.bet/api/game/status?room=1

# Проверить health check (требует admin JWT)
curl https://anora.bet/api/admin/health_check

# Проверить game worker
ps aux | grep game_worker

# Проверить WebSocket
ps aux | grep "node server.js"

# Проверить Redis
redis-cli ping
```

### Шаг 14: SSL-сертификат

DirectAdmin → SSL Certificates → выбрать Let's Encrypt → получить сертификат для домена.

Или через SSH:

```bash
certbot certonly --webroot -w /home/<username>/domains/anora.bet/public_html -d anora.bet
```

### Troubleshooting (DirectAdmin)

| Проблема | Решение |
|----------|---------|
| 500 Internal Server Error | Проверить `error_log` в DirectAdmin → Error Log. Часто — неправильные права на файлы (`chmod 755 backend/`, `chmod 644 *.php`) |
| API возвращает HTML вместо JSON | Убедиться что `.htaccess` работает: `RewriteEngine On` требует `AllowOverride All` |
| phpMyAdmin: import слишком большой | Увеличить `upload_max_filesize` и `post_max_size` в PHP Settings (DirectAdmin → PHP Version Manager) или импортировать через SSH |
| Redis не подключается | Проверить `php -m \| grep redis`. Если расширения нет — установить через pecl или попросить хостера |
| Game Worker падает | Проверить лог: `tail -f /home/<username>/game_worker_1.log`. Частая причина — нет расширения pcntl (на shared-хостинге может быть отключено). Использовать `game_worker_cron.php` как fallback |
| WebSocket не работает | На shared-хостинге порт 8080 может быть заблокирован файрволом. Попросить хостера открыть порт или использовать Cloudflare Tunnel |
| Composer не найден | `curl -sS https://getcomposer.org/installer \| php` → использовать `php composer.phar` вместо `composer` |
| Node.js не найден | Установить через nvm: `curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh \| bash && nvm install 20` |
| Cron не работает | Проверить путь к PHP: `which php`. На некоторых хостингах нужен полный путь: `/usr/local/bin/php` |

## SSL/TLS (Production)

Для production добавить в nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    # ... остальная конфигурация из docker/nginx/nginx.conf
}

server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}
```

Certbot:

```bash
certbot --nginx -d yourdomain.com
```

## API Endpoints

| Метод | Путь | Описание |
|-------|------|----------|
| POST | /api/auth/login | Логин (JWT) |
| POST | /api/auth/register | Регистрация |
| POST | /api/auth/refresh | Обновление токенов |
| POST | /api/auth/logout | Выход |
| GET | /api/auth/me | Текущий пользователь |
| POST | /api/game/bet | Сделать ставку |
| GET | /api/game/status | Состояние раунда |
| POST | /api/account/crypto_deposit | Создать крипто-депозит |
| POST | /api/account/crypto_withdraw | Создать крипто-вывод |
| GET | /api/admin/finance_dashboard | Финансовый дашборд |
| GET | /api/admin/games_analytics | Аналитика игр |
| GET | /api/admin/activity_monitor | Мониторинг фрода |
| GET | /api/admin/health_check | Проверка инвариантов |
| GET | /api/admin/media_settings | Настройки медиа (Instagram, Telegram, соцсети) |
| POST | /api/admin/media_settings | Обновить настройки медиа |
| GET | /api/admin/media_posts | Лог медиа-постов с пагинацией |
| GET | /api/admin/social_links_public | Публичные соцсети для footer (без авторизации) |

WebSocket:

```
ws://host/ws/game/1?token=<jwt>      — комната $1
ws://host/ws/game/10?token=<jwt>     — комната $10
ws://host/ws/game/100?token=<jwt>    — комната $100
ws://host/ws/admin/live?token=<jwt>  — admin мониторинг
```

## Telegram Bot (Game Autoposting)

The platform includes a Telegram bot that automatically posts game events (new game started, game finished with winner) to a Telegram channel in real time.

### Step 1: Create a Telegram Bot

1. Open Telegram, search for `@BotFather`
2. Send `/newbot`
3. Choose a name: `ANORA Game Bot`
4. Choose a username: `anora_game_bot` (must end with `bot`)
5. BotFather will reply with a token like: `7123456789:AAH...`
6. Copy the token — this is your `TELEGRAM_BOT_TOKEN`

### Step 2: Create a Telegram Channel

1. In Telegram, create a new channel (e.g. `ANORA Games`)
2. Make it public with a username (e.g. `@anora_games`)
3. Go to channel settings → Administrators → Add Administrator
4. Search for your bot (`@anora_game_bot`) and add it as admin
5. Give it permission to "Post Messages"
6. Your `TELEGRAM_CHAT_ID` is `@anora_games` (the channel username with @)

Alternatively, for a private channel:
1. Add the bot as admin
2. Send any message to the channel
3. Open `https://api.telegram.org/bot<TOKEN>/getUpdates` in browser
4. Find `"chat":{"id":-100...}` — that negative number is your `TELEGRAM_CHAT_ID`

### Step 3: Configure Environment Variables

```bash
nano /var/www/anora/.env
```

Add:

```dotenv
TELEGRAM_BOT_TOKEN=7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=@anora_games
TELEGRAM_RATE_LIMIT=20
```

### Step 4: Deploy the Bot

```bash
cd /var/www/anora
docker compose up -d --build telegram
```

### Step 5: Verify

```bash
# Check logs
docker compose logs -f telegram

# Should see:
# Telegram bot validated (bot_username: anora_game_bot)
# Redis connected
# MySQL pool created
# Service started (channels: game:finished, bet:placed)
```

When a game finishes, the bot will post:

```
🏆 Game finished!

🎰 Room $10
💰 Bank: $30.00 USD
👥 Players: 3
🥇 Winner: CoolPlayer42
💵 Won: $25.65 USD

🎉 Congratulations!
👇 Play now: https://anora.bet
```

### Troubleshooting (Telegram Bot)

| Problem | Solution |
|---------|----------|
| `Missing required environment variable: TELEGRAM_BOT_TOKEN` | Add `TELEGRAM_BOT_TOKEN` to `.env` and rebuild: `docker compose up -d telegram` |
| `Telegram getMe failed: HTTP 401` | Bot token is invalid. Get a new one from @BotFather |
| `Telegram API error: HTTP 403` | Bot is not an admin of the channel, or `TELEGRAM_CHAT_ID` is wrong |
| Bot starts but no messages | Check that games are being played. Bot only posts on `game:finished` and `bet:placed` Redis events |
| Duplicate messages | Should not happen (Redis dedup). Check `docker compose logs telegram` for warnings |
| `Redis subscriber error` | Redis container not running: `docker compose ps redis` |

## Media Service (Instagram Reels + Telegram Autopost)

Платформа включает отдельный сервис для автоматической публикации игровых событий в Instagram (Reels) и Telegram. Сервис написан на Node.js + TypeScript, использует BullMQ для очередей и Remotion для рендеринга видео.

### Архитектура

```
game_engine.php
    │ PUBLISH game:finished / bet:placed
    ▼
┌──────────────────┐
│   Redis Pub/Sub  │
└────────┬─────────┘
         ▼
┌──────────────────┐
│  Room Watcher    │ ← подписка на события, фильтрация, дедупликация
└────────┬─────────┘
    ┌────┴────┐
    ▼         ▼
┌────────┐ ┌──────────┐
│BullMQ  │ │ BullMQ   │
│telegram│ │instagram │
│-publish│ │-publish  │
└───┬────┘ └────┬─────┘
    ▼           ▼
┌────────┐ ┌──────────────┐
│Telegram│ │ Remotion     │
│Bot API │ │ render → MP4 │
└────────┘ │ → Meta Graph │
           │   API publish│
           └──────────────┘
```

### Настройка Telegram (детальная)

#### Шаг 1: Создать бота

1. Открыть Telegram, найти `@BotFather`
2. Отправить `/newbot`
3. Выбрать имя: `ANORA Game Bot`
4. Выбрать username: `anora_game_bot` (должен заканчиваться на `bot`)
5. BotFather ответит токеном вида: `7123456789:AAH...`
6. Скопировать — это `TELEGRAM_BOT_TOKEN`

#### Шаг 2: Создать канал

1. В Telegram создать новый канал (например `ANORA Games`)
2. Сделать публичным с username (например `@anora_games`)
3. Настройки канала → Администраторы → Добавить администратора
4. Найти бота (`@anora_game_bot`) и добавить как админа
5. Дать права: "Публикация сообщений"
6. `TELEGRAM_CHAT_ID` = `@anora_games` (username канала с @)

Для приватного канала:
1. Добавить бота как админа
2. Отправить любое сообщение в канал
3. Открыть `https://api.telegram.org/bot<TOKEN>/getUpdates`
4. Найти `"chat":{"id":-100...}` — это отрицательное число и есть `TELEGRAM_CHAT_ID`

#### Шаг 3: Переменные окружения

В `.env`:

```dotenv
TELEGRAM_BOT_TOKEN=7123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TELEGRAM_CHAT_ID=@anora_games
TELEGRAM_RATE_LIMIT=20
```

#### Шаг 4: Настройка через админ-панель

1. Зайти в Admin Panel → Media Settings
2. Включить глобальный тогл "Telegram"
3. В секции Telegram Settings:
   - Enable Telegram posting: ✅
   - Post new games: ✅ (отправлять сообщение когда игра стартует)
   - Post finished games: ✅ (отправлять результат завершённой игры)
4. Сохранить

#### Шаг 5: Деплой

```bash
cd /var/www/anora
docker compose up -d --build media-service
```

#### Шаг 6: Проверка

```bash
docker compose logs -f media-service

# Должно быть:
# Starting ANORA Media Service
# RoomWatcher subscribed to game:finished, bet:placed
# Telegram publisher worker started
# Instagram publisher worker started
# All workers started
```

Когда игра завершится, бот отправит:

```
🏆 Game Finished!

🎰 Room $10
💰 Bank: $30.00

👥 Players:
  🥇 CoolPlayer42 — $10.00 (33%) ← WINNER
  👤 Player2 — $10.00 (33%)
  👤 Player3 — $10.00 (33%)

💵 Won: $29.10

🎉 Congratulations!

[🎮 Play Now]  ← inline кнопка
```

### Настройка Instagram Reels (детальная)

Instagram публикация работает через Meta Graph API. Требуется бизнес-аккаунт Instagram, привязанный к Facebook Page.

#### Шаг 1: Создать Facebook App

1. Перейти на [Meta for Developers](https://developers.facebook.com/)
2. My Apps → Create App
3. Тип: Business
4. Название: `ANORA Media Publisher`
5. Привязать к Business Manager (если есть)

#### Шаг 2: Добавить Instagram Graph API

1. В настройках приложения → Add Products
2. Найти "Instagram Graph API" → Set Up
3. Перейти в Instagram Graph API → Settings

#### Шаг 3: Подключить Instagram Business Account

Требования:
- Instagram аккаунт должен быть **Business** или **Creator** (не Personal)
- Аккаунт должен быть привязан к **Facebook Page**

Как переключить на Business:
1. Instagram → Настройки → Аккаунт → Переключиться на профессиональный аккаунт
2. Выбрать "Бизнес"
3. Привязать к Facebook Page (создать новую если нет)

#### Шаг 4: Получить Access Token

Вариант A — через Graph API Explorer (для тестирования):

1. Перейти в [Graph API Explorer](https://developers.facebook.com/tools/explorer/)
2. Выбрать своё приложение
3. Нажать "Generate Access Token"
4. Выбрать permissions:
   - `instagram_basic`
   - `instagram_content_publish`
   - `pages_read_engagement`
   - `pages_show_list`
5. Авторизовать
6. Скопировать токен

Этот токен живёт ~1 час. Для production нужен long-lived token.

Вариант B — Long-Lived Token (для production):

```bash
# 1. Получить short-lived user token через Graph API Explorer (см. выше)

# 2. Обменять на long-lived token (живёт 60 дней)
curl -s "https://graph.facebook.com/v19.0/oauth/access_token?\
grant_type=fb_exchange_token&\
client_id=YOUR_APP_ID&\
client_secret=YOUR_APP_SECRET&\
fb_exchange_token=SHORT_LIVED_TOKEN"

# Ответ: {"access_token":"LONG_LIVED_TOKEN","token_type":"bearer","expires_in":5184000}

# 3. Получить Page Access Token (не истекает)
curl -s "https://graph.facebook.com/v19.0/me/accounts?\
access_token=LONG_LIVED_USER_TOKEN"

# Найти нужную Page и скопировать её access_token
# Этот Page Token не истекает (если у приложения есть manage_pages permission)
```

#### Шаг 5: Получить Instagram User ID

```bash
# Используя Page Access Token:
curl -s "https://graph.facebook.com/v19.0/PAGE_ID?\
fields=instagram_business_account&\
access_token=PAGE_ACCESS_TOKEN"

# Ответ: {"instagram_business_account":{"id":"17841400123456789"}}
# Это ваш INSTAGRAM_USER_ID
```

Или через Graph API Explorer:
1. Запрос: `GET /me/accounts?fields=instagram_business_account`
2. Найти `instagram_business_account.id`

#### Шаг 6: Переменные окружения

В `.env`:

```dotenv
INSTAGRAM_ACCESS_TOKEN=EAAxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
INSTAGRAM_USER_ID=17841400123456789
VIDEO_OUTPUT_DIR=./media-service/out
VIDEO_PUBLIC_URL=https://anora.bet/media
```

`VIDEO_PUBLIC_URL` — публичный URL, по которому Meta сможет скачать видео. Nginx уже настроен раздавать файлы из `/media/`.

#### Шаг 7: Настройка через админ-панель

1. Admin Panel → Media Settings
2. Включить глобальный тогл "Instagram"
3. В секции Instagram Reels:
   - Enable Instagram posting: ✅
   - Allowed Rooms: выбрать комнаты (✅ $1, ✅ $10, ✅ $100)
   - Min Win Amount: `5.00` (минимальный банк для публикации)
   - Max Reels / Day: `10` (лимит постов в сутки)
4. Сохранить

#### Шаг 8: Деплой

```bash
cd /var/www/anora

# Применить миграцию БД (создаёт таблицы media_settings, instagram_settings и т.д.)
docker compose exec php-fpm php migrations/add_media_tables.php

# Запустить/перезапустить media service
docker compose up -d --build media-service
```

#### Шаг 9: Проверка

```bash
# Логи media service
docker compose logs -f media-service

# Проверить что видео рендерится (после завершения игры)
docker compose exec media-service ls -la /app/out/

# Проверить статус постов в админке
# Admin Panel → Media Settings → Recent Posts
```

#### Как работает публикация Reel

1. Игра завершается → `game_engine.php` публикует `game:finished` в Redis
2. Room Watcher получает событие, проверяет фильтры:
   - Instagram включён глобально и в настройках?
   - Комната разрешена?
   - Банк >= min_win_amount?
   - Не превышен дневной лимит?
   - Нет дубликата?
3. Создаёт запись в `media_posts` и добавляет job в BullMQ
4. Instagram Publisher Worker:
   - Рендерит видео через Remotion (1080x1920, 30fps, 12 сек, H264)
   - Загружает через Meta Graph API: создаёт контейнер → ждёт обработки → публикует
5. Обновляет статус в `media_posts`, инкрементирует счётчик `posts_today`

#### Обновление Instagram токена

Long-lived Page Token не истекает, но если используется User Token (60 дней):

```bash
# Обновить токен до истечения
curl -s "https://graph.facebook.com/v19.0/oauth/access_token?\
grant_type=fb_exchange_token&\
client_id=YOUR_APP_ID&\
client_secret=YOUR_APP_SECRET&\
fb_exchange_token=CURRENT_LONG_LIVED_TOKEN"
```

Обновить в `.env` и перезапустить:

```bash
docker compose restart media-service
```

#### Troubleshooting (Instagram)

| Проблема | Решение |
|----------|---------|
| `Instagram credentials not configured` | Заполнить `INSTAGRAM_ACCESS_TOKEN` и `INSTAGRAM_USER_ID` в `.env` |
| `Container creation failed` | Проверить что токен валиден и имеет permission `instagram_content_publish` |
| `Container status: ERROR` | Видео не доступно по `VIDEO_PUBLIC_URL`. Проверить nginx location `/media/` и что файл существует |
| `OAuthException: Invalid token` | Токен истёк. Обновить через Graph API Explorer или обменять на long-lived |
| `Application does not have permission` | В Meta App Dashboard → App Review → запросить `instagram_content_publish` |
| `Video too short/long` | Remotion рендерит 12 сек (360 frames @ 30fps). Instagram требует 3-90 сек |
| `Daily limit reached` | Счётчик `posts_today` сбрасывается автоматически раз в сутки. Или сбросить вручную в админке |
| Видео рендерится, но не публикуется | Проверить что `VIDEO_PUBLIC_URL` доступен из интернета: `curl -I https://anora.bet/media/game_123_xxx.mp4` |
| `Chromium not found` | Docker образ включает Chromium. Для bare metal: `apt install chromium` |

#### Troubleshooting (Telegram в Media Service)

| Проблема | Решение |
|----------|---------|
| `Telegram not configured, skipping` | Заполнить `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID` в `.env` |
| `Telegram send failed: 403` | Бот не админ канала или неверный `TELEGRAM_CHAT_ID` |
| `Telegram send failed: 401` | Невалидный `TELEGRAM_BOT_TOKEN` |
| Дубликаты сообщений | Проверить что не запущен одновременно старый `telegram/server.js` и новый `media-service`. Выбрать один |
| Сообщения не отправляются | Проверить что в Admin Panel включены: Global Telegram + Telegram Settings enabled |

### Настройка Social Links (Footer)

1. Admin Panel → Media Settings → Social Links
2. Заполнить:
   - Telegram URL: `https://t.me/anora_games`
   - Instagram URL: `https://instagram.com/anora.bet`
3. Сохранить

Ссылки автоматически появятся в footer сайта с иконками Telegram и Instagram.

### Миграция с существующего Telegram бота

Если уже используется `telegram/server.js` (старый Telegram autopost сервис), есть два варианта:

**Вариант A: Использовать только Media Service (рекомендуется)**

1. Остановить старый сервис: `docker compose stop telegram`
2. Убрать из `docker-compose.yml` или добавить `profiles: ["legacy"]`
3. Настроить Media Service как описано выше
4. Media Service берёт на себя и Telegram, и Instagram

**Вариант B: Использовать оба параллельно**

1. В Admin Panel → Media Settings → отключить глобальный тогл Telegram
2. Старый `telegram/server.js` продолжает работать через Redis Pub/Sub напрямую
3. Media Service обрабатывает только Instagram
4. Минус: нет единого лога постов и управления через админку для Telegram

## Тестирование

```bash
cd backend
composer install
vendor/bin/phpunit
```

25 property-based тестов + unit-тесты покрывают: JWT auth, ledger idempotency, cache invalidation, rate limiting, graceful degradation, event routing, WebSocket limits, partition generation, query routing, worker recovery.

## Мониторинг

- `GET /api/admin/health_check` — 3 монетарных инварианта (no money created, no money lost, everything traceable)
- `GET /api/admin/finance_dashboard` — deposits, withdrawals, system profit, RTP
- `GET /api/admin/activity_monitor` — 8 типов fraud-флагов
- `backend/logs/reconciliation_latest.json` — последний результат reconciliation
- nginx JSON access logs — `docker-compose logs nginx`
- Structured JSON logs (stdout) — `docker-compose logs php-fpm`

## Структура проекта

```
├── backend/
│   ├── api/              # REST API endpoints
│   │   ├── auth/         # login, register, refresh, logout, me
│   │   ├── game/         # bet, status, verify, fingerprint
│   │   ├── account/      # deposits, withdrawals, stats
│   │   ├── admin/        # dashboard, users, analytics, media_settings
│   │   └── webhook/      # NOWPayments IPN
│   ├── includes/         # Core services
│   │   ├── jwt_service.php        # JWT auth (HS256)
│   │   ├── auth_middleware.php    # requireAuth/requireAdmin
│   │   ├── ledger_service.php     # Ledger (idempotent, FOR UPDATE)
│   │   ├── game_engine.php        # Game state machine
│   │   ├── redis_client.php       # Redis singleton
│   │   ├── queue_service.php      # Redis Streams
│   │   ├── cache_service.php      # Cache + rate limiting
│   │   ├── structured_logger.php  # JSON logging
│   │   └── webhook_handler.php    # HMAC-SHA512 webhooks
│   ├── cron/             # Background jobs
│   │   ├── reconciliation.php     # Financial invariants
│   │   ├── cleanup.php            # Data cleanup
│   │   ├── worker_recovery.php    # Dead worker XCLAIM
│   │   └── partition_manager.php  # DB partitioning
│   ├── migrations/       # DB migrations
│   ├── config/           # db.php, nowpayments.php
│   ├── tests/            # PHPUnit property-based tests
│   └── game_worker.php   # Redis Streams game processor
├── frontend/             # React 18 + Vite
├── media-service/        # Instagram Reels + Telegram autopost (Node.js + TypeScript)
│   └── src/
│       ├── workers/      # roomWatcher, telegramPublisher, instagramPublisher
│       ├── publishers/   # telegram.ts, instagram.ts (Meta Graph API)
│       ├── remotion/     # FinishedGameVideo.tsx (branded video component)
│       ├── cron/         # dailyReset.ts (Instagram counter)
│       ├── renderer.ts   # Remotion → MP4 (1080x1920, H264)
│       └── index.ts      # Entry point
├── telegram/             # Legacy Telegram autopost (Node.js)
├── websocket/            # Node.js WebSocket server
├── docker/               # Dockerfiles + nginx config
├── docker-compose.yml
├── .env.example
└── database.sql          # Full MySQL schema
```
