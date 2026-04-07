'use strict';

/**
 * MySQL module — connection pool and query helpers.
 *
 * Pool: mysql2 with max 5 connections.
 * fetchRoundDetails: fetches round + all players with bets and win percentages.
 * fetchRoundStatus: query game_rounds status + total_pot + player count for bet:placed.
 *
 * Feature: telegram-autopost
 */

const mysql = require('mysql2/promise');

function createPool(config) {
  return mysql.createPool({
    host: config.db.host,
    user: config.db.user,
    password: config.db.password,
    database: config.db.database,
    connectionLimit: 5,
    waitForConnections: true,
  });
}

const ROUND_SQL = `
  SELECT gr.total_pot, gr.winner_net, gr.room, gr.winner_id,
    COALESCE(u.nickname, CONCAT('Player_', SUBSTRING(u.email, 1, 3), '***')) AS winner_nickname
  FROM game_rounds gr
  LEFT JOIN users u ON u.id = gr.winner_id
  WHERE gr.id = ?
`;

const BETS_SQL = `
  SELECT gb.user_id, gb.amount,
    COALESCE(u.nickname, CONCAT('Player_', SUBSTRING(u.email, 1, 3), '***')) AS nickname
  FROM game_bets gb
  LEFT JOIN users u ON u.id = gb.user_id
  WHERE gb.round_id = ?
  ORDER BY gb.amount DESC
`;

const ROUND_STATUS_SQL = `
  SELECT gr.status, gr.total_pot, gr.room,
    (SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = gr.id) AS players_count
  FROM game_rounds gr WHERE gr.id = ?
`;

async function fetchRoundDetails(pool, roundId) {
  const [rows] = await pool.execute(ROUND_SQL, [roundId]);
  if (rows.length === 0) return null;
  const round = rows[0];

  const [bets] = await pool.execute(BETS_SQL, [roundId]);

  // Aggregate bets per player
  const map = {};
  for (const b of bets) {
    if (!map[b.user_id]) map[b.user_id] = { nickname: b.nickname, total_bet: 0, user_id: b.user_id };
    map[b.user_id].total_bet += parseFloat(b.amount);
  }

  const totalPot = parseFloat(round.total_pot) || 0;
  const players = Object.values(map).map(p => ({
    nickname: p.nickname,
    total_bet: p.total_bet,
    percent: totalPot > 0 ? ((p.total_bet / totalPot) * 100).toFixed(1) : '0.0',
    is_winner: p.user_id === round.winner_id,
  }));

  players.sort((a, b) => {
    if (a.is_winner && !b.is_winner) return -1;
    if (!a.is_winner && b.is_winner) return 1;
    return b.total_bet - a.total_bet;
  });

  return {
    total_pot: round.total_pot,
    winner_net: round.winner_net,
    room: round.room,
    winner_nickname: round.winner_nickname,
    players,
    players_count: players.length,
  };
}

async function fetchRoundStatus(pool, roundId) {
  const [rows] = await pool.execute(ROUND_STATUS_SQL, [roundId]);
  return rows.length > 0 ? rows[0] : null;
}

async function shutdown(pool) { await pool.end(); }

module.exports = { createPool, fetchRoundDetails, fetchRoundStatus, shutdown };
