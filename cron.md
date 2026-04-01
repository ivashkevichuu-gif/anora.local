# ANORA — Cron Jobs

Server path: `/home/ivash536/domains/anora.bet/public_html/backend/`

## Game Worker (state machine driver)

Runs continuously in background. Processes round transitions for all rooms every 1 second.

```
for i in 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 38 39 40 41 42 43 44 45 46 47 48 49 50 51 52 53 54 55 56 57 58 59; do sleep $i; php /home/ivash536/domains/anora.bet/public_html/backend/game_worker_cron.php >> /home/ivash536/game_worker.log 2>&1
```

## Bot Runner

Every minute, runs 4 iterations with staggered delays (0s, 2s, 4s, 12s).

```
for i in 0 2 4 12; do sleep $i; php /home/ivash536/domains/anora.bet/public_html/backend/bot_runner.php; done >> /home/ivash536/bot.log 2>&1
```

## Cleanup

Daily at 3am. Expires stale crypto invoices (>24h) and removes old registration attempts (>7d).

```
php /home/ivash536/domains/anora.bet/public_html/backend/cron/cleanup.php >> /dev/null 2>&1
```
