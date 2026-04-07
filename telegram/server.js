'use strict';

/**
 * ANORA Telegram Autopost Service — entry point.
 *
 * Startup: config → validate bot (getMe) → Redis → MySQL → subscribe → log.
 * Event routing:
 *   game:finished → dedup → MySQL enrich → format → send
 *   bet:placed    → dedup → MySQL status check → if active → format → send
 * Graceful shutdown on SIGTERM/SIGINT.
 *
 * Feature: telegram-autopost
 * Validates: Requirements 1.1, 1.2, 1.3, 1.5, 2.1, 2.3, 3.1, 3.2, 3.3,
 *            4.1, 4.2, 4.3, 9.1, 9.2, 9.3, 10.4
 */

const config = require('./lib/config');
const { createLogger } = require('./lib/logger');
const { createConnections } = require('./lib/redis');
const { createPool, fetchRoundDetails, fetchRoundStatus, shutdown: shutdownDb } = require('./lib/mysql');
const { formatGameFinished, formatGameStarted } = require('./lib/formatter');
const { createSender } = require('./lib/telegram');

const DEDUP_TTL = 3600; // 1 hour

async function main() {
  const logger = createLogger(config.logLevel);

  // 1. Validate Telegram bot token
  const sender = createSender(config, logger);
  try {
    await sender.validateBot();
  } catch (err) {
    logger.error('Bot validation failed', { error: err.message });
    process.exit(1);
  }

  // 2. Connect Redis
  const redis = createConnections(config, logger);
  try {
    await redis.subscriber.connect();
    await redis.client.connect();
    logger.info('Redis connected');
  } catch (err) {
    logger.error('Redis connection failed', { error: err.message });
    process.exit(1);
  }

  // 3. Create MySQL pool
  const pool = createPool(config);
  logger.info('MySQL pool created');

  // 4. Subscribe to channels
  await redis.subscriber.subscribe('game:finished', 'bet:placed');
  logger.info('Service started', {
    channels: ['game:finished', 'bet:placed'],
    chat_id: config.telegram.chatId,
    rate_limit: config.telegram.rateLimit,
  });

  // ── Event routing ───────────────────────────────────────────────────────

  redis.subscriber.on('message', async (channel, message) => {
    let data;
    try {
      data = JSON.parse(message);
    } catch (err) {
      logger.error('Invalid JSON in Pub/Sub message', {
        channel,
        raw: message,
        error: err.message,
      });
      return;
    }

    try {
      if (channel === 'game:finished') {
        await handleGameFinished(data, logger, redis, pool, sender);
      } else if (channel === 'bet:placed') {
        await handleBetPlaced(data, logger, redis, pool, sender);
      }
    } catch (err) {
      logger.error('Event handler error', {
        channel,
        round_id: data.round_id,
        error: err.message,
      });
    }
  });

  // ── Graceful shutdown ─────────────────────────────────────────────────

  let shuttingDown = false;

  async function shutdown() {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info('Shutting down...');

    // 1. Unsubscribe from Redis Pub/Sub
    try {
      await redis.subscriber.unsubscribe();
    } catch (_) {}

    // 2. Wait for in-flight Telegram requests (10s max)
    await sender.shutdown();

    // 3. Close Redis connections
    await redis.shutdown();

    // 4. Close MySQL pool
    try {
      await shutdownDb(pool);
    } catch (_) {}

    logger.info('Shutdown complete');
    process.exit(0);
  }

  process.on('SIGTERM', shutdown);
  process.on('SIGINT', shutdown);
}

// ── Event Handlers ────────────────────────────────────────────────────────

async function handleGameFinished(data, logger, redis, pool, sender) {
  const { round_id } = data;
  if (!round_id) {
    logger.warning('game:finished missing round_id', { data });
    return;
  }

  const dedupKey = `posted:finish:${round_id}`;

  // Dedup check
  const dup = await redis.isDuplicate(dedupKey);
  if (dup) {
    logger.debug('Duplicate game:finished, skipping', { round_id });
    return;
  }

  // MySQL enrich
  let details;
  try {
    details = await fetchRoundDetails(pool, round_id);
  } catch (err) {
    logger.error('MySQL fetchRoundDetails failed', { round_id, error: err.message });
    return;
  }

  if (!details) {
    logger.warning('Round not found in MySQL', { round_id });
    return;
  }

  // Format and send
  const text = formatGameFinished(details);
  await sender.send(text, {
    round_id,
    room: details.room,
    message_type: 'game_finished',
  });

  // Mark as sent
  await redis.markSent(dedupKey, DEDUP_TTL);
}

async function handleBetPlaced(data, logger, redis, pool, sender) {
  const { round_id } = data;
  if (!round_id) {
    logger.warning('bet:placed missing round_id', { data });
    return;
  }

  const dedupKey = `posted:start:${round_id}`;

  // Dedup check
  const dup = await redis.isDuplicate(dedupKey);
  if (dup) {
    logger.debug('Duplicate bet:placed start, skipping', { round_id });
    return;
  }

  // MySQL status check
  let status;
  try {
    status = await fetchRoundStatus(pool, round_id);
  } catch (err) {
    logger.error('MySQL fetchRoundStatus failed', { round_id, error: err.message });
    return;
  }

  if (!status) {
    logger.warning('Round not found in MySQL for bet:placed', { round_id });
    return;
  }

  // Only send if round is active
  if (status.status !== 'active') {
    logger.debug('Round not active, skipping bet:placed', {
      round_id,
      status: status.status,
    });
    return;
  }

  // Format and send
  const text = formatGameStarted(status);
  await sender.send(text, {
    round_id,
    room: status.room,
    message_type: 'game_started',
  });

  // Mark as sent
  await redis.markSent(dedupKey, DEDUP_TTL);
}

main().catch((err) => {
  process.stderr.write(
    JSON.stringify({
      timestamp: new Date().toISOString(),
      level: 'error',
      message: `Fatal startup error: ${err.message}`,
      context: { stack: err.stack },
    }) + '\n'
  );
  process.exit(1);
});

module.exports = { handleGameFinished, handleBetPlaced };
