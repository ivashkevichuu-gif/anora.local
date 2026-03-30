<?php
/**
 * Property-based tests for computePayoutAmounts().
 *
 * Uses a manual random-generation loop (100 iterations) so the tests
 * run with plain PHPUnit — no eris dependency required.
 *
 * Feature: referral-commission-system
 * Validates: Requirements 1.1, 1.2, 3.1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../backend/includes/lottery.php';

use PHPUnit\Framework\TestCase;

class PayoutAmountsPropertyTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constants already defined in lottery.php / bootstrap.php — guard here
    // so the file is safe to require in isolation.
    // -------------------------------------------------------------------------
    protected function setUp(): void
    {
        defined('LOTTERY_BET')              || define('LOTTERY_BET',              1.00);
        defined('LOTTERY_COUNTDOWN')        || define('LOTTERY_COUNTDOWN',        30);
        defined('LOTTERY_MIN_PLAYERS')      || define('LOTTERY_MIN_PLAYERS',      2);
        defined('LOTTERY_MAX_BETS_PER_SEC') || define('LOTTERY_MAX_BETS_PER_SEC', 5);
        defined('LOTTERY_HASH_FORMAT')      || define('LOTTERY_HASH_FORMAT',      '%s:%s:%d');
    }

    // -------------------------------------------------------------------------
    // Helper: random float in [$min, $max] with 2 decimal places
    // -------------------------------------------------------------------------
    private function randomFloat(float $min, float $max): float
    {
        // mt_rand gives uniform integers; scale to the desired range
        $cents = mt_rand((int)round($min * 100), (int)round($max * 100));
        return round($cents / 100, 2);
    }

    // =========================================================================
    // Property 1: Financial Invariant
    // Feature: referral-commission-system, Property 1: Financial Invariant
    //
    // For any pot >= 0.50:
    //   winner_net + commission + referral_bonus === pot   (within float delta)
    //   commission     >= 0
    //   referral_bonus >= 0
    //   winner_net     >= 0
    // =========================================================================
    public function testProperty1_FinancialInvariant(): void
    {
        // Feature: referral-commission-system, Property 1: Financial Invariant
        $iterations = 100;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate a random pot in [0.50, 10000.00]
            $pot    = $this->randomFloat(0.50, 10000.00);
            $result = computePayoutAmounts($pot);

            $commission     = $result['commission'];
            $referralBonus  = $result['referral_bonus'];
            $winnerNet      = $result['winner_net'];

            $sum = $commission + $referralBonus + $winnerNet;

            // Collect failures rather than stopping at first one
            if (abs($sum - $pot) > 0.001) {
                $failures[] = sprintf(
                    'pot=%.2f  sum=%.4f  (commission=%.2f referral_bonus=%.2f winner_net=%.2f)',
                    $pot, $sum, $commission, $referralBonus, $winnerNet
                );
            }
            if ($commission < 0) {
                $failures[] = sprintf('pot=%.2f  commission < 0: %.4f', $pot, $commission);
            }
            if ($referralBonus < 0) {
                $failures[] = sprintf('pot=%.2f  referral_bonus < 0: %.4f', $pot, $referralBonus);
            }
            if ($winnerNet < 0) {
                $failures[] = sprintf('pot=%.2f  winner_net < 0: %.4f', $pot, $winnerNet);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 1 (Financial Invariant) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Property 2: Micro-Pot Exception
    // Feature: referral-commission-system, Property 2: Micro-Pot Exception
    //
    // For any pot in [0.01, 0.49]:
    //   commission     === 0.00
    //   referral_bonus === 0.00
    //   winner_net     === pot
    // =========================================================================
    public function testProperty2_MicroPotException(): void
    {
        // Feature: referral-commission-system, Property 2: Micro-Pot Exception
        $iterations = 100;
        $failures   = [];

        for ($i = 0; $i < $iterations; $i++) {
            // Generate a random pot in [0.01, 0.49]
            $pot    = $this->randomFloat(0.01, 0.49);
            $result = computePayoutAmounts($pot);

            $commission    = $result['commission'];
            $referralBonus = $result['referral_bonus'];
            $winnerNet     = $result['winner_net'];

            if ($commission !== 0.00) {
                $failures[] = sprintf('pot=%.2f  commission should be 0.00, got %.4f', $pot, $commission);
            }
            if ($referralBonus !== 0.00) {
                $failures[] = sprintf('pot=%.2f  referral_bonus should be 0.00, got %.4f', $pot, $referralBonus);
            }
            if (abs($winnerNet - $pot) > 0.001) {
                $failures[] = sprintf('pot=%.2f  winner_net should equal pot, got %.4f', $pot, $winnerNet);
            }
        }

        $this->assertEmpty(
            $failures,
            "Property 2 (Micro-Pot Exception) failed on " . count($failures) . " case(s):\n"
            . implode("\n", array_slice($failures, 0, 10))
        );
    }

    // =========================================================================
    // Boundary: pot exactly at the 0.50 threshold
    // =========================================================================
    public function testBoundary_PotAtThreshold(): void
    {
        $result = computePayoutAmounts(0.50);

        $this->assertSame(0.01, $result['commission'],     'commission at pot=0.50');
        $this->assertSame(0.01, $result['referral_bonus'], 'referral_bonus at pot=0.50');
        $this->assertEqualsWithDelta(0.48, $result['winner_net'], 0.001, 'winner_net at pot=0.50');
    }

    // =========================================================================
    // Boundary: pot just below threshold (0.49)
    // =========================================================================
    public function testBoundary_PotJustBelowThreshold(): void
    {
        $result = computePayoutAmounts(0.49);

        $this->assertSame(0.00, $result['commission'],     'commission at pot=0.49');
        $this->assertSame(0.00, $result['referral_bonus'], 'referral_bonus at pot=0.49');
        $this->assertSame(0.49, $result['winner_net'],     'winner_net at pot=0.49');
    }

    // =========================================================================
    // Known value: pot=100.00
    // =========================================================================
    public function testKnownValue_Pot100(): void
    {
        $result = computePayoutAmounts(100.00);

        $this->assertSame(2.00,  $result['commission'],     'commission at pot=100.00');
        $this->assertSame(1.00,  $result['referral_bonus'], 'referral_bonus at pot=100.00');
        $this->assertSame(97.00, $result['winner_net'],     'winner_net at pot=100.00');
    }
}
