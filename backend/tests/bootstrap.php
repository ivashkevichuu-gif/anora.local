<?php
/**
 * PHPUnit bootstrap — defines constants required by lottery.php
 * so tests can require it without a live DB or web server.
 */

defined('LOTTERY_BET')              || define('LOTTERY_BET',              1.00);
defined('LOTTERY_COUNTDOWN')        || define('LOTTERY_COUNTDOWN',        30);
defined('LOTTERY_MIN_PLAYERS')      || define('LOTTERY_MIN_PLAYERS',      2);
defined('LOTTERY_MAX_BETS_PER_SEC') || define('LOTTERY_MAX_BETS_PER_SEC', 5);
defined('LOTTERY_HASH_FORMAT')      || define('LOTTERY_HASH_FORMAT',      '%s:%s:%d');
