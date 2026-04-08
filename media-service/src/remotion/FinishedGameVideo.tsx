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

// Video: 1080x1920
const VW = 1080;
const VH = 1920;

const TILE_W = 80;
const TILE_GAP = 10;
const TILE_STEP = TILE_W + TILE_GAP;
const REEL_W = 900;
const REEL_CENTER = REEL_W / 2;
const STRIP_LEN = 200;
const WINNER_IDX = 150;
const TARGET_X = REEL_CENTER - (WINNER_IDX * TILE_STEP + TILE_W / 2);

// Timeline (frames at 30fps)
const COUNTDOWN_END = 90;
const SPIN_START = 90;
const SPIN_END = 255;
const RESULT_START = 255;
const RESULT_END = 300;
const TABLE_START = 300;

function seededRandom(seed: number): () => number {
  let s = seed;
  return () => { s = (s * 16807) % 2147483647; return (s - 1) / 2147483646; };
}

function buildStrip(players: Player[], winnerName: string): Player[] {
  const rand = seededRandom(42);
  const tw = players.reduce((s, p) => s + p.total_bet, 0);
  const pick = (): Player => {
    let r = rand() * tw;
    for (const p of players) { r -= p.total_bet; if (r <= 0) return p; }
    return players[players.length - 1];
  };
  const strip = Array.from({ length: STRIP_LEN }, () => ({ ...pick() }));
  strip[WINNER_IDX] = { ...(players.find(p => p.nickname === winnerName) || players[0]), is_winner: true };
  return strip;
}

// ── Main ─────────────────────────────────────────────────────────────────────

export const FinishedGameVideo: React.FC<{ room: RoomData }> = ({ room }) => {
  const frame = useCurrentFrame();
  const strip = React.useMemo(() => buildStrip(room.players, room.winner), [room.players, room.winner]);

  return (
    <AbsoluteFill style={{
      background: 'linear-gradient(180deg, #0B0F1A 0%, #111827 50%, #0B0F1A 100%)',
      fontFamily: "'Inter', 'Segoe UI', sans-serif",
      overflow: 'hidden',
    }}>
      <div style={{
        position: 'absolute', width: 600, height: 600, borderRadius: '50%',
        background: 'radial-gradient(circle, rgba(124,58,237,0.06) 0%, transparent 70%)',
        top: -200, right: -200, filter: 'blur(80px)',
      }} />
      <div style={{
        position: 'absolute', width: 500, height: 500, borderRadius: '50%',
        background: 'radial-gradient(circle, rgba(0,229,255,0.05) 0%, transparent 70%)',
        bottom: -150, left: -150, filter: 'blur(80px)',
      }} />

      {/* ANORA header — always visible */}
      <div style={{
        position: 'absolute', top: 80, width: '100%', textAlign: 'center',
        fontSize: 34, fontWeight: 900, letterSpacing: 8, color: '#00E5FF',
        textShadow: '0 0 20px rgba(0,229,255,0.4)',
        opacity: interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' }),
      }}>ANORA.BET</div>
      <div style={{
        position: 'absolute', top: 135, width: '100%', textAlign: 'center',
        fontSize: 20, color: '#6B7280', letterSpacing: 3,
        opacity: interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' }),
      }}>ROOM ${room.room}</div>

      <Sequence from={0} durationInFrames={COUNTDOWN_END}>
        <CountdownPhase pot={room.total_bank} players={room.players} />
      </Sequence>
      <Sequence from={SPIN_START} durationInFrames={SPIN_END - SPIN_START}>
        <CarouselPhase strip={strip} />
      </Sequence>
      <Sequence from={RESULT_START} durationInFrames={RESULT_END - RESULT_START}>
        <WinnerReveal winner={room.winner} winnerNet={room.winner_net} pot={room.total_bank} strip={strip} />
      </Sequence>
      <Sequence from={TABLE_START} durationInFrames={360 - TABLE_START}>
        <ResultsTable players={room.players} winner={room.winner} pot={room.total_bank} />
      </Sequence>

      {/* Footer */}
      <div style={{
        position: 'absolute', bottom: 60, width: '100%', textAlign: 'center',
        fontSize: 20, color: '#4B5563', letterSpacing: 2,
        opacity: interpolate(frame, [330, 350], [0, 0.7], { extrapolateRight: 'clamp' }),
      }}>anora.bet · Play & Win</div>
    </AbsoluteFill>
  );
};

// ── Phase 1: Countdown ───────────────────────────────────────────────────────
// Layout: Pot at ~25%, countdown number at center (~50%), progress bar at ~60%, players at ~70%

