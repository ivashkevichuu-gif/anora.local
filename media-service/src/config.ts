import dotenv from 'dotenv';
dotenv.config();

export const config = {
  redis: {
    host: process.env.REDIS_HOST || 'redis',
    port: parseInt(process.env.REDIS_PORT || '6379', 10),
    password: process.env.REDIS_PASSWORD || undefined,
  },
  db: {
    host: process.env.DB_HOST || 'mysql',
    user: process.env.DB_USER || 'anora',
    password: process.env.DB_PASS || '',
    database: process.env.DB_NAME || 'anora',
  },
  telegram: {
    botToken: process.env.TELEGRAM_BOT_TOKEN || '',
    chatId: process.env.TELEGRAM_CHAT_ID || '',
  },
  instagram: {
    accessToken: process.env.INSTAGRAM_ACCESS_TOKEN || '',
    userId: process.env.INSTAGRAM_USER_ID || '',
  },
  video: {
    outputDir: process.env.VIDEO_OUTPUT_DIR || './out',
    publicUrl: process.env.VIDEO_PUBLIC_URL || 'https://anora.bet/media',
    width: 1080,
    height: 1920,
    fps: 30,
    durationInFrames: 360, // 12 seconds at 30fps
  },
  logLevel: process.env.LOG_LEVEL || 'info',
} as const;
