import { Worker, Job } from 'bullmq';
import { config } from '../config';
import { logger } from '../logger';
import { fetchRoundDetails, updateMediaPost } from '../db';
import { sendFinishedRoom, sendNewRoom } from '../publishers/telegram';

/**
 * Telegram Publisher Worker — processes telegram-publish queue.
 *
 * Job types:
 *   finished-room — send finished game message
 *   new-room      — send new game started message
 */

export function startTelegramPublisher(): Worker {
  const worker = new Worker(
    'telegram-publish',
    async (job: Job) => {
      const { roundId, postId } = job.data;

      logger.info('Processing telegram job', { jobName: job.name, roundId, postId });

      try {
        await updateMediaPost(postId, { status: 'publishing', attempts: job.attemptsMade + 1 });

        let success = false;

        if (job.name === 'finished-room') {
          const round = await fetchRoundDetails(roundId);
          if (!round) throw new Error(`Round ${roundId} not found`);
          success = await sendFinishedRoom(round);
        } else if (job.name === 'new-room') {
          const { room, totalPot, playersCount } = job.data;
          success = await sendNewRoom(room, totalPot, playersCount);
        }

        if (success) {
          await updateMediaPost(postId, { status: 'published', published_at: new Date() });
          logger.info('Telegram post published', { roundId, postId, type: job.name });
        } else {
          throw new Error('Telegram send returned false');
        }
      } catch (err: any) {
        logger.error('Telegram publish failed', { roundId, postId, error: err.message });
        await updateMediaPost(postId, {
          status: 'failed',
          error_message: err.message,
          attempts: job.attemptsMade + 1,
        });
        throw err; // Let BullMQ handle retry
      }
    },
    {
      connection: {
        host: config.redis.host,
        port: config.redis.port,
        password: config.redis.password,
        maxRetriesPerRequest: null,
      },
      concurrency: 2,
      limiter: {
        max: 20,
        duration: 60000, // 20 messages per minute
      },
    }
  );

  worker.on('completed', (job) => {
    logger.debug('Telegram job completed', { jobId: job.id, name: job.name });
  });

  worker.on('failed', (job, err) => {
    logger.error('Telegram job failed', { jobId: job?.id, name: job?.name, error: err.message });
  });

  logger.info('Telegram publisher worker started');
  return worker;
}