function CountdownPhase({ pot, players }: { pot: number; players: Player[] }) {
  const frame = useCurrentFrame();
  const countdownNum = frame < 30 ? 3 : frame < 60 ? 2 : 1;
  const localFrame = frame % 30;
  const numScale = interpolate(localFrame, [0, 5, 25, 30], [0.3, 1.2, 1, 0.8], { extrapolateRight: 'clamp' });
  const numOpacity = interpolate(localFrame, [0, 5, 25, 30], [0, 1, 1, 0], { extrapolateRight: 'clamp' });
  const numColor = countdownNum > 2 ? '#00ff88' : countdownNum > 1 ? '#f59e0b' : '#ef4444';
  const glowColor = countdownNum > 2 ? 'rgba(0,255,136,0.5)' : countdownNum > 1 ? 'rgba(245,158,11,0.5)' : 'rgba(239,68,68,0.6)';
  const progress = 1 - (frame / 90);
  const potOpacity = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill>
      {/* Pot — 25% from top */}
      <div style={{
        position: 'absolute', top: VH * 0.22, width: '100%', textAlign: 'center', opacity: potOpacity,
      }}>
        <div style={{ fontSize: 18, color: '#6B7280', letterSpacing: 4, marginBottom: 10 }}>TOTAL POT</div>
        <div style={{
          fontSize: 80, fontWeight: 900,
          background: 'linear-gradient(135deg, #00ff88, #a855f7)',
          WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent',
          filter: 'drop-shadow(0 0 20px rgba(0,255,136,0.4))',
        }}>${pot.toFixed(2)}</div>
      </div>

      {/* Draw in label — 42% */}
      <div style={{
        position: 'absolute', top: VH * 0.42, width: '100%', textAlign: 'center',
        fontSize: 18, color: '#6B7280', letterSpacing: 4,
      }}>DRAW IN</div>

      {/* Countdown number — center 47% */}
      <div style={{
        position: 'absolute', top: VH * 0.46, width: '100%', textAlign: 'center',
        fontSize: 180, fontWeight: 900, color: numColor,
        textShadow: `0 0 50px ${glowColor}`,
        transform: `scale(${numScale})`, opacity: numOpacity,
      }}>{countdownNum}</div>

      {/* Progress bar — 62% */}
      <div style={{
        position: 'absolute', top: VH * 0.64, left: (VW - 700) / 2, width: 700, height: 8,
        borderRadius: 4, background: 'rgba(255,255,255,0.08)',
      }}>
        <div style={{
          width: `${progress * 100}%`, height: '100%', borderRadius: 4,
          background: numColor, boxShadow: `0 0 12px ${numColor}`,
        }} />
      </div>

      {/* Player avatars — 72% */}
      <div style={{
        position: 'absolute', top: VH * 0.72, width: '100%',
        display: 'flex', gap: 24, justifyContent: 'center',
      }}>
        {players.slice(0, 6).map((p, i) => {
          const pO = interpolate(frame, [i * 5, i * 5 + 15], [0, 1], { extrapolateRight: 'clamp' });
          const pS = interpolate(frame, [i * 5, i * 5 + 15], [30, 0], { extrapolateRight: 'clamp' });
          return (
            <div key={i} style={{
              display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8,
              opacity: pO, transform: `translateY(${pS}px)`,
            }}>
              <div style={{
                width: 64, height: 64, borderRadius: '50%', background: avatarColor(p.nickname),
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontSize: 18, fontWeight: 700, color: '#fff',
                boxShadow: `0 0 14px ${avatarColor(p.nickname)}44`,
              }}>{p.nickname.slice(0, 2).toUpperCase()}</div>
              <div style={{ fontSize: 14, color: '#9CA3AF', maxWidth: 80, textAlign: 'center', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{p.nickname}</div>
              <div style={{ fontSize: 14, color: '#00ff88', fontWeight: 700 }}>${p.total_bet.toFixed(2)}</div>
            </div>
          );
        })}
      </div>
    </AbsoluteFill>
  );
}

// ── Phase 2: Carousel ────────────────────────────────────────────────────────
// Carousel at vertical center (~45%), label above

function CarouselPhase({ strip }: { strip: Player[] }) {
  const frame = useCurrentFrame();
  const totalFrames = SPIN_END - SPIN_START;
  const progress = Math.min(frame / totalFrames, 1);
  const eased = easeCasino(progress);
  const currentX = TARGET_X * eased;
  const prevX = TARGET_X * easeCasino(Math.max(0, (frame - 1) / totalFrames));
  const blur = Math.min(Math.abs(currentX - prevX) / 6, 10);
  const revealed = frame > totalFrames - 10;

  return (
    <AbsoluteFill>
      {/* Label — 35% */}
      <div style={{
        position: 'absolute', top: VH * 0.35, width: '100%', textAlign: 'center',
        fontSize: 20, color: '#6B7280', letterSpacing: 4,
      }}>🎰 SELECTING WINNER…</div>

      {/* Carousel — center 45% */}
      <div style={{
        position: 'absolute', top: VH * 0.45 - 65, left: (VW - REEL_W) / 2,
        width: REEL_W, height: 130, overflow: 'hidden', borderRadius: 22,
        background: 'rgba(0,0,0,0.35)',
        border: revealed ? '1px solid rgba(245,158,11,0.4)' : '1px solid rgba(255,255,255,0.08)',
        boxShadow: revealed ? '0 0 40px rgba(245,158,11,0.15)' : 'none',
      }}>
        <div style={{ position: 'absolute', top: 0, bottom: 0, left: 0, width: 100, zIndex: 10, background: 'linear-gradient(to right, rgba(0,0,0,0.9), transparent)' }} />
        <div style={{ position: 'absolute', top: 0, bottom: 0, right: 0, width: 100, zIndex: 10, background: 'linear-gradient(to left, rgba(0,0,0,0.9), transparent)' }} />
        <div style={{ position: 'absolute', top: 0, bottom: 0, left: REEL_CENTER - 1, width: 2, background: '#FFC857', boxShadow: '0 0 12px #FFC857', zIndex: 20 }} />
        <div style={{ position: 'absolute', top: 0, left: REEL_CENTER - 8, zIndex: 20, width: 0, height: 0, borderLeft: '8px solid transparent', borderRight: '8px solid transparent', borderTop: '10px solid #FFC857' }} />
        <div style={{ position: 'absolute', bottom: 0, left: REEL_CENTER - 8, zIndex: 20, width: 0, height: 0, borderLeft: '8px solid transparent', borderRight: '8px solid transparent', borderBottom: '10px solid #FFC857' }} />
        <div style={{
          position: 'absolute', top: 18, left: 0, display: 'flex', gap: TILE_GAP,
          transform: `translateX(${currentX}px)`,
          filter: blur > 0.5 ? `blur(${blur.toFixed(1)}px)` : 'none',
        }}>
          {strip.map((p, i) => {
            const isW = i === WINNER_IDX && revealed;
            return (
              <div key={i} style={{
                width: TILE_W, height: 94, flexShrink: 0, borderRadius: 14,
                display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 4,
                background: isW ? 'rgba(245,158,11,0.2)' : 'rgba(255,255,255,0.04)',
                border: isW ? '2px solid rgba(245,158,11,0.8)' : '1px solid rgba(255,255,255,0.06)',
                boxShadow: isW ? '0 0 30px rgba(245,158,11,0.6)' : 'none',
              }}>
                <div style={{
                  width: 42, height: 42, borderRadius: '50%', background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 14, fontWeight: 700, color: '#fff',
                  boxShadow: isW ? `0 0 15px ${avatarColor(p.nickname)}` : 'none',
                }}>{p.nickname.slice(0, 2).toUpperCase()}</div>
                <span style={{
                  fontSize: 12, maxWidth: TILE_W - 8, overflow: 'hidden', textOverflow: 'ellipsis',
                  whiteSpace: 'nowrap', textAlign: 'center',
                  color: isW ? '#FFC857' : '#6B7280', fontWeight: isW ? 700 : 400,
                }}>{p.nickname}</span>
              </div>
            );
          })}
        </div>
      </div>
    </AbsoluteFill>
  );
}

// ── Phase 3: Winner Reveal ───────────────────────────────────────────────────
// Carousel at ~25%, winner info spread from 40% to 75%

function WinnerReveal({ winner, winnerNet, pot, strip }: {
  winner: string; winnerNet: number; pot: number; strip: Player[];
}) {
  const frame = useCurrentFrame();
  const flashOpacity = interpolate(frame, [0, 5, 15], [0.7, 0.3, 0], { extrapolateRight: 'clamp' });
  const avatarScale = interpolate(frame, [5, 15, 20], [0.3, 1.1, 1], { extrapolateRight: 'clamp' });
  const avatarOpacity = interpolate(frame, [5, 12], [0, 1], { extrapolateRight: 'clamp' });
  const nameOpacity = interpolate(frame, [12, 20], [0, 1], { extrapolateRight: 'clamp' });
  const amountScale = interpolate(frame, [18, 28, 32], [0.5, 1.15, 1], { extrapolateRight: 'clamp' });
  const amountOpacity = interpolate(frame, [18, 25], [0, 1], { extrapolateRight: 'clamp' });
  const glowPulse = interpolate(frame % 30, [0, 15, 30], [20, 50, 20]);

  return (
    <AbsoluteFill>
      {/* Flash */}
      <div style={{
        position: 'absolute', inset: 0, opacity: flashOpacity,
        background: 'radial-gradient(ellipse at 50% 50%, rgba(245,158,11,0.5) 0%, transparent 65%)',
      }} />

      {/* Frozen carousel — 22% */}
      <div style={{
        position: 'absolute', top: VH * 0.22, left: (VW - REEL_W) / 2,
        width: REEL_W, height: 130, overflow: 'hidden', borderRadius: 22,
        background: 'rgba(0,0,0,0.35)',
        border: '1px solid rgba(245,158,11,0.4)',
        boxShadow: '0 0 40px rgba(245,158,11,0.15)',
      }}>
        <div style={{ position: 'absolute', top: 0, bottom: 0, left: 0, width: 100, zIndex: 10, background: 'linear-gradient(to right, rgba(0,0,0,0.9), transparent)' }} />
        <div style={{ position: 'absolute', top: 0, bottom: 0, right: 0, width: 100, zIndex: 10, background: 'linear-gradient(to left, rgba(0,0,0,0.9), transparent)' }} />
        <div style={{ position: 'absolute', top: 0, bottom: 0, left: REEL_CENTER - 1, width: 2, background: '#FFC857', boxShadow: '0 0 12px #FFC857', zIndex: 20 }} />
        <div style={{
          position: 'absolute', top: 18, left: 0, display: 'flex', gap: TILE_GAP,
          transform: `translateX(${TARGET_X}px)`,
        }}>
          {strip.map((p, i) => {
            const isW = i === WINNER_IDX;
            return (
              <div key={i} style={{
                width: TILE_W, height: 94, flexShrink: 0, borderRadius: 14,
                display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 4,
                background: isW ? 'rgba(245,158,11,0.2)' : 'rgba(255,255,255,0.04)',
                border: isW ? '2px solid rgba(245,158,11,0.8)' : '1px solid rgba(255,255,255,0.06)',
                boxShadow: isW ? '0 0 30px rgba(245,158,11,0.6)' : 'none',
              }}>
                <div style={{
                  width: 42, height: 42, borderRadius: '50%', background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 14, fontWeight: 700, color: '#fff',
                }}>{p.nickname.slice(0, 2).toUpperCase()}</div>
                <span style={{
                  fontSize: 12, color: isW ? '#FFC857' : '#6B7280', fontWeight: isW ? 700 : 400,
                  maxWidth: TILE_W - 8, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                }}>{p.nickname}</span>
              </div>
            );
          })}
        </div>
      </div>

      {/* Winner label — 40% */}
      <div style={{
        position: 'absolute', top: VH * 0.40, width: '100%', textAlign: 'center',
        fontSize: 20, color: '#6B7280', letterSpacing: 4, opacity: nameOpacity,
      }}>🏆 WINNER</div>

      {/* Avatar — 45% */}
      <div style={{
        position: 'absolute', top: VH * 0.45, left: VW / 2 - 50,
        width: 100, height: 100, borderRadius: '50%', background: avatarColor(winner),
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: 32, fontWeight: 900, color: '#fff',
        transform: `scale(${avatarScale})`, opacity: avatarOpacity,
        boxShadow: `0 0 ${glowPulse}px rgba(245,158,11,0.6)`,
      }}>{winner.slice(0, 2).toUpperCase()}</div>

      {/* Name — 55% */}
      <div style={{
        position: 'absolute', top: VH * 0.55, width: '100%', textAlign: 'center',
        fontSize: 48, fontWeight: 900, color: '#FFC857', opacity: nameOpacity,
      }}>{winner}</div>

      {/* Amount — 62% */}
      <div style={{
        position: 'absolute', top: VH * 0.62, width: '100%', textAlign: 'center',
        fontSize: 64, fontWeight: 900,
        background: 'linear-gradient(135deg, #f59e0b, #fbbf24)',
        WebkitBackgroundClip: 'text', WebkitTextFillColor: 'transparent',
        filter: 'drop-shadow(0 0 14px rgba(245,158,11,0.7))',
        transform: `scale(${amountScale})`, opacity: amountOpacity,
      }}>+${winnerNet.toFixed(2)}</div>

      {/* Subtitle — 70% */}
      <div style={{
        position: 'absolute', top: VH * 0.70, width: '100%', textAlign: 'center',
        fontSize: 22, color: '#6B7280', opacity: amountOpacity,
      }}>wins the pot!</div>

      {/* Explosion particles centered on avatar */}
      {Array.from({ length: 24 }, (_, i) => {
        const angle = (i / 24) * Math.PI * 2;
        const dist = interpolate(frame, [0, 20], [0, 120 + (i % 3) * 50], { extrapolateRight: 'clamp' });
        const cx = VW / 2;
        const cy = VH * 0.50;
        const x = cx + Math.cos(angle) * dist;
        const y = cy + Math.sin(angle) * dist;
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

// ── Phase 4: Results Table ───────────────────────────────────────────────────
// Spread from 15% to 85% of screen height

function ResultsTable({ players, winner, pot }: {
  players: Player[]; winner: string; pot: number;
}) {
  const frame = useCurrentFrame();
  const cardOpacity = interpolate(frame, [0, 15], [0, 1], { extrapolateRight: 'clamp' });
  const cardSlide = interpolate(frame, [0, 15], [40, 0], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill>
      <div style={{
        position: 'absolute', top: VH * 0.12, left: (VW - 940) / 2,
        width: 940, borderRadius: 28, padding: '50px 45px',
        background: 'rgba(255,255,255,0.02)',
        border: '1px solid rgba(255,255,255,0.06)',
        opacity: cardOpacity, transform: `translateY(${cardSlide}px)`,
      }}>
        {/* Header */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 35 }}>
          <span style={{ fontSize: 24, color: '#FFC857' }}>🏆</span>
          <span style={{ fontSize: 22, fontWeight: 600, color: '#9CA3AF' }}>Game Results</span>
        </div>

        {/* Winner card */}
        <div style={{
          display: 'flex', alignItems: 'center', gap: 20, padding: 28,
          borderRadius: 20, marginBottom: 40,
          background: 'rgba(245,158,11,0.08)',
          border: '1px solid rgba(245,158,11,0.25)',
          boxShadow: '0 0 25px rgba(245,158,11,0.1)',
        }}>
          <div style={{
            width: 64, height: 64, borderRadius: '50%', background: avatarColor(winner),
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 22, fontWeight: 700, color: '#fff',
            boxShadow: `0 0 18px ${avatarColor(winner)}88`,
          }}>{winner.slice(0, 2).toUpperCase()}</div>
          <div>
            <div style={{ fontSize: 26, fontWeight: 700, color: '#FFC857' }}>{winner}</div>
            <div style={{ fontSize: 16, color: '#6B7280' }}>Winner</div>
          </div>
          <div style={{ marginLeft: 'auto', fontSize: 34, fontWeight: 900, color: '#FFC857' }}>
            +${pot.toFixed(2)}
          </div>
        </div>

        {/* Players */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {players.map((p, i) => {
            const pO = interpolate(frame, [10 + i * 4, 18 + i * 4], [0, 1], { extrapolateRight: 'clamp' });
            const isW = p.nickname === winner;
            return (
              <div key={i} style={{
                display: 'flex', alignItems: 'center', gap: 14,
                padding: '14px 20px', borderRadius: 14,
                background: isW ? 'rgba(245,158,11,0.1)' : 'rgba(255,255,255,0.03)',
                border: isW ? '1px solid rgba(245,158,11,0.3)' : '1px solid rgba(255,255,255,0.06)',
                opacity: pO,
              }}>
                <div style={{
                  width: 36, height: 36, borderRadius: '50%', background: avatarColor(p.nickname),
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: 12, fontWeight: 700, color: '#fff',
                }}>{p.nickname.slice(0, 2).toUpperCase()}</div>
                <span style={{
                  fontSize: 18, color: isW ? '#FFC857' : '#D1D5DB',
                  fontWeight: isW ? 700 : 400, flex: 1,
                }}>{p.nickname}</span>
                <span style={{ fontSize: 16, color: '#00ff88', fontWeight: 700, minWidth: 60, textAlign: 'right' }}>
                  {p.percent}%
                </span>
                <span style={{ fontSize: 16, color: '#9CA3AF', minWidth: 80, textAlign: 'right' }}>
                  ${p.total_bet.toFixed(2)}
                </span>
              </div>
            );
          })}
        </div>

        {/* Provably Fair */}
        <div style={{ marginTop: 35, display: 'flex', alignItems: 'center' }}>
          <div style={{
            fontSize: 14, padding: '6px 16px', borderRadius: 20,
            background: 'rgba(0,255,136,0.08)', border: '1px solid rgba(0,255,136,0.2)', color: '#00ff88',
          }}>✓ Provably Fair · Verifiable</div>
        </div>
      </div>
    </AbsoluteFill>
  );
}
