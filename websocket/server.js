'use strict';

/**
 * ANORA WebSocket Server — real-time game events via Redis Pub/Sub.
 *
 * JWT authentication at handshake (query param `token`).
 * Subscribes to Redis Pub/Sub channels: game:finished, bet:placed, admin:events.
 * WebSocket channels: game:{room} (1, 10, 100), admin:live.
 * Connection limits: 1000 per game:{room}, 50 per admin:live.
 * Blacklist check via Redis on connection.
 *
 * Connection URL format:
 *   ws://host/ws/game/10?token=<jwt>
 *   ws://host/ws/admin/live?token=<jwt>
 *
 * Feature: production-architecture-overhaul
 * Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7
 */

const { WebSocketServer } = require('ws');
const http = require('http');
const url = require('url');
const jwt = require('jsonwebtoken');
const Redis = require('ioredis');

// ── Configuration ───────────────────────────────────────────────────────────

const WS_PORT = parseInt(process.env.WS_PORT || '8080', 10);
const JWT_SECRET = process.env.JWT_SECRET || 'default-dev-secret-change-me';
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = parseInt(process.env.REDIS_PORT || '6379', 10);
const REDIS_PASSWORD = process.env.REDIS_PASSWORD || undefined;

const VALID_ROOMS = [1, 10, 100];

/** Connection limits per channel */
const CONNECTION_LIMITS = {
  'admin:live': 50,
};
// game:{room} channels default to 1000
VALID_ROOMS.forEach(r => { CONNECTION_LIMITS[`game:${r}`] = 1000; });

// ── Channel subscription tracking ──────────────────────────────────────────

/** Map<channelName, Set<ws>> — tracks which clients are subscribed to each channel */
const channelSubscribers = new Map();

/** Map<ws, Set<channelName>> — tracks which channels each client is subscribed to */
const clientChannels = new Map();

/**
 * Get subscriber count for a channel.
 * @param {string} channel
 * @returns {number}
 */
function getChannelCount(channel) {
  const subs = channelSubscribers.get(channel);
  return subs ? subs.size : 0;
}

/**
 * Subscribe a client to a channel. Returns false if limit reached.
 * @param {import('ws').WebSocket} ws
 * @param {string} channel
 * @returns {boolean}
 */
function subscribeClient(ws, channel) {
  const limit = CONNECTION_LIMITS[channel];
  if (limit !== undefined && getChannelCount(channel) >= limit) {
    return false;
  }

  if (!channelSubscribers.has(channel)) {
    channelSubscribers.set(channel, new Set());
  }
  channelSubscribers.get(channel).add(ws);

  if (!clientChannels.has(ws)) {
    clientChannels.set(ws, new Set());
  }
  clientChannels.get(ws).add(channel);

  return true;
}

/**
 * Remove a client from all subscription sets (cleanup on disconnect).
 * @param {import('ws').WebSocket} ws
 */
function unsubscribeClient(ws) {
  const channels = clientChannels.get(ws);
  if (channels) {
    for (const channel of channels) {
      const subs = channelSubscribers.get(channel);
      if (subs) {
        subs.delete(ws);
        if (subs.size === 0) {
          channelSubscribers.delete(channel);
        }
      }
    }
    clientChannels.delete(ws);
  }
}

/**
 * Broadcast a JSON message to all subscribers of a channel.
 * @param {string} channel
 * @param {object} message
 */
function broadcastToChannel(channel, message) {
  const subs = channelSubscribers.get(channel);
  if (!subs) return;

  const payload = JSON.stringify(message);
  for (const client of subs) {
    if (client.readyState === 1 /* WebSocket.OPEN */) {
      client.send(payload);
    }
  }
}

// ── JWT verification ────────────────────────────────────────────────────────

/**
 * Verify JWT token. Returns decoded payload or null.
 * @param {string} token
 * @returns {object|null}
 */
function verifyToken(token) {
  try {
    return jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
  } catch (e) {
    return null;
  }
}

// ── Redis connections ───────────────────────────────────────────────────────

const redisOpts = {
  host: REDIS_HOST,
  port: REDIS_PORT,
  password: REDIS_PASSWORD,
  lazyConnect: true,
  retryStrategy(times) {
    return Math.min(times * 500, 5000);
  },
};

/** Subscriber connection (dedicated for Pub/Sub) */
const redisSub = new Redis(redisOpts);

/** Regular connection for blacklist checks */
const redisClient = new Redis(redisOpts);

/**
 * Check if a user is blacklisted via Redis.
 * @param {number} userId
 * @returns {Promise<boolean>}
 */
async function isBlacklisted(userId) {
  try {
    const result = await redisClient.exists(`blacklist:user:${userId}`);
    return result === 1;
  } catch (e) {
    console.error('[WS] Redis blacklist check failed:', e.message);
    return false; // graceful degradation
  }
}

// ── Route parsing ───────────────────────────────────────────────────────────

/**
 * Parse the request URL to determine channel and validate.
 * Returns { channel, room, type } or null if invalid.
 *
 * Valid paths:
 *   /ws/game/1   → channel "game:1",   type "game",  room 1
 *   /ws/game/10  → channel "game:10",  type "game",  room 10
 *   /ws/game/100 → channel "game:100", type "game",  room 100
 *   /ws/admin/live → channel "admin:live", type "admin"
 *
 * @param {string} pathname
 * @returns {object|null}
 */
function parseRoute(pathname) {
  // /ws/game/{room}
  const gameMatch = pathname.match(/^\/ws\/game\/(\d+)$/);
  if (gameMatch) {
    const room = parseInt(gameMatch[1], 10);
    if (VALID_ROOMS.includes(room)) {
      return { channel: `game:${room}`, type: 'game', room };
    }
    return null;
  }

  // /ws/admin/live
  if (pathname === '/ws/admin/live') {
    return { channel: 'admin:live', type: 'admin', room: null };
  }

  return null;
}

