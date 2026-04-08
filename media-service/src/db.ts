import mysql from 'mysql2/promise';
import { config } from './config';
import { logger } from './logger';

let pool: mysql.Pool;

export function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool({
      host: config.db.host,
      user: config.db.user,
      password: config.db.password,
      database: config.db.database,
      waitForConnections: true,
      connectionLimit: 5,
      queueLimit: 0,
    });
    logger.info('MySQL pool created');
  }
  return pool;
}

export interface RoundDetails {
  id: number;
  room: number;
  status: string;
  total_pot: number;
  winner_id: number | null;
  winner_nickname: string;
  winner_net: number;
  players: Array<{
    user_id: number;
    nickname: string;
    total_bet: number;
    percent: number;
    is_winner: boolean;
  }>;
}

export async function fetchRoundDetails(roundId: number): Promise<RoundDetails | null> {
  const db = getPool();

  const [rounds] = await db.query(
    `SELECT gr.id, gr.room, gr.status, gr.winner_id,
            COALESCE(gr.final_bets_snapshot, '[]') AS snapshot
     FROM game_rounds gr WHERE gr.id = ?`,
    [roundId]
  );

  const rows = rounds as any[];
  if (!rows.length) return null;

  const round = rows[0];
  let snapshot: any[] = [];
  try {
    snapshot = typeof round.snapshot === 'string' ? JSON.parse(round.snapshot) : round.snapshot;
  } catch { snapshot = []; }

  // Fetch bets with nicknames
  const [bets] = await db.query(
    `SELECT gb.user_id, COALESCE(u.nickname, 'Anonymous') AS nickname,
            SUM(gb.amount) AS total_bet
     FROM game_bets gb
     LEFT JOIN users u ON u.id = gb.user_id
     WHERE gb.round_id = ?
     GROUP BY gb.user_id, u.nickname
     ORDER BY total_bet DESC`,
    [roundId]
  );

  const betRows = bets as any[];
  const totalPot = betRows.reduce((s: number, b: any) => s + parseFloat(b.total_bet), 0);

  const players = betRows.map((b: any) => ({
    user_id: b.user_id,
    nickname: b.nickname || 'Anonymous',
    total_bet: parseFloat(b.total_bet),
    percent: totalPot > 0 ? Math.round((parseFloat(b.total_bet) / totalPot) * 100) : 0,
    is_winner: b.user_id === round.winner_id,
  }));

  const winner = players.find(p => p.is_winner);
  const commission = totalPot * 0.02;
  const referralBonus = totalPot * 0.01;
  const winnerNet = totalPot - commission - referralBonus;

  return {
    id: round.id,
    room: round.room,
    status: round.status,
    total_pot: totalPot,
    winner_id: round.winner_id,
    winner_nickname: winner?.nickname || 'Anonymous',
    winner_net: parseFloat(winnerNet.toFixed(2)),
    players,
  };
}

export async function getInstagramSettings() {
  const db = getPool();
  const [rows] = await db.query('SELECT * FROM instagram_settings WHERE id = 1');
  const r = (rows as any[])[0];
  if (!r) return null;
  r.allowed_rooms = typeof r.allowed_rooms === 'string' ? JSON.parse(r.allowed_rooms) : r.allowed_rooms;
  r.enabled = !!r.enabled;
  return r;
}

export async function getTelegramSettings() {
  const db = getPool();
  const [rows] = await db.query('SELECT * FROM telegram_settings WHERE id = 1');
  const r = (rows as any[])[0];
  if (!r) return null;
  r.enabled = !!r.enabled;
  r.post_new_rooms = !!r.post_new_rooms;
  r.post_finished_rooms = !!r.post_finished_rooms;
  return r;
}

export async function getMediaSettings() {
  const db = getPool();
  const [rows] = await db.query('SELECT * FROM media_settings WHERE id = 1');
  return (rows as any[])[0] || null;
}

export async function incrementInstagramPostCount() {
  const db = getPool();
  await db.query('UPDATE instagram_settings SET posts_today = posts_today + 1 WHERE id = 1');
}

export async function resetInstagramDailyCount() {
  const db = getPool();
  await db.query('UPDATE instagram_settings SET posts_today = 0, last_reset_at = NOW() WHERE id = 1');
}

export async function isDuplicatePost(roundId: number, platform: string, postType: string): Promise<boolean> {
  const db = getPool();
  const [rows] = await db.query(
    `SELECT id FROM media_posts
     WHERE round_id = ? AND platform = ? AND post_type = ? AND status IN ('queued','rendering','publishing','published')
     LIMIT 1`,
    [roundId, platform, postType]
  );
  return (rows as any[]).length > 0;
}

export async function createMediaPost(roundId: number, platform: string, postType: string): Promise<number> {
  const db = getPool();
  const [result] = await db.query(
    'INSERT INTO media_posts (round_id, platform, post_type, status) VALUES (?, ?, ?, ?)',
    [roundId, platform, postType, 'queued']
  );
  return (result as any).insertId;
}

export async function updateMediaPost(id: number, data: Record<string, any>) {
  const db = getPool();
  const sets = Object.keys(data).map(k => `${k} = ?`).join(', ');
  const vals = Object.values(data);
  await db.query(`UPDATE media_posts SET ${sets} WHERE id = ?`, [...vals, id]);
}

export async function shutdownDb() {
  if (pool) await pool.end();
}
