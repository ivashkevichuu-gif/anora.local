import React from 'react';
import { AbsoluteFill, useCurrentFrame, interpolate, Sequence } from 'remotion';

interface Player {
  nickname: string;
  total_bet: number;
  percent: number;
  is_winner: boolean;
}

interface RoomData {
  name: string;
  total_bank: number;
  winner: string;
  winner_net: number;
  players: Player[];
  room: number;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function avatarColor(name: string): string {
  let hash = 0;
  for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
  const colors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#db2777', '#0891b2'];
  return colors[Math.abs(hash) % colors.length];
}

function easeCasino(t: number): number {
  if (t < 0.15) return 3.33 * t * t;
  if (t < 0.75) return 0.075 + (t - 0.15) * 1.542;
  const p = (t - 0.75) / 0.25;
  return 0.925 + (1 - Math.pow(1 - p, 3)) * 0.075;
}

// ── Constants ────────────────────────────────────────────────────────────────

const FPS = 30;
const TILE_W = 80;
const TILE_GAP = 10;
const TILE_STEP = TILE_W + TILE_GAP;
const REEL_W = 900; // visible reel width
const REEL_CENTER = REEL_W / 2;
const STRIP_LEN = 200;
const WINNER_IDX = 150;
const TARGET_X = REEL_CENTER - (WINNER_IDX * TILE_STEP + TILE_W / 2);

// Timeline (in frames at 30fps)
const COUNTDOWN_START = 0;
const COUNTDOWN_END = 90;       // 0-3s: countdown
const SPIN_START = 90;
const SPIN_END = 255;            // 3-8.5s: carousel spin
const RESULT_START = 255;
const RESULT_END = 300;          // 8.5-10s: winner reveal
const TABLE_START = 300;         // 10-12s: previous game table

// ── Seeded random for deterministic strip ────────────────────────────────────

function seededRandom(seed: number): () => number {
  let s = seed;
  return () => {
    s = (s * 16807 + 0) % 2147483647;
    return (s - 1) / 2147483646;
  };
}

function buildStrip(players: Player[], winnerName: string): Player[] {
  const rand = seededRandom(42);
  const totalWeight = players.reduce((s, p) => s + p.total_bet, 0);
  const pick = (): Player => {
    let r = rand() * totalWeight;
    for (const p of players) {
      r -= p.total_bet;
      if (r <= 0) return p;
    }
    return players[players.length - 1];
  };
  const strip: Player[] = Array.from({ length: STRIP_LEN }, () => ({ ...pick() }));
  const winner = players.find(p => p.nickname === winnerName) || players[0];
  strip[WINNER_IDX] = { ...winner, is_winner: true };
  return strip;
}

// ── Main Component ───────────────────────────────────────────────────────────

export const FinishedGameVideo: React.FC<{ room: RoomData }> = ({ room }) => {
  const frame = useCurrentFrame();
  const strip = React.useMemo(
    () => buildStrip(room.players, room.winner),
    [room.players, room.winner]
  );

  return (
    <AbsoluteFill style={{
      background: 'linear-gradient(180deg, #0B0F1A 0%, #111827 50%, #0B0F1A 100%)',
      fontFamily: "'Inter', 'Segoe UI', sans-serif",
      overflow: 'hidden',
    }}>
      {/* Ambient glow */}
      <div style={{
        position: 'absolute', width: 600, height: 600, borderRadius: '50%',
        background: 'radial-gradient(circle, rgba(124,58,237,0.06) 0%, transparent 70%)',
        top: -200, right: -200, filter: 'blur(80px)',
      }} />

      {/* ANORA header */}
      <div style={{
        position: 'absolute', top: 60, width: '100%', textAlign: 'center',
        fontSize: 32, fontWeight: 900, letterSpacing: 8, color: '#00E5FF',
        textShadow: '0 0 20px rgba(0,229,255,0.4)',
        opacity: interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' }),
      }}>
        ANORA.BET
      </div>

      {/* Room label */}
      <div style={{
        position: 'absolute', top: 110, width: '100%', textAlign: 'center',
        fontSize: 20, color: '#6B7280', letterSpacing: 3,
        opacity: interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' }),
      }}>
        ROOM ${room.room}
      </div>

      {/* Phase 1: Countdown */}
      <Sequence from={COUNTDOWN_START} durationInFrames={COUNTDOWN_END - COUNTDOWN_START}>
        <CountdownPhase pot={room.total_bank} players={room.players} />
      </Sequence>

      {/* Phase 2: Carousel spin */}
      <Sequence from={SPIN_START} durationInFrames={SPIN_END - SPIN_START}>
        <CarouselPhase strip={strip} />
      </Sequence>

      {/* Phase 3: Winner reveal */}
      <Sequence from={RESULT_START} durationInFrames={RESULT_END - RESULT_START}>
        <WinnerReveal
          winner={room.winner}
          winnerNet={room.winner_net}
          pot={room.total_bank}
          strip={strip}
        />
      </Sequence>

      {/* Phase 4: Results table */}
      <Sequence from={TABLE_START} durationInFrames={360 - TABLE_START}>
        <ResultsTable players={room.players} winner={room.winner} pot={room.total_bank} />
      </Sequence>

      {/* Footer */}
      <div style={{
        position: 'absolute', bottom: 50, width: '100%', textAlign: 'center',
        fontSize: 18, color: '#4B5563', letterSpacing: 2,
        opacity: interpolate(frame, [330, 350], [0, 0.7], { extrapolateRight: 'clamp' }),
      }}>
        anora.bet · Play & Win
      </div>
    </AbsoluteFill>
  );
};

// ── Phase 1: Countdown 3...2...1 ─────────────────────────────────────────────

function CountdownPhase({ pot, players }: { pot: number; players: Player[] }) {
  const frame = useCurrentFrame();

  // Each number lasts 30 frames (1 second)
  const countdownNum = frame < 30 ? 3 : frame < 60 ? 2 : 1;
  const localFrame = frame % 30;

  const numScale = interpolate(localFrame, [0, 5, 25, 30], [0.3, 1.2, 1, 0.8], { extrapolateRight: 'clamp' });
  const numOpacity = interpolate(localFrame, [0, 5, 25, 30], [0, 1, 1, 0], { extrapolateRight: 'clamp' });

  const numColor = countdownNum > 2 ? '#00ff88' : countdownNum > 1 ? '#f59e0b' : '#ef4444';
  const glowColor = countdownNum > 2 ? 'rgba(0,255,136,0.5)' : countdownNum > 1 ? 'rgba(245,158,11,0.5)' : 'rgba(239,68,68,0.6)';

  // Progress bar
  const progress = 1 - (frame / 90);

  // Pot display
  const potOpacity = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
      {/* Pot */}
      <div style={{
        position: 'absolute', top: 200, textAlign: 'center', opacity: potOpacity,
      }}>
        <div style={{ fontSize: 16, color: '#6B7280', letterSpacing: 4, marginBottom: 8 }}>
          TOTAL POT
        </div>
        <div style={{
          fontSize: 72, fontWeight: 900,
          background: 'linear-gradient(135deg, #00ff88, #a855f7)',
          WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent',
          filter: 'drop-shadow(0 0 20px rgba(0,255,136,0.4))',
        }}>
          ${pot.toFixed(2)}
        </div>
      </div>

      {/* Draw in label */}
      <div style={{
        position: 'absolute', top: 420, fontSize: 16, color: '#6B7280',
        letterSpacing: 4, textTransform: 'uppercase',
      }}>
        DRAW IN
      </div>

      {/* Countdown number */}
      <div style={{
        position: 'absolute', top: 460,
        fontSize: 160, fontWeight: 900, color: numColor,
        textShadow: `0 0 40px ${glowColor}`,
        transform: `scale(${numScale})`,
        opacity: numOpacity,
      }}>
        {countdownNum}
      </div>

      {/* Progress bar */}
      <div style={{
        position: 'absolute', top: 700, width: 600, height: 6,
        borderRadius: 3, background: 'rgba(255,255,255,0.08)',
      }}>
        <div style={{
          width: `${progress * 100}%`, height: '100%', borderRadius: 3,
          background: numColor, boxShadow: `0 0 10px ${numColor}`,
          transition: 'width 0.1s linear',
        }} />
      </div>

      {/* Player avatars */}
      <div style={{
        position: 'absolute', top: 780, display: 'flex', gap: 20,
        justifyContent: 'center', width: '100%',
      }}>
        {players.slice(0, 6).map((p, i) => {
          const pOpacity = interpolate(frame, [i * 5, i * 5 + 15], [0, 1], { extrapolateRight: 'clamp' });
          const pSlide = interpolate(frame, [i * 5, i * 5 + 15], [30, 0], { extrapolateRight: 'clamp' });
          return (
            <div key={i} style={{
              display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 6,
              opacity: pOpacity, transform: `translateY(${pSlide}px)`,
            }}>
              <div style={{
                width: 56, height: 56, borderRadius: '50%',
                background: avatarColor(p.nickname),
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 16, fontWeight: 700, color: '#fff',
                boxShadow: `0 0 12px ${avatarColor(p.nickname)}44`,
              }}>
                {p.nickname.slice(0, 2).toUpperCase()}
              </div>
              <div style={{ fontSize: 13, color: '#9CA3AF', maxWidth: 70, textAlign: 'center', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                {p.nickname}
              </div>
              <div style={{ fontSize: 12, color: '#00ff88', fontWeight: 700 }}>
                ${p.total_bet.toFixed(2)}
              </div>
            </div>
          );
        })}
      </div>
    </AbsoluteFill>
  );
}

// ── Phase 2: Casino Carousel ─────────────────────────────────────────────────

function CarouselPhase({ strip }: { strip: Player[] }) {
  const frame = useCurrentFrame();
  const totalFrames = SPIN_END - SPIN_START; // 165 frames = 5.5s

  const progress = Math.min(frame / totalFrames, 1);
  const eased = easeCasino(progress);
  const currentX = TARGET_X * eased;

  // Motion blur based on velocity
  const prevX = TARGET_X * easeCasino(Math.max(0, (frame - 1) / totalFrames));
  const dx = Math.abs(currentX - prevX);
  const blur = Math.min(dx / 6, 10);

  // Revealed state (last 10 frames)
  const revealed = frame > totalFrames - 10;

  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
      {/* Status label */}
      <div style={{
        position: 'absolute', top: 200,
        fontSize: 18, color: '#6B7280', letterSpacing: 4, textTransform: 'uppercase',
      }}>
        🎰 SELECTING WINNER…
      </div>

      {/* Carousel container */}
      <div style={{
        position: 'absolute', top: 400,
        width: REEL_W, height: 120,
        overflow: 'hidden', borderRadius: 20,
        background: 'rgba(0,0,0,0.35)',
        border: revealed ? '1px solid rgba(245,158,11,0.4)' : '1px solid rgba(255,255,255,0.08)',
        boxShadow: revealed ? '0 0 40px rgba(245,158,11,0.15)' : 'none',
      }}>
        {/* Edge fades */}
        <div style={{
          position: 'absolute', top: 0, bottom: 0, left: 0, width: 100, zIndex: 10,
          background: 'linear-gradient(to right, rgba(0,0,0,0.9), transparent)',
        }} />
        <div style={{
          position: 'absolute', top: 0, bottom: 0, right: 0, width: 100, zIndex: 10,
          background: 'linear-gradient(to left, rgba(0,0,0,0.9), transparent)',
        }} />

        {/* Gold center line */}
        <div style={{
          position: 'absolute', top: 0, bottom: 0, left: REEL_CENTER - 1, width: 2,
          background: '#FFC857', boxShadow: '0 0 12px #FFC857', zIndex: 20,
        }} />
        {/* Top arrow */}
        <div style={{
          position: 'absolute', top: 0, left: REEL_CENTER - 8, zIndex: 20,
          width: 0, height: 0,
          borderLeft: '8px solid transparent', borderRight: '8px solid transparent',
          borderTop: '10px solid #FFC857',
        }} />
        {/* Bottom arrow */}
        <div style={{
          position: 'absolute', bottom: 0, left: REEL_CENTER - 8, zIndex: 20,
          width: 0, height: 0,
          borderLeft: '8px solid transparent', borderRight: '8px solid transparent',
          borderBottom: '10px solid #FFC857',
        }} />

        {/* Strip */}
        <div style={{
          position: 'absolute', top: 15, left: 0,
          display: 'flex', gap: TILE_GAP,
          transform: `translateX(${currentX}px)`,
          filter: blur > 0.5 ? `blur(${blur.toFixed(1)}px)` : 'none',
        }}>
          {strip.map((p, i) => {
            const isWinnerTile = i === WINNER_IDX && revealed;
            return (
              <div key={i} style={{
                width: TILE_W, height: 90, flexShrink: 0, borderRadius: 14,
                display: 'flex', flexDirection: 'column',
                alignItems: 'center', justifyContent: 'center', gap: 4,
                background: isWinnerTile ? 'rgba(245,158,11,0.2)' : 'rgba(255,255,255,0.04)',
                border: isWinnerTile ? '2px solid rgba(245,158,11,0.8)' : '1px solid rgba(255,255,255,0.06)',
                boxShadow: isWinnerTile ? '0 0 30px rgba(245,158,11,0.6)' : 'none',
              }}>
                <div style={{
                  width: 40, height: 40, borderRadius: '50%',
                  background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 13, fontWeight: 700, color: '#fff',
                  boxShadow: isWinnerTile ? `0 0 15px ${avatarColor(p.nickname)}` : 'none',
                }}>
                  {p.nickname.slice(0, 2).toUpperCase()}
                </div>
                <span style={{
                  fontSize: 11, maxWidth: TILE_W - 8, overflow: 'hidden',
                  textOverflow: 'ellipsis', whiteSpace: 'nowrap', textAlign: 'center',
                  color: isWinnerTile ? '#FFC857' : '#6B7280',
                  fontWeight: isWinnerTile ? 700 : 400,
                }}>
                  {p.nickname}
                </span>
              </div>
            );
          })}
        </div>
      </div>
    </AbsoluteFill>
  );
}

// ── Phase 3: Winner Reveal ───────────────────────────────────────────────────

function WinnerReveal({ winner, winnerNet, pot, strip }: {
  winner: string; winnerNet: number; pot: number; strip: Player[];
}) {
  const frame = useCurrentFrame();

  // Flash effect
  const flashOpacity = interpolate(frame, [0, 5, 15], [0.7, 0.3, 0], { extrapolateRight: 'clamp' });

  // Avatar animation
  const avatarScale = interpolate(frame, [5, 15, 20], [0.3, 1.1, 1], { extrapolateRight: 'clamp' });
  const avatarOpacity = interpolate(frame, [5, 12], [0, 1], { extrapolateRight: 'clamp' });

  // Name
  const nameOpacity = interpolate(frame, [12, 20], [0, 1], { extrapolateRight: 'clamp' });

  // Amount
  const amountScale = interpolate(frame, [18, 28, 32], [0.5, 1.15, 1], { extrapolateRight: 'clamp' });
  const amountOpacity = interpolate(frame, [18, 25], [0, 1], { extrapolateRight: 'clamp' });

  // Pulsing glow on avatar
  const glowPulse = interpolate(frame % 30, [0, 15, 30], [20, 50, 20]);

  // Carousel stays visible at final position
  const revealed = true;

  return (
    <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
      {/* Screen flash */}
      <div style={{
        position: 'absolute', inset: 0, opacity: flashOpacity,
        background: 'radial-gradient(ellipse at center, rgba(245,158,11,0.5) 0%, transparent 65%)',
        pointerEvents: 'none',
      }} />

      {/* Carousel frozen at winner position */}
      <div style={{
        position: 'absolute', top: 250,
        width: REEL_W, height: 120, overflow: 'hidden', borderRadius: 20,
        background: 'rgba(0,0,0,0.35)',
        border: '1px solid rgba(245,158,11,0.4)',
        boxShadow: '0 0 40px rgba(245,158,11,0.15)',
      }}>
        <div style={{
          position: 'absolute', top: 0, bottom: 0, left: 0, width: 100, zIndex: 10,
          background: 'linear-gradient(to right, rgba(0,0,0,0.9), transparent)',
        }} />
        <div style={{
          position: 'absolute', top: 0, bottom: 0, right: 0, width: 100, zIndex: 10,
          background: 'linear-gradient(to left, rgba(0,0,0,0.9), transparent)',
        }} />
        <div style={{
          position: 'absolute', top: 0, bottom: 0, left: REEL_CENTER - 1, width: 2,
          background: '#FFC857', boxShadow: '0 0 12px #FFC857', zIndex: 20,
        }} />
        <div style={{
          position: 'absolute', top: 15, left: 0,
          display: 'flex', gap: TILE_GAP,
          transform: `translateX(${TARGET_X}px)`,
        }}>
          {strip.map((p, i) => {
            const isW = i === WINNER_IDX;
            return (
              <div key={i} style={{
                width: TILE_W, height: 90, flexShrink: 0, borderRadius: 14,
                display: 'flex', flexDirection: 'column',
                alignItems: 'center', justifyContent: 'center', gap: 4,
                background: isW ? 'rgba(245,158,11,0.2)' : 'rgba(255,255,255,0.04)',
                border: isW ? '2px solid rgba(245,158,11,0.8)' : '1px solid rgba(255,255,255,0.06)',
                boxShadow: isW ? '0 0 30px rgba(245,158,11,0.6)' : 'none',
              }}>
                <div style={{
                  width: 40, height: 40, borderRadius: '50%',
                  background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 13, fontWeight: 700, color: '#fff',
                }}>
                  {p.nickname.slice(0, 2).toUpperCase()}
                </div>
                <span style={{
                  fontSize: 11, color: isW ? '#FFC857' : '#6B7280',
                  fontWeight: isW ? 700 : 400,
                  maxWidth: TILE_W - 8, overflow: 'hidden',
                  textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                }}>
                  {p.nickname}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      {/* 🏆 Winner label */}
      <div style={{
        position: 'absolute', top: 420, fontSize: 18, color: '#6B7280',
        letterSpacing: 4, opacity: nameOpacity,
      }}>
        🏆 WINNER
      </div>

      {/* Winner avatar */}
      <div style={{
        position: 'absolute', top: 470,
        width: 80, height: 80, borderRadius: '50%',
        background: avatarColor(winner),
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: 28, fontWeight: 900, color: '#fff',
        transform: `scale(${avatarScale})`,
        opacity: avatarOpacity,
        boxShadow: `0 0 ${glowPulse}px rgba(245,158,11,0.6)`,
      }}>
        {winner.slice(0, 2).toUpperCase()}
      </div>

      {/* Winner name */}
      <div style={{
        position: 'absolute', top: 570,
        fontSize: 42, fontWeight: 900, color: '#FFC857',
        opacity: nameOpacity,
      }}>
        {winner}
      </div>

      {/* Win amount */}
      <div style={{
        position: 'absolute', top: 630,
        fontSize: 56, fontWeight: 900,
        background: 'linear-gradient(135deg, #f59e0b, #fbbf24)',
        WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent',
        filter: 'drop-shadow(0 0 14px rgba(245,158,11,0.7))',
        transform: `scale(${amountScale})`,
        opacity: amountOpacity,
      }}>
        +${winnerNet.toFixed(2)}
      </div>

      <div style={{
        position: 'absolute', top: 710,
        fontSize: 20, color: '#6B7280', opacity: amountOpacity,
      }}>
        wins the pot!
      </div>

      {/* Explosion particles */}
      {Array.from({ length: 20 }, (_, i) => {
        const angle = (i / 20) * Math.PI * 2;
        const dist = interpolate(frame, [0, 20], [0, 100 + (i % 3) * 40], { extrapolateRight: 'clamp' });
        const x = 540 + Math.cos(angle) * dist;
        const y = 510 + Math.sin(angle) * dist;
        const opacity = interpolate(frame, [0, 8, 30], [0, 0.8, 0], { extrapolateRight: 'clamp' });
        const size = 3 + (i % 4) * 2;
        const colors = ['#FFC857', '#00E5FF', '#7A5CFF', '#FF6B9D'];
        return (
          <div key={i} style={{
            position: 'absolute', left: x, top: y,
            width: size, height: size, borderRadius: '50%',
            background: colors[i % colors.length], opacity,
            boxShadow: `0 0 ${size * 3}px ${colors[i % colors.length]}`,
          }} />
        );
      })}
    </AbsoluteFill>
  );
}

// ── Phase 4: Results Table (Previous Game style) ─────────────────────────────

function ResultsTable({ players, winner, pot }: {
  players: Player[]; winner: string; pot: number;
}) {
  const frame = useCurrentFrame();
  const cardOpacity = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });
  const cardSlide = interpolate(frame, [0, 15], [30, 0], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ justifyContent: 'flex-start', alignItems: 'center', paddingTop: 180 }}>
      <div style={{
        width: 900, borderRadius: 24, padding: 40,
        background: 'rgba(255,255,255,0.02)',
        border: '1px solid rgba(255,255,255,0.06)',
        opacity: cardOpacity,
        transform: `translateY(${cardSlide}px)`,
      }}>
        {/* Header */}
        <div style={{
          display: 'flex', alignItems: 'center', gap: 10, marginBottom: 30,
        }}>
          <span style={{ fontSize: 20, color: '#FFC857' }}>🏆</span>
          <span style={{ fontSize: 18, fontWeight: 600, color: '#6B7280' }}>
            Game Results
          </span>
        </div>

        {/* Winner highlight card */}
        <div style={{
          display: 'flex', alignItems: 'center', gap: 16, padding: 20,
          borderRadius: 16, marginBottom: 30,
          background: 'rgba(245,158,11,0.08)',
          border: '1px solid rgba(245,158,11,0.25)',
          boxShadow: '0 0 20px rgba(245,158,11,0.1)',
        }}>
          <div style={{
            width: 52, height: 52, borderRadius: '50%',
            background: avatarColor(winner),
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 18, fontWeight: 700, color: '#fff',
            boxShadow: `0 0 15px ${avatarColor(winner)}88`,
          }}>
            {winner.slice(0, 2).toUpperCase()}
          </div>
          <div>
            <div style={{ fontSize: 22, fontWeight: 700, color: '#FFC857' }}>
              {winner}
            </div>
            <div style={{ fontSize: 14, color: '#6B7280' }}>Winner</div>
          </div>
          <div style={{
            marginLeft: 'auto', fontSize: 28, fontWeight: 900, color: '#FFC857',
          }}>
            +${pot.toFixed(2)}
          </div>
        </div>

        {/* Players grid */}
        <div style={{
          display: 'flex', flexWrap: 'wrap', gap: 10,
        }}>
          {players.map((p, i) => {
            const pOpacity = interpolate(frame, [10 + i * 4, 18 + i * 4], [0, 1], { extrapolateRight: 'clamp' });
            const isW = p.nickname === winner;
            return (
              <div key={i} style={{
                display: 'flex', alignItems: 'center', gap: 10,
                padding: '8px 16px', borderRadius: 12,
                background: isW ? 'rgba(245,158,11,0.1)' : 'rgba(255,255,255,0.04)',
                border: isW ? '1px solid rgba(245,158,11,0.3)' : '1px solid rgba(255,255,255,0.06)',
                opacity: pOpacity,
              }}>
                <div style={{
                  width: 28, height: 28, borderRadius: '50%',
                  background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 10, fontWeight: 700, color: '#fff',
                }}>
                  {p.nickname.slice(0, 2).toUpperCase()}
                </div>
                <span style={{
                  fontSize: 15, color: isW ? '#FFC857' : '#9CA3AF',
                  fontWeight: isW ? 700 : 400,
                }}>
                  {p.nickname}
                </span>
                <span style={{ fontSize: 13, color: '#00ff88', fontWeight: 700 }}>
                  {p.percent}%
                </span>
                <span style={{ fontSize: 13, color: '#6B7280' }}>
                  ${p.total_bet.toFixed(2)}
                </span>
              </div>
            );
          })}
        </div>

        {/* Provably Fair badge */}
        <div style={{
          marginTop: 30, display: 'flex', alignItems: 'center', gap: 8,
        }}>
          <div style={{
            fontSize: 13, padding: '4px 12px', borderRadius: 20,
            background: 'rgba(0,255,136,0.08)',
            border: '1px solid rgba(0,255,136,0.2)',
            color: '#00ff88',
          }}>
            ✓ Provably Fair · Verifiable
          </div>
        </div>
      </div>
    </AbsoluteFill>
  );
}
