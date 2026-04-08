import path from 'path';
import fs from 'fs';
import { bundle } from '@remotion/bundler';
import { renderMedia, selectComposition } from '@remotion/renderer';
import { config } from './config';
import { logger } from './logger';
import type { RoundDetails } from './db';

const ENTRY_POINT = path.resolve('/app/src/remotion/index.ts');

let bundled: string | null = null;

async function ensureBundle(): Promise<string> {
  if (bundled) return bundled;
  logger.info('Bundling Remotion project...');
  bundled = await bundle({
    entryPoint: ENTRY_POINT,
    onProgress: (progress: number) => {
      if (progress % 25 === 0) logger.debug(`Bundle progress: ${progress}%`);
    },
  });
  logger.info('Remotion bundle ready');
  return bundled;
}

export async function renderFinishedGameVideo(round: RoundDetails): Promise<string> {
  const outputDir = config.video.outputDir;
  if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
  }

  const outputPath = path.join(outputDir, `game_${round.id}_${Date.now()}.mp4`);

  const bundleLocation = await ensureBundle();

  const inputProps = {
    room: {
      name: `Room $${round.room}`,
      room: round.room,
      total_bank: round.total_pot,
      winner: round.winner_nickname,
      winner_net: round.winner_net,
      players: round.players,
    },
  };

  logger.info('Rendering video', { roundId: round.id, outputPath });

  const composition = await selectComposition({
    serveUrl: bundleLocation,
    id: 'FinishedGameVideo',
    inputProps,
  });

  await renderMedia({
    composition,
    serveUrl: bundleLocation,
    codec: 'h264',
    outputLocation: outputPath,
    inputProps,
    chromiumOptions: { disableWebSecurity: true },
    onProgress: ({ progress }) => {
      if (Math.round(progress * 100) % 25 === 0) {
        logger.debug(`Render progress: ${Math.round(progress * 100)}%`);
      }
    },
  });

  logger.info('Video rendered', { roundId: round.id, outputPath });
  return outputPath;
}
