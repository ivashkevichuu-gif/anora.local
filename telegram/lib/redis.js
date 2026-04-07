'use strict';

/**
 * Redis module — Pub/Sub subscriber + deduplication client.
 *
 * Two ioredis connections:
 *   subscriber — enters Pub/Sub mode for game:finished, bet:placed
 *   client     — regular connection for dedup SET/EXISTS
 *
 * Exponential backoff reconnect: min(times * 1000, 30000).
 *
 * Feature: telegram-autopost
 * Validates: Requirements 1.1, 1.4, 3.3, 4.1, 4.2, 4.3, 4.4
 */

const Redis = require('ioredis');

function createConnections(config, logger) {
  const opts = {
    host: config.redis.host,
    port: config.redis.port,
    password: config.redis.password || undefined,
    lazyConnect: true,
    retryStrategy(times) {
      const delay = Math.min(times * 1000, 30000);
      logger.info('Redis reconnecting', { attempt: times, delay_ms: delay });
      return delay;
    },
  };

  const subscriber = new Redis(opts);
  const client = new Redis(opts);

  subscriber.on('error', (err) => {
    logger.error('Redis subscriber error', { error: err.message });
  });

  client.on('error', (err) => {
    logger.error('Redis client error', { error: err.message });
  });

  /**
   * Check if a dedup key exists. Returns false on connection error (Req 4.4).
   */
  async function isDuplicate(key) {
    try {
      const exists = await client.exists(key);
      return exists === 1;
    } catch (err) {
      logger.warning('Redis dedup check failed, proceeding with send', {
        key,
        error: err.message,
      });
      return false;
    }
  }

  /**
   * Mark an event as sent with TTL.
   */
  async function markSent(key, ttlSeconds) {
    try {
      await client.set(key, '1', 'EX', ttlSeconds);
    } catch (err) {
      logger.warning('Redis markSent failed', { key, error: err.message });
    }
  }

  /**
   * Disconnect both connections.
   */
  async function shutdown() {
    try { subscriber.disconnect(); } catch (_) {}
    try { client.disconnect(); } catch (_) {}
  }

  return { subscriber, client, isDuplicate, markSent, shutdown };
}

module.exports = { createConnections };
