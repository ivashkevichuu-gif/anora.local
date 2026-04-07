'use strict';

/**
 * Configuration module — reads and validates environment variables.
 *
 * Required: TELEGRAM_BOT_TOKEN, TELEGRAM_CHAT_ID (exit 1 if missing).
 * Optional vars have sensible defaults for Docker environment.
 *
 * Feature: telegram-autopost
 * Validates: Requirements 8.2, 11.1, 11.2
 */

const required = ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'];

for (const name of required) {
  const val = process.env[name];
  if (!val || val.trim() === '') {
    process.stderr.write(
      JSON.stringify({
        timestamp: new Date().toISOString(),
        level: 'error',
        message: `Missing required environment variable: ${name}`,
        context: {},
      }) + '\n'
    );
    process.exit(1);
  }
}

const config = Object.freeze({
  telegram: Object.freeze({
    botToken: process.env.TELEGRAM_BOT_TOKEN,
    chatId: process.env.TELEGRAM_CHAT_ID,
    rateLimit: parseInt(process.env.TELEGRAM_RATE_LIMIT || '20', 10),
  }),
  redis: Object.freeze({
    host: process.env.REDIS_HOST || 'redis',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || '',
  }),
  db: Object.freeze({
    host: process.env.DB_WRITE_HOST || 'mysql',
    user: process.env.DB_USER || '',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'anora',
  }),
  logLevel: process.env.LOG_LEVEL || 'info',
});

module.exports = config;