// ── HTTP + WebSocket server ─────────────────────────────────────────────────

const server = http.createServer((req, res) => {
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
});

const wss = new WebSocketServer({ noServer: true });

server.on('upgrade', async (request, socket, head) => {
  try {
    const parsed = url.parse(request.url, true);
    const route = parseRoute(parsed.pathname);

    if (!route) {
      socket.write('HTTP/1.1 404 Not Found\r\n\r\n');
      socket.destroy();
      return;
    }

    // JWT authentication from query param
    const token = parsed.query.token;
    if (!token) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    const payload = verifyToken(token);
    if (!payload) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    // Admin channel requires admin role
    if (route.type === 'admin' && payload.role !== 'admin') {
      socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
      socket.destroy();
      return;
    }

    // Blacklist check
    const blacklisted = await isBlacklisted(payload.sub);
    if (blacklisted) {
      socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
      socket.destroy();
      return;
    }

    // Connection limit check
    const limit = CONNECTION_LIMITS[route.channel];
    if (limit !== undefined && getChannelCount(route.channel) >= limit) {
      socket.write('HTTP/1.1 503 Service Unavailable\r\n\r\n');
      socket.destroy();
      return;
    }

    // Accept the WebSocket upgrade
    wss.handleUpgrade(request, socket, head, (ws) => {
      ws._userId = payload.sub;
      ws._role = payload.role;
      ws._channel = route.channel;
      ws._route = route;

      wss.emit('connection', ws, request);
    });
  } catch (err) {
    console.error('[WS] Upgrade error:', err.message);
    socket.write('HTTP/1.1 500 Internal Server Error\r\n\r\n');
    socket.destroy();
  }
});

wss.on('connection', (ws, request) => {
  const channel = ws._channel;

  // Subscribe client to channel
  const subscribed = subscribeClient(ws, channel);
  if (!subscribed) {
    ws.close(4002, JSON.stringify({ error: 'Connection limit reached' }));
    return;
  }

  console.log(`[WS] Client connected: user=${ws._userId} channel=${channel} (count=${getChannelCount(channel)})`);

  // Handle disconnect — cleanup subscriptions
  ws.on('close', () => {
    unsubscribeClient(ws);
    console.log(`[WS] Client disconnected: user=${ws._userId} channel=${channel} (count=${getChannelCount(channel)})`);
  });

  ws.on('error', (err) => {
    console.error(`[WS] Client error: user=${ws._userId}`, err.message);
    unsubscribeClient(ws);
  });
});

// ── Redis Pub/Sub event routing ─────────────────────────────────────────────

/**
 * Map Redis Pub/Sub channel → WebSocket channel(s).
 *
 * game:finished → game:{room} (extracted from event data)
 * bet:placed    → game:{room} (extracted from event data)
 * admin:events  → admin:live
 */
function handleRedisMessage(redisChannel, message) {
  try {
    const data = JSON.parse(message);

    switch (redisChannel) {
      case 'game:finished': {
        const room = data.room;
        if (room && VALID_ROOMS.includes(room)) {
          broadcastToChannel(`game:${room}`, { event: 'round:finished', data });
        }
        // Also broadcast to admin:live
        broadcastToChannel('admin:live', { event: 'round:finished', data });
        break;
      }

      case 'bet:placed': {
        const room = data.room;
        if (room && VALID_ROOMS.includes(room)) {
          broadcastToChannel(`game:${room}`, { event: 'bet:placed', data });
        }
        // Also broadcast to admin:live
        broadcastToChannel('admin:live', { event: 'bet:placed', data });
        break;
      }

      case 'admin:events': {
        broadcastToChannel('admin:live', { event: 'admin:event', data });
        break;
      }

      default:
        break;
    }
  } catch (err) {
    console.error(`[WS] Failed to parse Redis message on ${redisChannel}:`, err.message);
  }
}

// ── Startup ─────────────────────────────────────────────────────────────────

async function start() {
  try {
    await redisSub.connect();
    await redisClient.connect();
    console.log('[WS] Connected to Redis');
  } catch (err) {
    console.error('[WS] Redis connection failed:', err.message);
    console.log('[WS] Starting without Redis — events will not be received');
  }

  // Subscribe to Redis Pub/Sub channels
  try {
    await redisSub.subscribe('game:finished', 'bet:placed', 'admin:events');
    console.log('[WS] Subscribed to Redis channels: game:finished, bet:placed, admin:events');
  } catch (err) {
    console.error('[WS] Redis subscribe failed:', err.message);
  }

  redisSub.on('message', handleRedisMessage);

  server.listen(WS_PORT, () => {
    console.log(`[WS] WebSocket server listening on port ${WS_PORT}`);
  });
}

start();

// ── Graceful shutdown ───────────────────────────────────────────────────────

function shutdown() {
  console.log('[WS] Shutting down...');

  // Close all WebSocket connections
  wss.clients.forEach((ws) => {
    ws.close(1001, 'Server shutting down');
  });

  server.close(() => {
    redisSub.disconnect();
    redisClient.disconnect();
    console.log('[WS] Server stopped');
    process.exit(0);
  });

  // Force exit after 5 seconds
  setTimeout(() => process.exit(0), 5000);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);

// ── Exports for testing ─────────────────────────────────────────────────────

module.exports = {
  verifyToken,
  parseRoute,
  subscribeClient,
  unsubscribeClient,
  getChannelCount,
  broadcastToChannel,
  channelSubscribers,
  clientChannels,
  CONNECTION_LIMITS,
  VALID_ROOMS,
};
