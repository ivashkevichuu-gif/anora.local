import { useState } from 'react'
import LotteryPanel from '../components/lottery/LotteryPanel'
import { useSEO } from '../hooks/useSEO'

const ROOMS = [
  { id: 1, label: '$1' },
  { id: 10, label: '$10' },
  { id: 100, label: '$100' },
]

export default function Home() {
  useSEO('Play Provably Fair Lottery', 'Join real-time lottery rooms with crypto. $1, $10, $100 stakes. Every game is provably fair and verifiable.')
  const [activeRoom, setActiveRoom] = useState(1)

  return (
    <div className="flex flex-col gap-6 max-w-2xl mx-auto">
      {/* Room tabs */}
      <div
        className="flex gap-2 p-1 rounded-2xl"
        role="tablist"
        aria-label="Lottery rooms"
        style={{
          background: 'rgba(255,255,255,0.04)',
          border: '1px solid rgba(255,255,255,0.08)',
        }}
      >
        {ROOMS.map((room) => {
          const isActive = activeRoom === room.id
          return (
            <button
              key={room.id}
              role="tab"
              aria-selected={isActive}
              aria-pressed={isActive}
              onClick={() => setActiveRoom(room.id)}
              className="flex-1 py-2 px-4 rounded-xl text-sm font-bold transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
              style={{
                background: isActive
                  ? 'linear-gradient(135deg, rgba(124,58,237,0.6), rgba(0,255,136,0.2))'
                  : 'transparent',
                border: isActive
                  ? '1px solid rgba(124,58,237,0.5)'
                  : '1px solid transparent',
                color: isActive ? '#fff' : 'var(--text-muted)',
                boxShadow: isActive ? '0 0 16px rgba(124,58,237,0.3)' : 'none',
                cursor: 'pointer',
                '--tw-ring-color': 'rgba(124,58,237,0.6)',
                '--tw-ring-offset-color': '#0d0d0d',
              }}
            >
              {room.label}
            </button>
          )
        })}
      </div>

      {/* Lottery panel — key causes full remount/reset on room change */}
      <LotteryPanel key={activeRoom} room={activeRoom} />
    </div>
  )
}
