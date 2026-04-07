'use strict';

/**
 * Message formatter — emoji templates for Telegram messages.
 *
 * Feature: telegram-autopost
 */

function roomLabel(room) {
  return `$${room}`;
}

function safeNickname(nickname) {
  if (!nickname || nickname === 'null' || nickname.trim() === '') return 'Anonymous';
  return nickname;
}

function formatGameFinished({ room, total_pot, players, winner_nickname, winner_net }) {
  const lines = [
    '🏆 Game finished!',
    '',
    `🎰 Room ${roomLabel(room)}`,
    `💰 Bank: $${total_pot} USD`,
    '',
    '👥 Players:',
  ];

  for (const p of players) {
    const nick = safeNickname(p.nickname);
    if (p.is_winner) {
      lines.push(`  🥇 ${nick} — $${p.total_bet.toFixed(2)} (${p.percent}%) ← WINNER`);
    } else {
      lines.push(`  � ${nick} — $${p.total_bet.toFixed(2)} (${p.percent}%)`);
    }
  }

  lines.push('');
  lines.push(`💵 Won: $${winner_net} USD`);
  lines.push('');
  lines.push('🎉 Congratulations!');
  lines.push('👇 Play now: https://anora.bet');

  return lines.join('\n');
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
