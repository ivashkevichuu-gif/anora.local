import { Worker, Job } from 'bullmq';
import { config } from '../config';
import { logger } from '../logger';
import {
  fetchRoundDetails,
  updateMediaPost,
  incrementInstagramPostCount,
  getInstagramSettings,
} from '../db';
import { renderFinishedGameVideo } from '../renderer';
import { publishReel } from '../publishers/instagram';

/**
 * Instagram Publisher Worker — processes instagram-publish queue.
 *
 * Pipeline: fetch round → re-check filters → render video → upload → publish reel
 */

export function startInstagramPublisher(): Worker {
  const worker = new Worker(
    'instagram-publish',
    async (job: Job) => {
      const { roundId, postId } = job.data;

      logger.info('Processing instagram job', { roundId, postId });

      try {
        // Re-check settings (may have changed since queued)
        const igSettings = await getInstagramSettings();
        if (!igSettings?.enabled) {
          await updateMediaPost(postId, { status: 'failed', error_message: 'Instagram disabled' });
          return;
        }

        if (igSettings.posts_today >= igSettings.max_posts_per_day) {
          await updateMediaPost(postId, { status: 'failed', error_message: 'Daily limit reached' });
          return;
        }

        const round = await fetchRoundDetails(roundId);
        if (!round) throw new Error(`Round ${roundId} not found`);

        // Re-check filters
        if (!igSettings.allowed_rooms.includes(String(round.room))) {
          await updateMediaPost(postId, { status: 'failed', error_message: 'Room not allowed' });
          return;
        }

        if (round.total_pot < parseFloat(igSettings.min_win_amount)) {
          await updateMediaPost(postId, { status: 'failed', error_message: 'Below min win amount' });
          return;
        }

        // Render video
        await updateMediaPost(postId, { status: 'rendering', attempts: job.attemptsMade + 1 });
        const videoPath = await renderFinishedGameVideo(round);
        await updateMediaPost(postId, { video_path: videoPath });

        // Build public URL for the video
        const videoFilename = videoPath.split('/').pop() || videoPath.split('\\').pop();
        const videoUrl = `${config.video.publicUrl}/${videoFilename}`;

        // Publish to Instagram
        await updateMediaPost(postId, { status: 'publishing' });

        const caption = [
          `🏆 Game Finished! Room $${round.room}`,
          `💰 Bank: $${round.total_pot.toFixed(2)}`,
          `🥇 Winner: ${round.winner_nickname}`,
          `💵 Won: $${round.winner_net.toFixed(2)}`,
          '',
          '🎮 Play at anora.bet',
          '',
          '#anora #gambling #win #crypto #casino #reels',
        ].join('\n');

        const result = await publishReel(videoUrl, caption);

        if (result.success) {
          await updateMediaPost(postId, {
            status: 'published',
            external_id: result.id,
            published_at: new Date(),
          });
          await incrementInstagramPostCount();
          logger.info('Instagram reel published', { roundId, postId, mediaId: result.id });
        } else {
          throw new Error(result.error || 'Publish failed');
        }
      } catch (err: any) {
        logger.error('Instagram publish failed', { roundId, postId, error: err.message });
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
      concurrency: 1, // One render at a time (CPU intensive)
      limiter: {
        max: 5,
        duration: 60000,
      },
    }
  );

  worker.on('completed', (job) => {
    logger.debug('Instagram job completed', { jobId: job.id });
  });

  worker.on('failed', (job, err) => {
    logger.error('Instagram job failed', { jobId: job?.id, error: err.message });
  });

  logger.info('Instagram publisher worker started');
  return worker;
}
