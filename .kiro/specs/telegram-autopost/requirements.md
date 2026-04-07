# Requirements Document

## Introduction

A standalone Node.js microservice that subscribes to existing Redis Pub/Sub channels (`game:finished`, `bet:placed`) on the anora.bet gambling platform and automatically posts formatted messages to a Telegram channel via the Telegram Bot API. The service runs as a Docker container alongside the existing infrastructure (MySQL, Redis, PHP backend, WebSocket server) and requires no changes to the existing codebase.

## Glossary

- **Telegram_Service**: The Node.js microservice responsible for subscribing to Redis events and sending messages to Telegram.
- **Redis_Subscriber**: The component within the Telegram_Service that maintains a persistent connection to Redis and listens for Pub/Sub messages on configured channels.
- **Message_Formatter**: The component that transforms raw game event data into human-readable Telegram messages using predefined templates.
- **Telegram_Sender**: The component that sends formatted messages to the Telegram channel via the Telegram Bot API, handling rate limiting and retries.
- **Deduplication_Store**: A Redis-based key-value store using SET with TTL to prevent duplicate messages for the same game round.
- **Rate_Limiter**: A token-bucket or sliding-window mechanism that enforces a maximum of 20 messages per minute to the Telegram API.
- **Game_Round**: A single game session in a room ($1, $10, or $100) that transitions through states: waiting → active → spinning → finished.
- **Room**: One of three game tiers identified by bet amount: 1, 10, or 100 (USD).

## Requirements

### Requirement 1: Redis Pub/Sub Subscription

**User Story:** As the platform operator, I want the Telegram service to subscribe to Redis Pub/Sub channels in real time, so that Telegram messages are triggered immediately when game events occur without polling.

#### Acceptance Criteria

1. WHEN the Telegram_Service starts, THE Redis_Subscriber SHALL establish a persistent connection to Redis and subscribe to the `game:finished` and `bet:placed` channels.
2. WHEN a message is published on the `game:finished` channel, THE Redis_Subscriber SHALL parse the JSON payload containing `round_id`, `winner_id`, `room`, and `winner_net` fields.
3. WHEN a message is published on the `bet:placed` channel, THE Redis_Subscriber SHALL parse the JSON payload containing `round_id`, `user_id`, `amount`, and `room` fields.
4. IF the Redis connection is lost, THEN THE Redis_Subscriber SHALL attempt to reconnect with exponential backoff starting at 1 second up to a maximum of 30 seconds.
5. IF a Redis Pub/Sub message contains invalid JSON, THEN THE Telegram_Service SHALL log the error and discard the message without crashing.

### Requirement 2: Game Finished Message

**User Story:** As the platform operator, I want a formatted message posted to Telegram when a game round finishes, so that the channel audience sees the winner and game results in real time.

#### Acceptance Criteria

1. WHEN a `game:finished` event is received, THE Telegram_Service SHALL query MySQL to fetch the round details (`total_pot`, `winner_net`, `room`, `started_at`, `finished_at`) from the `game_rounds` table and the winner nickname from the `users` table.
2. WHEN round details are fetched successfully, THE Message_Formatter SHALL produce a message in the following format:
   ```
   🏆 Game finished!
   🎰 Room ${room}
   💰 Bank: ${total_pot} USD
   👥 Players: ${players_count}
   🥇 Winner: ${winner_nickname}
   💵 Won: ${winner_net} USD
   🎉 Congratulations!
   👇 Play now: https://anora.bet
   ```
3. WHEN the formatted message is ready, THE Telegram_Sender SHALL send the message to the configured Telegram channel.
4. IF the round is not found in MySQL, THEN THE Telegram_Service SHALL log a warning and skip sending the message.
5. IF the winner has no nickname set, THEN THE Message_Formatter SHALL use a masked version of the winner identifier instead of displaying null.

### Requirement 3: New Game Started Message

**User Story:** As the platform operator, I want a message posted to Telegram when a new game round becomes active with enough players, so that the channel audience is encouraged to join.

#### Acceptance Criteria

1. WHEN a `bet:placed` event is received and the round transitions to `active` status (verified by querying MySQL), THE Telegram_Service SHALL trigger a "new game started" message.
2. WHEN a new game started message is triggered, THE Message_Formatter SHALL produce a message in the following format:
   ```
   🔥 New game started!
   🎰 Room ${room}
   💰 Bank: ${total_pot} USD
   👥 Players: ${players_count}
   🎯 Join the game and win the pot!
   👇 Play now: https://anora.bet
   ```
3. THE Deduplication_Store SHALL ensure that only one "new game started" message is sent per round by storing a key `posted:start:{round_id}` with a TTL of 3600 seconds.

### Requirement 4: Deduplication

**User Story:** As the platform operator, I want to prevent duplicate Telegram messages for the same game event, so that the channel remains clean and professional.

#### Acceptance Criteria

1. WHEN a `game:finished` event is received, THE Deduplication_Store SHALL check for the existence of key `posted:finish:{round_id}` in Redis before sending the message.
2. IF the deduplication key exists, THEN THE Telegram_Service SHALL skip sending the message and log a debug entry.
3. WHEN a message is sent successfully, THE Deduplication_Store SHALL store the deduplication key with a TTL of 3600 seconds.
4. IF the Redis deduplication check fails due to a connection error, THEN THE Telegram_Service SHALL proceed with sending the message to avoid missing events.

### Requirement 5: Rate Limiting

