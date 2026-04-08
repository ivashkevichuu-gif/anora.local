import { config } from './config';
import { logger } from './logger';
import { startRoomWatcher } from './workers/roomWatcher';
import { startTelegramPublisher } from './workers/telegramPublisher';
import { startInstagramPublisher } from './workers/instagramPublisher';
import { startDailyReset } from './cron/dailyReset';
import { shutdownDb } from './db';

/**
 * ANORA Media Service — entry point.
 *
 * Starts:
 *   1. Room Watcher (Redis Pub/Sub → BullMQ queues)
 *   2. Telegram Publisher Worker
 *   3. Instagram Publisher Worker
 *   4. Daily Reset Cron
 *
 * Graceful shutdown on SIGTERM/SIGINT.
 */

async function main() {
  logger.info('Starting ANORA Media Service', {
    redis: `${config.redis.host}:${config.redis.port}`,
    db: `${config.db.host}/${config.db.database}`,
  });

  // Start workers
  const watcher = await startRoomWatcher();
  const telegramWorker = startTelegramPublisher();
  const instagramWorker = startInstagramPublisher();
  const dailyResetTimer = startDailyReset();

  logger.info('All workers started');

  // Graceful shutdown
  let shuttingDown = false;

  async function shutdown(signal: string) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info(`${signal} received, shutting down...`);

    clearInterval(dailyResetTimer);

    try {
      // Unsubscribe from Redis
      await watcher.subscriber.unsubscribe();
      await watcher.subscriber.quit();

      // Close queues
      await watcher.instagramQueue.close();
      await watcher.telegramQueue.close();

      // Close workers
      await telegramWorker.close();
      await instagramWorker.close();

      // Close DB
      await shutdownDb();

      logger.info('Shutdown complete');
      process.exit(0);
    } catch (err: any) {
      logger.error('Shutdown error', { error: err.message });
      process.exit(1);
    }
  }

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

main().catch((err) => {
  logger.error('Fatal startup error', { error: err.message, stack: err.stack });
  process.exit(1);
});
