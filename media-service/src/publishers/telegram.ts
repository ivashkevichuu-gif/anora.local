import https from 'https';
import { config } from '../config';
import { logger } from '../logger';
import type { RoundDetails } from '../db';

const API_BASE = 'https://api.telegram.org';

function apiUrl(method: string): string {
  return `${API_BASE}/bot${config.telegram.botToken}/${method}`;
}

function request(method: string, body: Record<string, any>): Promise<{ status: number; body: string }> {
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
        res.on('data', (chunk: string) => { responseBody += chunk; });
        res.on('end', () => resolve({ status: res.statusCode || 500, body: responseBody }));
      }
    );
    req.on('error', reject);
    req.write(data);
    req.end();
  });
}

function formatFinishedMessage(round: RoundDetails): string {
  const lines = [
    '🏆 <b>Game Finished!</b>',
    '',
    `🎰 Room $${round.room}`,
    `💰 Bank: <b>$${round.total_pot.toFixed(2)}</b>`,
    '',
    '👥 Players:',
  ];

  for (const p of round.players) {
    const icon = p.is_winner ? '🥇' : '👤';
    const suffix = p.is_winner ? ' ← WINNER' : '';
    lines.push(`  ${icon} ${p.nickname} — $${p.total_bet.toFixed(2)} (${p.percent}%)${suffix}`);
  }

  lines.push('');
  lines.push(`💵 Won: <b>$${round.winner_net.toFixed(2)}</b>`);
  lines.push('');
  lines.push('🎉 Congratulations!');

  return lines.join('\n');
}

function formatNewGameMessage(room: number, totalPot: number, playersCount: number): string {
  return [
    '🔥 <b>New Game Started!</b>',
    '',
    `🎰 Room $${room}`,
    `💰 Bank: <b>$${totalPot.toFixed(2)}</b>`,
    `👥 Players: ${playersCount}`,
    '',
    '🎯 Join the game and win the pot!',
  ].join('\n');
}

export async function sendFinishedRoom(round: RoundDetails): Promise<boolean> {
  if (!config.telegram.botToken || !config.telegram.chatId) {
    logger.warn('Telegram not configured, skipping');
    return false;
  }

  const text = formatFinishedMessage(round);

  try {
    const res = await request('sendMessage', {
      chat_id: config.telegram.chatId,
      text,
      parse_mode: 'HTML',
      disable_web_page_preview: true,
      reply_markup: {
        inline_keyboard: [[
          { text: '🎮 Play Now', url: 'https://anora.bet' },
        ]],
      },
    });

    if (res.status >= 200 && res.status < 400) {
      logger.info('Telegram finished room message sent', { roundId: round.id });
      return true;
    }

    logger.error('Telegram send failed', { status: res.status, body: res.body });
    return false;
  } catch (err: any) {
    logger.error('Telegram send error', { error: err.message });
    return false;
  }
}

export async function sendNewRoom(room: number, totalPot: number, playersCount: number): Promise<boolean> {
  if (!config.telegram.botToken || !config.telegram.chatId) {
    logger.warn('Telegram not configured, skipping');
    return false;
  }

  const text = formatNewGameMessage(room, totalPot, playersCount);

  try {
    const res = await request('sendMessage', {
      chat_id: config.telegram.chatId,
      text,
      parse_mode: 'HTML',
      disable_web_page_preview: true,
      reply_markup: {
        inline_keyboard: [[
          { text: '🎮 Join Now', url: 'https://anora.bet' },
        ]],
      },
    });

    if (res.status >= 200 && res.status < 400) {
      logger.info('Telegram new room message sent', { room });
      return true;
    }

    logger.error('Telegram new room send failed', { status: res.status, body: res.body });
    return false;
  } catch (err: any) {
    logger.error('Telegram new room send error', { error: err.message });
    return false;
  }
}
