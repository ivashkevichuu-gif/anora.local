import React from 'react';
import { Composition } from 'remotion';
import { FinishedGameVideo } from './FinishedGameVideo';

const defaultProps = {
  room: {
    name: 'Room $10',
    room: 10,
    total_bank: 150.00,
    winner: 'Player1',
    winner_net: 145.50,
    players: [
      { nickname: 'Player1', total_bet: 50, percent: 33, is_winner: true },
      { nickname: 'Player2', total_bet: 60, percent: 40, is_winner: false },
      { nickname: 'Player3', total_bet: 40, percent: 27, is_winner: false },
    ],
  },
};

export const RemotionRoot: React.FC = () => {
  return (
    <Composition
      id="FinishedGameVideo"
      component={FinishedGameVideo}
      durationInFrames={360}
      fps={30}
      width={1080}
      height={1920}
      defaultProps={defaultProps}
    />
  );
};
