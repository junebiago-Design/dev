const os = require("os");
const express = require("express");
const { createServer } = require("http");
const { Server } = require("socket.io");

const app = express();
const httpServer = createServer(app);

// ── Secret for authenticating PHP → Node requests ──
// Must match SOCKET_SHARED_SECRET in socket_notifier.php
// 🔐 CHANGE THIS to a strong random string in production
const SHARED_SECRET = process.env.SOCKET_SECRET || "asdqwe";
if (SHARED_SECRET === "asdqwe") {
  console.warn("⚠️  Using the default SOCKET_SECRET. Set the SOCKET_SECRET env var before going beyond local testing.");
}

// ── Middleware to parse JSON bodies ──
app.use(express.json());

// ── Logging middleware ──
app.use((req, res, next) => {
  const timestamp = new Date().toISOString();
  console.log(`[${timestamp}] ${req.method} ${req.url} from ${req.ip || req.connection.remoteAddress}`);
  next();
});

// ── Static files (optional, for serving socket client) ──
app.get('/socket.io/socket.io.js', (req, res) => {
  res.sendFile(require.resolve('socket.io-client/dist/socket.io.js'));
});

// ── Socket.IO with CORS (Hostinger-friendly) ──
const io = new Server(httpServer, {
  cors: {
    origin: (origin, callback) => {
      // Allow any origin for Hostinger
      callback(null, true);
    },
    methods: ["GET", "POST"],
    credentials: true,
    allowedHeaders: ["Content-Type", "Authorization", "X-Socket-Secret", "X-Request-Id"]
  },
  // Hostinger optimization: use polling first, then upgrade to websocket
  transports: ['polling', 'websocket'],
  allowUpgrades: true,
  pingTimeout: 60000,
  pingInterval: 25000,
  path: '/socket.io/',
  // Increase max payload size for larger broadcasts
  maxHttpBufferSize: 1e6,
  // Allow old clients to connect
  allowEIO3: true,
  // Allow requests from the same domain (for proxy)
  allowRequest: (req, callback) => {
    // Allow all requests when behind a proxy
    callback(null, true);
  }
});

// ── Track connected clients for monitoring ──
let connectedClients = 0;
let totalConnections = 0;

// ── Handle HTTP POST from PHP (socket_notifier.php) ──
app.post("/update", (req, res) => {
  // Verify secret
  const secret = req.headers["x-socket-secret"];
  if (secret !== SHARED_SECRET) {
    console.warn(`❌ Unauthorized /update attempt from ${req.ip}`);
    return res.status(401).json({ 
      success: false, 
      error: "Unauthorized" 
    });
  }

  const { event, data, originatorId, originatorContactId, originatorUsername, originatorTimestamp } = req.body;
  
  if (!event) {
    return res.status(400).json({ 
      success: false, 
      error: "Missing event" 
    });
  }

  // Prepare payload with originator info for client-side filtering
  const payload = {
    ...data,
    originatorId: originatorId || null,
    originatorContactId: originatorContactId || null,
    originatorUsername: originatorUsername || null,
    originatorTimestamp: originatorTimestamp || Date.now(),
    serverTimestamp: new Date().toISOString()
  };

  // Broadcast to all connected clients
  io.emit(event, payload);
  
  const clientCount = io.engine.clientsCount;
  console.log(`✅ Broadcasted "${event}" to ${clientCount} clients`);

  res.json({ 
    success: true, 
    clients: clientCount,
    event: event,
    timestamp: new Date().toISOString()
  });
});

// ── Health check endpoint ──
app.get("/update", (req, res) => {
  res.json({ 
    success: true, 
    service: "socket-server",
    clients: io.engine.clientsCount,
    connections: Object.keys(io.sockets.sockets).length,
    uptime: process.uptime(),
    timestamp: new Date().toISOString(),
    nodeVersion: process.version,
    memory: process.memoryUsage()
  });
});

// ── Status endpoint for debugging ──
app.get("/status", (req, res) => {
  const clients = Object.keys(io.sockets.sockets);
  res.json({
    status: "running",
    clients: clients.length,
    totalConnections: totalConnections,
    uptime: process.uptime(),
    memory: process.memoryUsage(),
    nodeVersion: process.version,
    platform: process.platform,
    pid: process.pid
  });
});

// ── Socket.IO connection handling ──
io.engine.on("connection_error", (err) => {
  console.error("❌ Connection error:", {
    code: err.code,
    message: err.message,
    reqUrl: err.req?.url,
    context: err.context
  });
});

