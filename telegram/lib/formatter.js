'use strict';

/**
 * Message formatter — emoji templates for Telegram messages.
 *
 * formatGameFinished: winner announcement with room, bank, players, winner, net.
 * formatGameStarted: new game announcement with room, bank, players.
 * Room mapping: 1 → "$1", 10 → "$10", 100 → "$100".
 * Null-safe nickname handling.
 *
 * Feature: telegram-autopost
 * Validates: Requirements 2.2, 2.5, 3.2
 */

function roomLabel(room) {
  return `$${room}`;
}

function safeNickname(nickname) {
  if (!nickname || nickname === 'null' || nickname.trim() === '') {
    return 'Anonymous';
  }
  return nickname;
}

function formatGameFinished({ room, total_pot, players_count, winner_nickname, winner_net }) {
  const nick = safeNickname(winner_nickname);
  return [
    '🏆 Game finished!',
    '',
    `🎰 Room ${roomLabel(room)}`,
    `💰 Bank: $${total_pot} USD`,
    `👥 Players: ${players_count}`,
    `🥇 Winner: ${nick}`,
    `💵 Won: $${winner_net} USD`,
    '',
    '🎉 Congratulations!',
    '👇 Play now: https://anora.bet',
  ].join('\n');
}

function formatGameStarted({ room, total_pot, players_count }) {
  return [
    '🔥 New game started!',
    '',
    `🎰 Room ${roomLabel(room)}`,
    `💰 Bank: $${total_pot} USD`,
    `👥 Players: ${players_count}`,
    '',
    '🎯 Join the game and win the pot!',
    '👇 Play now: https://anora.bet',
  ].join('\n');
}

module.exports = { formatGameFinished, formatGameStarted, roomLabel, safeNickname };
