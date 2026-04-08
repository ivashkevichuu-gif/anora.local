import Redis from 'ioredis';
import { Queue } from 'bullmq';
import { config } from '../config';
import { logger } from '../logger';
import {
  fetchRoundDetails,
  getMediaSettings,
  getInstagramSettings,
  getTelegramSettings,
  isDuplicatePost,
  createMediaPost,
} from '../db';

/**
 * Room Watcher — subscribes to Redis Pub/Sub events from game_engine
 * and dispatches jobs to BullMQ queues based on settings.
 *
 * Events:
 *   game:finished → check filters → queue instagram/telegram jobs
 *   bet:placed    → check if active → queue telegram new game job
 */

const redisOpts = {
  host: config.redis.host,
  port: config.redis.port,
  password: config.redis.password,
  maxRetriesPerRequest: null,
};

export async function startRoomWatcher() {
  const subscriber = new Redis(redisOpts);
  const instagramQueue = new Queue('instagram-publish', { connection: redisOpts });
  const telegramQueue = new Queue('telegram-publish', { connection: redisOpts });

  await subscriber.subscribe('game:finished', 'bet:placed');
  logger.info('RoomWatcher subscribed to game:finished, bet:placed');

  subscriber.on('message', async (channel: string, message: string) => {
    let data: any;
    try {
      data = JSON.parse(message);
    } catch {
      logger.error('Invalid JSON in Pub/Sub', { channel, raw: message });
      return;
    }

    try {
      if (channel === 'game:finished') {
        await handleGameFinished(data, instagramQueue, telegramQueue);
      } else if (channel === 'bet:placed') {
        await handleBetPlaced(data, telegramQueue);
      }
    } catch (err: any) {
      logger.error('RoomWatcher handler error', { channel, error: err.message });
    }
  });

  return { subscriber, instagramQueue, telegramQueue };
}

async function handleGameFinished(
  data: { round_id: number },
  instagramQueue: Queue,
  telegramQueue: Queue
) {
  const roundId = data.round_id;
  if (!roundId) return;

  logger.info('Game finished event', { roundId });

  const mediaSettings = await getMediaSettings();
  if (!mediaSettings) return;

  const round = await fetchRoundDetails(roundId);
  if (!round || round.status !== 'finished') return;

  // Telegram finished room
  if (mediaSettings.telegram_enabled) {
    const tgSettings = await getTelegramSettings();
    if (tgSettings?.enabled && tgSettings.post_finished_rooms) {
      const isDup = await isDuplicatePost(roundId, 'telegram', 'finished_room');
      if (!isDup) {
        const postId = await createMediaPost(roundId, 'telegram', 'finished_room');
        await telegramQueue.add('finished-room', { roundId, postId }, {
          attempts: 3,
          backoff: { type: 'exponential', delay: 5000 },
          removeOnComplete: 100,
          removeOnFail: 50,
        });
        logger.info('Queued telegram finished room', { roundId, postId });
      }
    }
  }

  // Instagram reel
  if (mediaSettings.instagram_enabled) {
    const igSettings = await getInstagramSettings();
    if (igSettings && shouldPostToInstagram(igSettings, round)) {
      const isDup = await isDuplicatePost(roundId, 'instagram', 'finished_room');
      if (!isDup) {
        const postId = await createMediaPost(roundId, 'instagram', 'finished_room');
        await instagramQueue.add('finished-room', { roundId, postId }, {
          attempts: 3,
          backoff: { type: 'exponential', delay: 10000 },
          removeOnComplete: 50,
          removeOnFail: 50,
        });
        logger.info('Queued instagram reel', { roundId, postId });
      }
    }
  }
}

async function handleBetPlaced(data: { round_id: number; room?: number }, telegramQueue: Queue) {
  const roundId = data.round_id;
  if (!roundId) return;

  const mediaSettings = await getMediaSettings();
  if (!mediaSettings?.telegram_enabled) return;

  const tgSettings = await getTelegramSettings();
  if (!tgSettings?.enabled || !tgSettings.post_new_rooms) return;

  // Only post when game becomes active (2+ players)
  const round = await fetchRoundDetails(roundId);
  if (!round || round.status !== 'active') return;

  const isDup = await isDuplicatePost(roundId, 'telegram', 'new_room');
  if (isDup) return;

  const postId = await createMediaPost(roundId, 'telegram', 'new_room');
  await telegramQueue.add('new-room', {
    roundId,
    postId,
    room: round.room,
    totalPot: round.total_pot,
    playersCount: round.players.length,
  }, {
    attempts: 3,
    backoff: { type: 'exponential', delay: 5000 },
    removeOnComplete: 100,
    removeOnFail: 50,
  });

  logger.info('Queued telegram new room', { roundId, postId });
}

function shouldPostToInstagram(settings: any, round: any): boolean {
  return (
    settings.enabled &&
    settings.allowed_rooms.includes(String(round.room)) &&
    round.total_pot >= parseFloat(settings.min_win_amount) &&
    settings.posts_today < settings.max_posts_per_day
  );
}
