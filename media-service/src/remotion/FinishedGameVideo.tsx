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

export const FinishedGameVideo: React.FC<{ room: RoomData }> = ({ room }) => {
  const frame = useCurrentFrame();

  const fadeIn = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 15], [-40, 0], { extrapolateRight: 'clamp' });
  const bankScale = interpolate(frame, [20, 40], [0.5, 1], { extrapolateRight: 'clamp' });
  const bankOpacity = interpolate(frame, [20, 35], [0, 1], { extrapolateRight: 'clamp' });
  const winnerOpacity = interpolate(frame, [50, 70], [0, 1], { extrapolateRight: 'clamp' });
  const winnerSlide = interpolate(frame, [50, 70], [30, 0], { extrapolateRight: 'clamp' });
  const glowPulse = interpolate(frame % 60, [0, 30, 60], [15, 25, 15]);
  const footerOpacity = interpolate(frame, [80, 100], [0, 0.7], { extrapolateRight: 'clamp' });

  // Particle positions (deterministic based on index)
  const particles = Array.from({ length: 20 }, (_, i) => ({
    x: (i * 137.5) % 100,
    y: ((i * 73.7 + frame * (0.3 + i * 0.05)) % 120) - 10,
    size: 2 + (i % 4),
    opacity: interpolate(frame, [0, 30], [0, 0.3 + (i % 3) * 0.1], { extrapolateRight: 'clamp' }),
    color: i % 3 === 0 ? '#00E5FF' : i % 3 === 1 ? '#7A5CFF' : '#FFC857',
  }));

  return (
    <AbsoluteFill
      style={{
        background: 'linear-gradient(180deg, #0B0F1A 0%, #111827 50%, #0B0F1A 100%)',
        fontFamily: "'Inter', 'Segoe UI', sans-serif",
        overflow: 'hidden',
      }}
    >
      {/* Particles */}
      {particles.map((p, i) => (
        <div
          key={i}
          style={{
            position: 'absolute',
            left: `${p.x}%`,
            top: `${p.y}%`,
            width: p.size,
            height: p.size,
            borderRadius: '50%',
            background: p.color,
            opacity: p.opacity,
            boxShadow: `0 0 ${p.size * 2}px ${p.color}`,
          }}
        />
      ))}

      {/* Main content */}
      <div
        style={{
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          height: '100%',
          padding: '60px 40px',
          opacity: fadeIn,
        }}
      >
        {/* ANORA logo area */}
        <div
          style={{
            fontSize: 36,
            fontWeight: 800,
            letterSpacing: 8,
            color: '#00E5FF',
            textShadow: `0 0 ${glowPulse}px #00E5FF`,
            marginBottom: 40,
            transform: `translateY(${titleSlide}px)`,
          }}
        >
          ANORA.BET
        </div>

        {/* Game Finished title */}
        <div
          style={{
            fontSize: 56,
            fontWeight: 900,
            color: '#FFFFFF',
            textShadow: '0 0 20px rgba(0, 229, 255, 0.5)',
            marginBottom: 20,
            transform: `translateY(${titleSlide}px)`,
          }}
        >
          GAME FINISHED
        </div>

        {/* Room label */}
        <div
          style={{
            fontSize: 32,
            color: '#9CA3AF',
            marginBottom: 30,
            opacity: fadeIn,
          }}
        >
          Room ${room.room}
        </div>

        {/* Bank amount */}
        <div
          style={{
            fontSize: 90,
            fontWeight: 900,
            color: '#FFC857',
            textShadow: `0 0 ${glowPulse * 1.5}px rgba(255, 200, 87, 0.6)`,
            transform: `scale(${bankScale})`,
            opacity: bankOpacity,
            marginBottom: 40,
          }}
        >
          ${room.total_bank.toFixed(2)}
        </div>

        {/* Winner section */}
        <div
          style={{
            opacity: winnerOpacity,
            transform: `translateY(${winnerSlide}px)`,
            textAlign: 'center',
          }}
        >
          <div
            style={{
              fontSize: 28,
              color: '#9CA3AF',
              letterSpacing: 4,
              marginBottom: 10,
            }}
          >
            WINNER
          </div>
          <div
            style={{
              fontSize: 52,
              fontWeight: 800,
              color: '#7A5CFF',
              textShadow: `0 0 ${glowPulse}px rgba(122, 92, 255, 0.6)`,
            }}
          >
            {room.winner}
          </div>
          <div
            style={{
              fontSize: 36,
              color: '#00E5FF',
              marginTop: 10,
            }}
          >
            Won ${room.winner_net.toFixed(2)}
          </div>
        </div>

        {/* Players list */}
        <Sequence from={90} durationInFrames={270}>
          <div
            style={{
              position: 'absolute',
              bottom: 200,
              left: 0,
              right: 0,
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              gap: 8,
            }}
          >
            {room.players.slice(0, 5).map((p, i) => {
              const pOpacity = interpolate(
                useCurrentFrame(),
                [i * 5, i * 5 + 15],
                [0, 1],
                { extrapolateRight: 'clamp' }
              );
              return (
                <div
                  key={i}
                  style={{
                    fontSize: 22,
                    color: p.is_winner ? '#FFC857' : '#9CA3AF',
                    opacity: pOpacity,
                    fontWeight: p.is_winner ? 700 : 400,
                  }}
                >
                  {p.is_winner ? '🏆' : '•'} {p.nickname} — ${p.total_bet.toFixed(2)} ({p.percent}%)
                </div>
              );
            })}
          </div>
        </Sequence>

        {/* Footer */}
        <div
          style={{
            position: 'absolute',
            bottom: 80,
            fontSize: 24,
            color: '#6B7280',
            opacity: footerOpacity,
            letterSpacing: 2,
          }}
        >
          anora.bet • Play & Win
        </div>
      </div>
    </AbsoluteFill>
  );
};