**User Story:** As the platform operator, I want the service to respect Telegram API rate limits, so that the bot is not blocked by Telegram for sending too many messages.

#### Acceptance Criteria

1. THE Rate_Limiter SHALL enforce a maximum of `TELEGRAM_RATE_LIMIT` messages per minute (default: 20) to the Telegram API.
2. WHEN the rate limit is reached, THE Telegram_Sender SHALL queue the message and delay sending until the rate limit window allows the next message.
3. THE Rate_Limiter SHALL use a sliding window algorithm to distribute messages evenly within the rate limit window.

### Requirement 6: Retry on Failure

**User Story:** As the platform operator, I want the service to retry failed Telegram API calls, so that transient network issues do not cause missed messages.

#### Acceptance Criteria

1. IF the Telegram Bot API returns an HTTP error (status code >= 400 excluding 400 and 403), THEN THE Telegram_Sender SHALL retry the request up to 3 times with exponential backoff (1s, 2s, 4s delays).
2. IF all 3 retry attempts fail, THEN THE Telegram_Service SHALL log an error with the round_id, HTTP status code, and response body.
3. IF the Telegram Bot API returns HTTP 429 (Too Many Requests), THEN THE Telegram_Sender SHALL wait for the duration specified in the `retry_after` response field before retrying.
4. IF the Telegram Bot API returns HTTP 400 (Bad Request) or HTTP 403 (Forbidden), THEN THE Telegram_Sender SHALL log the error and skip retrying, as these indicate a configuration problem.

### Requirement 7: MySQL Data Enrichment

**User Story:** As the platform operator, I want the service to fetch additional game data from MySQL, so that Telegram messages contain complete information (player count, winner nickname) not available in the Redis event payload.

#### Acceptance Criteria

1. THE Telegram_Service SHALL maintain a MySQL connection pool with a maximum of 5 connections to the existing anora database.
2. WHEN enriching a `game:finished` event, THE Telegram_Service SHALL execute a query joining `game_rounds`, `game_bets`, and `users` tables to fetch `total_pot`, player count (COUNT DISTINCT user_id from game_bets), and winner nickname.
3. WHEN enriching a `bet:placed` event for a "new game started" check, THE Telegram_Service SHALL query the `game_rounds` table for the round status and `total_pot`, and `game_bets` for the player count.
4. IF the MySQL connection fails, THEN THE Telegram_Service SHALL log the error and skip the message rather than crashing.

### Requirement 8: Docker Integration

**User Story:** As the platform operator, I want the Telegram service to run as a Docker container in the existing docker-compose stack, so that deployment is consistent with the rest of the platform.

#### Acceptance Criteria

1. THE Telegram_Service SHALL be defined as a new service in `docker-compose.yml` with dependencies on the `redis` and `mysql` services using health check conditions.
2. THE Telegram_Service SHALL read configuration from environment variables: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_RATE_LIMIT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `DB_WRITE_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`.
3. THE Telegram_Service SHALL connect to the existing `anora-network` Docker network.
4. THE Telegram_Service Docker image SHALL be built from a Dockerfile located at `docker/telegram/Dockerfile`.

### Requirement 9: Graceful Shutdown

**User Story:** As the platform operator, I want the service to shut down cleanly on SIGTERM/SIGINT, so that in-flight messages are completed and connections are closed properly during deployments.

#### Acceptance Criteria

1. WHEN a SIGTERM or SIGINT signal is received, THE Telegram_Service SHALL stop accepting new events from Redis Pub/Sub.
2. WHEN shutting down, THE Telegram_Service SHALL wait for any in-flight Telegram API requests to complete with a maximum timeout of 10 seconds.
3. WHEN all in-flight requests are completed or the timeout is reached, THE Telegram_Service SHALL close the Redis connection and MySQL connection pool, then exit with code 0.

### Requirement 10: Logging and Observability

**User Story:** As the platform operator, I want structured JSON logs from the Telegram service, so that I can monitor its health and debug issues using the same tooling as the rest of the platform.

#### Acceptance Criteria

1. THE Telegram_Service SHALL output structured JSON logs to stdout, consistent with the platform logging format (timestamp, level, message, context).
2. WHEN a Telegram message is sent successfully, THE Telegram_Service SHALL log an info entry with `round_id`, `room`, and `message_type` (game_started or game_finished).
3. WHEN a Telegram API call fails, THE Telegram_Service SHALL log an error entry with `round_id`, `room`, `http_status`, and `error_message`.
4. WHEN the service starts, THE Telegram_Service SHALL log an info entry confirming the Redis subscription channels and Telegram chat ID.
5. THE Telegram_Service SHALL log a warning when the rate limiter delays a message, including the delay duration in milliseconds.

### Requirement 11: Configuration Validation

**User Story:** As the platform operator, I want the service to validate its configuration at startup, so that misconfiguration is caught immediately rather than causing silent failures.

#### Acceptance Criteria

1. WHEN the Telegram_Service starts, THE Telegram_Service SHALL validate that `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` environment variables are set and non-empty.
2. IF any required environment variable is missing, THEN THE Telegram_Service SHALL log an error describing the missing variable and exit with code 1.
3. WHEN the Telegram_Service starts, THE Telegram_Service SHALL validate the bot token by calling the Telegram `getMe` API endpoint.
4. IF the `getMe` call fails, THEN THE Telegram_Service SHALL log an error and exit with code 1.
