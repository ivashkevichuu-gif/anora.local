'use strict';

/**
 * Telegram sender — Bot API client with rate limiting and retry.
 *
 * createSender(config, logger) → sender object with:
 *   send(text, metadata)  — POST sendMessage with rate limiting + retry
 *   validateBot()         — call getMe at startup, throw on failure
 *   shutdown()            — drain queue, resolve pending (10s max)
 *   pendingCount()        — return queued message count
 *
 * Sliding window rate limiter: track timestamps, enforce rateLimit/60s.
 * Bounded queue (100 messages), drop oldest on overflow.
 * Retry: 400/403 → no retry; 429 → wait retry_after; other 4xx/5xx → 3x backoff (1s, 2s, 4s).
 *
 * Feature: telegram-autopost
 * Validates: Requirements 5.1, 5.2, 5.3, 6.1, 6.2, 6.3, 6.4, 11.3, 11.4
 */

const https = require('https');

const TELEGRAM_API_BASE = 'https://api.telegram.org';
const MAX_QUEUE_SIZE = 100;
const RETRY_DELAYS = [1000, 2000, 4000];
const WINDOW_MS = 60000;

function createSender(config, logger) {
  const { botToken, chatId, rateLimit } = config.telegram;
  const sentTimestamps = [];
  const queue = [];
  let draining = false;
  let shutdownRequested = false;
  let drainTimer = null;

  function apiUrl(method) {
    return `${TELEGRAM_API_BASE}/bot${botToken}/${method}`;
  }

  /**
   * Low-level HTTPS request to Telegram API.
   */
  function request(method, body) {
    return new Promise((resolve, reject) => {
      const data = JSON.stringify(body);
      const parsed = new URL(apiUrl(method));

      const req = https.request(
        {
          hostname: parsed.hostname,
          path: parsed.pathname,
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(data),
          },
        },
        (res) => {
          let responseBody = '';
          res.on('data', (chunk) => { responseBody += chunk; });
          res.on('end', () => {
            resolve({ status: res.statusCode, body: responseBody });
          });
        }
      );

      req.on('error', reject);
      req.write(data);
      req.end();
    });
  }

  /**
   * Validate bot token by calling getMe.
   */
  async function validateBot() {
    const res = await request('getMe', {});
    if (res.status !== 200) {
      let parsed;
      try { parsed = JSON.parse(res.body); } catch (_) { parsed = {}; }
      throw new Error(
        `Telegram getMe failed: HTTP ${res.status} — ${parsed.description || res.body}`
      );
    }
    const data = JSON.parse(res.body);
    logger.info('Telegram bot validated', {
      bot_username: data.result && data.result.username,
    });
  }

  /**
   * Send a message with retry logic.
   */
  async function sendWithRetry(text, metadata, attempt) {
    attempt = attempt || 0;

    const res = await request('sendMessage', {
      chat_id: chatId,
      text,
      parse_mode: 'HTML',
      disable_web_page_preview: true,
    });

    if (res.status >= 200 && res.status < 400) {
      logger.info('Telegram message sent', metadata);
      return;
    }

    // 400 or 403 — config problem, no retry
    if (res.status === 400 || res.status === 403) {
      logger.error('Telegram API error (no retry)', {
        ...metadata,
        http_status: res.status,
        response: res.body,
      });
      return;
    }

    // 429 — rate limited by Telegram
    if (res.status === 429) {
      let retryAfter = 5;
      try {
        const parsed = JSON.parse(res.body);
        retryAfter = (parsed.parameters && parsed.parameters.retry_after) || 5;
      } catch (_) {}
      logger.warning('Telegram 429 rate limited', {
        ...metadata,
        retry_after: retryAfter,
      });
      await sleep(retryAfter * 1000);
      return sendWithRetry(text, metadata, attempt);
    }

    // Other 4xx/5xx — retry with backoff
    if (attempt < RETRY_DELAYS.length) {
      logger.warning('Telegram API error, retrying', {
        ...metadata,
        http_status: res.status,
        attempt: attempt + 1,
        delay_ms: RETRY_DELAYS[attempt],
      });
      await sleep(RETRY_DELAYS[attempt]);
      return sendWithRetry(text, metadata, attempt + 1);
    }

    // All retries exhausted
    logger.error('Telegram send failed after retries', {
      ...metadata,
      http_status: res.status,
      response: res.body,
    });
  }

  /**
   * Clean old timestamps from sliding window.
   */
  function cleanWindow() {
    const cutoff = Date.now() - WINDOW_MS;
    while (sentTimestamps.length > 0 && sentTimestamps[0] <= cutoff) {
      sentTimestamps.shift();
    }
  }

  /**
   * Calculate delay until next send is allowed.
   */
  function getDelay() {
    cleanWindow();
    if (sentTimestamps.length < rateLimit) return 0;
    const oldest = sentTimestamps[0];
    return oldest + WINDOW_MS - Date.now();
  }

  /**
   * Process the next item in the queue.
   */
  function processQueue() {
    if (queue.length === 0) {
      draining = false;
      return;
    }

    const delay = getDelay();
    if (delay > 0) {
      logger.warning('Rate limiter delaying message', { delay_ms: delay });
      drainTimer = setTimeout(processQueue, delay);
      return;
    }

    const { text, metadata, resolve } = queue.shift();
    sentTimestamps.push(Date.now());
    draining = true;

    sendWithRetry(text, metadata)
      .then(() => { if (resolve) resolve(); })
      .catch((err) => {
        logger.error('Unexpected send error', { error: err.message, ...metadata });
        if (resolve) resolve();
      })
      .finally(() => {
        if (queue.length > 0 && !shutdownRequested) {
          const nextDelay = getDelay();
          drainTimer = setTimeout(processQueue, Math.max(nextDelay, 0));
        } else {
          draining = false;
        }
      });
  }

  /**
   * Public send — enqueue with rate limiting.
   */
  function send(text, metadata) {
    return new Promise((resolve) => {
      if (shutdownRequested) {
        resolve();
        return;
      }

      // Bounded queue — drop oldest on overflow
      if (queue.length >= MAX_QUEUE_SIZE) {
        const dropped = queue.shift();
        logger.warning('Message queue overflow, dropping oldest', {
          dropped_metadata: dropped.metadata,
        });
        if (dropped.resolve) dropped.resolve();
      }

      queue.push({ text, metadata, resolve });

      if (!draining) {
        processQueue();
      }
    });
  }

  /**
   * Return queued message count.
   */
  function pendingCount() {
    return queue.length;
  }

  /**
   * Shutdown — drain queue with 10s timeout.
   */
  function shutdown() {
    shutdownRequested = true;
    if (drainTimer) {
      clearTimeout(drainTimer);
      drainTimer = null;
    }

    return new Promise((resolve) => {
      if (queue.length === 0 && !draining) {
        resolve();
        return;
      }

      // Drain remaining
      const timeout = setTimeout(() => {
        // Force resolve remaining
        while (queue.length > 0) {
          const item = queue.shift();
          if (item.resolve) item.resolve();
        }
        resolve();
      }, 10000);

      // Try to process remaining quickly
      function checkDone() {
        if (queue.length === 0 && !draining) {
          clearTimeout(timeout);
          resolve();
          return;
        }
        setTimeout(checkDone, 100);
      }
      checkDone();
    });
  }

  return { send, validateBot, shutdown, pendingCount };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = { createSender };
