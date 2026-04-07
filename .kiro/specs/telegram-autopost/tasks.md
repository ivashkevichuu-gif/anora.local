# Implementation Plan: Telegram Autopost Service

## Overview

Build a standalone Node.js microservice that subscribes to Redis Pub/Sub channels (`game:finished`, `bet:placed`), enriches events with MySQL data, and posts formatted messages to a Telegram channel. The service runs as a Docker container in the existing docker-compose stack. Implementation follows the module order: config → logger → redis → mysql → formatter → telegram sender → server.js → Dockerfile → docker-compose → .env.

## Tasks

- [x] 1. Initialize project and implement config module
  - [x] 1.1 Create `telegram/package.json` with dependencies (`ioredis`, `mysql2`) and `start` script
    - Use Node.js 20, plain JavaScript (no TypeScript)
    - Dependencies: `ioredis` ^5.3.2, `mysql2` ^3.9.0
    - _Requirements: 8.1, 8.2_

  - [x] 1.2 Create `telegram/lib/config.js` — read and validate environment variables
    - Validate `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` are set and non-empty; exit with code 1 if missing
    - Parse optional vars with defaults: `TELEGRAM_RATE_LIMIT=20`, `REDIS_HOST=redis`, `REDIS_PORT=6379`, `REDIS_PASSWORD=''`, `DB_WRITE_HOST=mysql`, `DB_NAME=anora`, `LOG_LEVEL=info`
    - Export a frozen config object with `telegram`, `redis`, `db`, `logLevel` sections
    - _Requirements: 8.2, 11.1, 11.2_

- [x] 2. Implement logger module
  - [x] 2.1 Create `telegram/lib/logger.js` — structured JSON logger to stdout
    - Output format: `{ timestamp (ISO 8601), level, message, context }`
    - Support levels: `debug`, `info`, `warning`, `error`
    - Respect `LOG_LEVEL` from config to filter output
    - Write to `process.stdout`
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

- [x] 3. Implement Redis module
  - [x] 3.1 Create `telegram/lib/redis.js` — Redis subscriber + dedup client
    - Create two ioredis connections: one for Pub/Sub subscriber mode, one for dedup SET/EXISTS
    - Configure exponential backoff reconnect: `Math.min(times * 1000, 30000)`
    - Implement `isDuplicate(key)` → checks EXISTS, returns false on connection error (Req 4.4)
    - Implement `markSent(key, ttlSeconds)` → SET with EX
    - Implement `shutdown()` → disconnect both connections
    - Subscribe to `game:finished` and `bet:placed` channels
    - _Requirements: 1.1, 1.4, 3.3, 4.1, 4.2, 4.3, 4.4_

- [x] 4. Implement MySQL module
  - [x] 4.1 Create `telegram/lib/mysql.js` — connection pool and query helpers
    - Create mysql2 connection pool with max 5 connections
    - Implement `fetchRoundDetails(pool, roundId)` — JOIN game_rounds, game_bets, users for game:finished enrichment
    - Implement `fetchRoundStatus(pool, roundId)` — query game_rounds status + total_pot + player count for bet:placed
    - Use `COALESCE(u.nickname, CONCAT('Player_', SUBSTRING(u.email, 1, 3), '***'))` for masked nickname fallback
    - Implement `shutdown(pool)` → pool.end()
    - Return null if round not found
    - _Requirements: 2.1, 2.4, 2.5, 3.1, 7.1, 7.2, 7.3, 7.4_

- [x] 5. Implement formatter module
  - [x] 5.1 Create `telegram/lib/formatter.js` — message template functions
    - Implement `formatGameFinished({ room, total_pot, players_count, winner_nickname, winner_net })` with emoji template
    - Implement `formatGameStarted({ room, total_pot, players_count })` with emoji template
    - Room display mapping: `1 → "$1"`, `10 → "$10"`, `100 → "$100"`
    - Ensure nickname is never displayed as "null" or empty
    - Include `https://anora.bet` link in both templates
    - _Requirements: 2.2, 2.5, 3.2_

- [x] 6. Implement Telegram sender module
  - [x] 6.1 Create `telegram/lib/telegram.js` — API sender with rate limiting and retry
    - Implement `createSender(config)` returning sender object
    - Implement `sender.validateBot()` — call Telegram `getMe` endpoint at startup; throw on failure
    - Implement `sender.send(text, metadata)` — POST to `sendMessage` endpoint
    - Sliding window rate limiter: track timestamps, enforce `rateLimit` messages per 60s, queue excess messages
    - Bounded message queue (max 100), drop oldest on overflow with warning log
    - Retry logic: HTTP 400/403 → no retry, log error; HTTP 429 → wait `retry_after`; other 4xx/5xx → retry 3 times with 1s, 2s, 4s backoff
    - After 3 failures: log error with round_id, status, body
    - Implement `sender.shutdown()` — drain queue, resolve pending (10s max)
    - Implement `sender.pendingCount()` — return queued message count
    - _Requirements: 5.1, 5.2, 5.3, 6.1, 6.2, 6.3, 6.4, 11.3, 11.4_

- [x] 7. Checkpoint — Verify all modules
  - Ensure all modules load without errors, ask the user if questions arise.

- [x] 8. Implement server.js entry point
  - [x] 8.1 Create `telegram/server.js` — startup, event routing, and graceful shutdown
    - Startup sequence: load config → validate bot token (getMe) → connect Redis subscriber + client → create MySQL pool → subscribe channels → log startup info
    - Event routing for `game:finished`: parse JSON → dedup check → MySQL enrich → format → send
    - Event routing for `bet:placed`: parse JSON → dedup check → MySQL status check → if status=active → format → send
    - Handle invalid JSON: log error, discard message without crashing (Req 1.5)
    - Graceful shutdown on SIGTERM/SIGINT: unsubscribe Redis → wait in-flight Telegram requests (10s timeout) → close Redis → close MySQL pool → exit 0
    - Log startup info: subscribed channels and chat ID
    - _Requirements: 1.1, 1.2, 1.3, 1.5, 2.1, 2.3, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3, 9.1, 9.2, 9.3, 10.4_

- [x] 9. Create Docker and infrastructure files
  - [x] 9.1 Create `docker/telegram/Dockerfile`
    - Use `node:20-alpine` base image
    - Copy `telegram/` directory, run `npm ci --production`
    - Set `CMD ["node", "server.js"]`
    - _Requirements: 8.1, 8.4_

  - [x] 9.2 Add `telegram` service to `docker-compose.yml`
    - Add service with build context pointing to `docker/telegram/Dockerfile`
    - Set all environment variables from .env: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_RATE_LIMIT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `DB_WRITE_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `LOG_LEVEL`
    - Add `depends_on` with health check conditions for `mysql` and `redis`
    - Connect to `anora-network`
    - Set `restart: unless-stopped`
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 9.3 Add Telegram environment variables to `.env.example`
    - Add `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_RATE_LIMIT` entries with comments
    - _Requirements: 8.2_

- [x] 10. Final checkpoint — Ensure everything is wired together
  - Ensure all modules are integrated, Dockerfile builds correctly, docker-compose references are valid. Ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP — none marked in this plan per user request to skip property tests for speed
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Plain JavaScript (Node.js 20), no TypeScript — consistent with existing `websocket/server.js` patterns
- Uses `ioredis` and `mysql2` matching existing stack conventions
