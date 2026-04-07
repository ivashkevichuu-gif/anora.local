'use strict';

/**
 * Structured JSON logger — writes to stdout.
 *
 * Output format: { timestamp (ISO 8601), level, message, context }
 * Levels: debug, info, warning, error
 * Respects LOG_LEVEL from config to filter output.
 *
 * Feature: telegram-autopost
 * Validates: Requirements 10.1, 10.2, 10.3, 10.4, 10.5
 */

const LEVELS = { debug: 0, info: 1, warning: 2, error: 3 };

function createLogger(logLevel) {
  const minLevel = LEVELS[logLevel] !== undefined ? LEVELS[logLevel] : LEVELS.info;

  function write(level, message, context) {
    if (LEVELS[level] < minLevel) return;
    const entry = JSON.stringify({
      timestamp: new Date().toISOString(),
      level,
      message,
      context: context || {},
    });
    process.stdout.write(entry + '\n');
  }

  return {
    debug: (message, context) => write('debug', message, context),
    info: (message, context) => write('info', message, context),
    warning: (message, context) => write('warning', message, context),
    error: (message, context) => write('error', message, context),
  };
}

module.exports = { createLogger, LEVELS };