io.on("connection", (socket) => {
  connectedClients++;
  totalConnections++;
  const clientAddress = socket.handshake.address;
  
  console.log(`✅ Client connected! ID: ${socket.id} from ${clientAddress} (${connectedClients} active)`);
  
  // Send welcome message
  socket.emit('welcome', {
    message: 'Connected to TMS Socket Server',
    clientId: socket.id,
    serverTime: new Date().toISOString()
  });

  // Handle authentication
  socket.on("authenticate", (data) => {
    console.log(`🔐 Client ${socket.id} authenticated:`, {
      userId: data.userId || 'none',
      contactId: data.contactId || 'none'
    });
    socket.data.userId = data.userId || null;
    socket.data.contactId = data.contactId || null;
    socket.data.authenticated = true;
    
    // Acknowledge authentication
    socket.emit('authenticated', {
      success: true,
      userId: socket.data.userId,
      contactId: socket.data.contactId
    });
  });

  // Handle ping/pong for keepalive
  socket.on("ping", (callback) => {
    if (typeof callback === 'function') {
      callback({ 
        pong: true, 
        timestamp: Date.now(),
        serverTime: new Date().toISOString()
      });
    }
  });

  // Handle disconnection
  socket.on("disconnect", (reason) => {
    connectedClients--;
    console.log(`❌ Client disconnected: ${socket.id} (${reason}) (${connectedClients} active)`);
  });

  // Handle errors
  socket.on("error", (err) => {
    console.error(`⚠️ Socket error for ${socket.id}:`, err.message);
  });

  // Handle custom events from client
  socket.onAny((eventName, ...args) => {
    if (eventName.startsWith('client:')) {
      console.log(`📨 Client ${socket.id} sent: ${eventName}`);
    }
  });
});

// ── Helper: find this machine's actual IP(s) ──
function getLanIPs() {
  const interfaces = os.networkInterfaces();
  const ips = [];
  for (const name of Object.keys(interfaces)) {
    for (const iface of interfaces[name]) {
      if (iface.family === "IPv4" && !iface.internal) {
        ips.push(iface.address);
      }
    }
  }
  return ips;
}

// ── Graceful shutdown ──
function shutdown(signal) {
  console.log(`🛑 Received ${signal}, shutting down gracefully...`);
  
  // Close all sockets
  io.close(() => {
    console.log('✅ Socket.IO closed');
    httpServer.close(() => {
      console.log('✅ HTTP server closed');
      process.exit(0);
    });
  });

  // Force exit after timeout
  setTimeout(() => {
    console.log('⚠️ Force exit after timeout');
    process.exit(1);
  }, 10000);
}

process.on('SIGINT', () => shutdown('SIGINT'));
process.on('SIGTERM', () => shutdown('SIGTERM'));

// ── Handle uncaught exceptions ──
process.on('uncaughtException', (err) => {
  console.error('❌ Uncaught exception:', err);
  // Keep running, don't crash
});

process.on('unhandledRejection', (reason, promise) => {
  console.error('❌ Unhandled rejection:', reason);
  // Keep running, don't crash
});

// ── Start server ──
const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || "0.0.0.0";

httpServer.listen(PORT, HOST, () => {
  console.log(`\n${'='.repeat(60)}`);
  console.log(`✅ Socket.IO server started successfully!`);
  console.log(`${'='.repeat(60)}`);
  console.log(`📡 Port:    ${PORT}`);
  console.log(`🌐 Host:    ${HOST}`);
  console.log(`🔗 Local:   http://localhost:${PORT}`);
  console.log(`🔗 Domain:  https://tms.ghcoor.com (via proxy)`);
  const lanIPs = getLanIPs();
  if (lanIPs.length) {
    lanIPs.forEach((ip) => console.log(`🔗 Network: http://${ip}:${PORT}`));
  }
  console.log(`📦 Clients: ${io.engine.clientsCount}`);
  console.log(`🔐 Secret:  ${SHARED_SECRET === 'asdqwe' ? '⚠️ DEFAULT (CHANGE ME)' : '✅ Custom'}`);
  console.log(`${'='.repeat(60)}\n`);
});

// ── Periodic stats logging ──
setInterval(() => {
  const stats = {
    clients: io.engine.clientsCount,
    connections: Object.keys(io.sockets.sockets).length,
    memory: Math.round(process.memoryUsage().rss / 1024 / 1024) + 'MB',
    uptime: Math.round(process.uptime() / 60) + 'min'
  };
  console.log(`📊 Stats: ${stats.clients} clients, ${stats.connections} connections, ${stats.memory}, ${stats.uptime}`);
}, 600000); // Every 10 minutes