import { logger } from '../logger';
import { resetInstagramDailyCount } from '../db';

const RESET_INTERVAL_MS = 60 * 60 * 1000; // Check every hour

let lastResetDate = '';

/**
 * Daily reset — resets Instagram posts_today counter once per day.
 * Runs on an interval, checks if the date has changed.
 */
export function startDailyReset(): NodeJS.Timeout {
  lastResetDate = new Date().toISOString().slice(0, 10);

  const timer = setInterval(async () => {
    const today = new Date().toISOString().slice(0, 10);
    if (today !== lastResetDate) {
      try {
        await resetInstagramDailyCount();
        lastResetDate = today;
        logger.info('Instagram daily counter reset', { date: today });
      } catch (err: any) {
        logger.error('Daily reset failed', { error: err.message });
      }
    }
  }, RESET_INTERVAL_MS);

  logger.info('Daily reset cron started');
  return timer;
}
