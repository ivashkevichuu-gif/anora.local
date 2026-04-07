'use strict';

/**
 * MySQL module — connection pool and query helpers.
 *
 * Pool: mysql2 with max 5 connections.
 * fetchRoundDetails: JOIN game_rounds + game_bets + users for game:finished enrichment.
 * fetchRoundStatus: query game_rounds status + total_pot + player count for bet:placed.
 *
 * Feature: telegram-autopost
 * Validates: Requirements 2.1, 2.4, 2.5, 3.1, 7.1, 7.2, 7.3, 7.4
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

const ROUND_DETAILS_SQL = `
  SELECT
    gr.total_pot,
    gr.winner_net,
    gr.room,
    COALESCE(u.nickname, CONCAT('Player_', SUBSTRING(u.email, 1, 3), '***')) AS winner_nickname,
    (SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = gr.id) AS players_count
  FROM game_rounds gr
  LEFT JOIN users u ON u.id = gr.winner_id
  WHERE gr.id = ?
`;

const ROUND_STATUS_SQL = `
  SELECT
    gr.status,
    gr.total_pot,
    gr.room,
    (SELECT COUNT(DISTINCT user_id) FROM game_bets WHERE round_id = gr.id) AS players_count
  FROM game_rounds gr
  WHERE gr.id = ?
`;

async function fetchRoundDetails(pool, roundId) {
  const [rows] = await pool.execute(ROUND_DETAILS_SQL, [roundId]);
  return rows.length > 0 ? rows[0] : null;
}

async function fetchRoundStatus(pool, roundId) {
  const [rows] = await pool.execute(ROUND_STATUS_SQL, [roundId]);
  return rows.length > 0 ? rows[0] : null;
}

async function shutdown(pool) {
  await pool.end();
}

module.exports = { createPool, fetchRoundDetails, fetchRoundStatus, shutdown };
