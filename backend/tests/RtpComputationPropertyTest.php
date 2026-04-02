<?php
/**
 * Property-based test for RTP Computation Correctness (Property 12).
 *
 * Uses an in-memory SQLite database to simulate game_rounds, game_bets, and users.
 * The RTP computation logic is inlined from games_analytics.php.
 *
 * Feature: platform-observability, Property 12: RTP Computation Correctness
 * Validates: Requirements 6.4, 6.5, 6.6
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class RtpComputationPropertyTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                nickname TEXT DEFAULT NULL,
                is_bot INTEGER NOT NULL DEFAULT 0
            )
        ");

        $this->db->exec("
            CREATE TABLE game_rounds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'waiting',
                winner_id INTEGER DEFAULT NULL,
                total_pot REAL NOT NULL DEFAULT 0.00,
                winner_net REAL DEFAULT NULL,
                commission REAL DEFAULT NULL,
                referral_bonus REAL DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                server_seed TEXT DEFAULT NULL,
                final_combined_hash TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (winner_id) REFERENCES users(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE game_bets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                round_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                client_seed TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (round_id) REFERENCES game_rounds(id),
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
    }

    private function createUser(string $email): int
    {
        $this->db->prepare("INSERT INTO users (email, is_bot) VALUES (?, 0)")
            ->execute([$email]);
        return (int) $this->db->lastInsertId();
    }

    // =========================================================================
    // Property 12: RTP Computation Correctness
    // Feature: platform-observability, Property 12: RTP Computation Correctness
    //
    // For any set of finished game_rounds with non-zero total_pot,
    // global_rtp = (SUM(winner_net) / SUM(total_pot)) * 100.
    // Per-room RTP same formula per room.
    //
    // Validates: Requirements 6.4, 6.5, 6.6
    // =========================================================================
    public function testProperty12_RtpComputationCorrectness(): void
    {
        // Feature: platform-observability, Property 12: RTP Computation Correctness
        $iterations = 30;
        $failures   = [];

        $rooms = [1, 10, 100];

        for ($i = 0; $i < $iterations; $i++) {
            $this->db->exec("DELETE FROM game_bets");
            $this->db->exec("DELETE FROM game_rounds");
            $this->db->exec("DELETE FROM users");

            // Create some users
            $userIds = [];
            for ($u = 0; $u < 3; $u++) {
                $userIds[] = $this->createUser("player{$u}_iter{$i}@test.com");
            }

            // Track expected values
            $expectedTotalPot    = 0.0;
            $expectedTotalPayout = 0.0;
            $expectedByRoom      = [];

            // Create random finished rounds across rooms
            $numRounds = mt_rand(3, 15);
            for ($r = 0; $r < $numRounds; $r++) {
                $room      = $rooms[mt_rand(0, 2)];
                $totalPot  = round(mt_rand(200, 10000) / 100, 2);
                // winner_net is typically pot minus commission (2-5%)
                $commRate  = mt_rand(2, 5) / 100.0;
                $commission = round($totalPot * $commRate, 2);
                $refBonus   = round($commission * 0.1, 2);
                $winnerNet  = round($totalPot - $commission, 2);
                $winnerId   = $userIds[mt_rand(0, count($userIds) - 1)];

                $this->db->prepare(
                    "INSERT INTO game_rounds (room, status, winner_id, total_pot, winner_net, commission, referral_bonus, finished_at)
                     VALUES (?, 'finished', ?, ?, ?, ?, ?, datetime('now', '-' || ? || ' hours'))"
                )->execute([$room, $winnerId, $totalPot, $winnerNet, $commission, $refBonus, $r]);

                $expectedTotalPot    += $totalPot;
                $expectedTotalPayout += $winnerNet;

                if (!isset($expectedByRoom[$room])) {
                    $expectedByRoom[$room] = ['pot' => 0.0, 'payout' => 0.0];
                }
                $expectedByRoom[$room]['pot']    += $totalPot;
                $expectedByRoom[$room]['payout'] += $winnerNet;
            }

            // Run the RTP computation queries (inlined from games_analytics.php)
            $agg = $this->db->query(
                "SELECT COALESCE(SUM(gr.total_pot), 0) AS total_pot_sum,
                        COALESCE(SUM(gr.winner_net), 0) AS total_payout_sum
                 FROM game_rounds gr
                 WHERE gr.status = 'finished'"
            )->fetch();

            $totalPotSum    = (float) $agg['total_pot_sum'];
            $totalPayoutSum = (float) $agg['total_payout_sum'];
            $globalRtp      = $totalPotSum > 0 ? round($totalPayoutSum / $totalPotSum * 100, 2) : 0.0;

            // Verify global aggregates
            if (abs($totalPotSum - $expectedTotalPot) > 0.01) {
                $failures[] = sprintf('iter=%d: total_pot_sum mismatch: expected=%.2f got=%.2f', $i, $expectedTotalPot, $totalPotSum);
            }
            if (abs($totalPayoutSum - $expectedTotalPayout) > 0.01) {
                $failures[] = sprintf('iter=%d: total_payout_sum mismatch: expected=%.2f got=%.2f', $i, $expectedTotalPayout, $totalPayoutSum);
            }

            // Verify global RTP
            $expectedGlobalRtp = $expectedTotalPot > 0 ? round($expectedTotalPayout / $expectedTotalPot * 100, 2) : 0.0;
            if (abs($globalRtp - $expectedGlobalRtp) > 0.01) {
                $failures[] = sprintf('iter=%d: global_rtp mismatch: expected=%.2f got=%.2f', $i, $expectedGlobalRtp, $globalRtp);
            }

            // Verify total_rounds
            $totalRounds = (int) $this->db->query("SELECT COUNT(*) FROM game_rounds WHERE status = 'finished'")->fetchColumn();
            if ($totalRounds !== $numRounds) {
                $failures[] = sprintf('iter=%d: total_rounds mismatch: expected=%d got=%d', $i, $numRounds, $totalRounds);
            }

            // Verify per-room RTP
            $rtpByRoom = $this->db->query(
                "SELECT gr.room,
                        COALESCE(SUM(gr.total_pot), 0) AS pot,
                        COALESCE(SUM(gr.winner_net), 0) AS payout
                 FROM game_rounds gr
                 WHERE gr.status = 'finished'
                 GROUP BY gr.room"
            )->fetchAll();

            foreach ($rtpByRoom as $row) {
                $room   = (int) $row['room'];
                $pot    = (float) $row['pot'];
                $payout = (float) $row['payout'];
                $roomRtp = $pot > 0 ? round($payout / $pot * 100, 2) : 0.0;

                if (isset($expectedByRoom[$room])) {
                    $expRoomRtp = $expectedByRoom[$room]['pot'] > 0
                        ? round($expectedByRoom[$room]['payout'] / $expectedByRoom[$room]['pot'] * 100, 2)
                        : 0.0;
                    if (abs($roomRtp - $expRoomRtp) > 0.01) {
                        $failures[] = sprintf('iter=%d: room=%d rtp mismatch: expected=%.2f got=%.2f', $i, $room, $expRoomRtp, $roomRtp);
                    }
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 12 (RTP Computation Correctness) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }
}
