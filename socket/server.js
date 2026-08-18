const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const bodyParser = require('body-parser');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    path: '/apps/socket.io/',
    cors: {
        origin: '*',
        methods: ['GET', 'POST']
    }
});

const SHARED_SECRET = process.env.SOCKET_SHARED_SECRET || 'asdqwe';

// Middleware for /update endpoint
app.use(bodyParser.json());

// Handle POST /update from PHP notifier
app.post('/update', (req, res) => {
    const secret = req.headers['x-socket-secret'];
    if (secret !== SHARED_SECRET) {
        return res.status(403).json({ success: false, error: 'Invalid secret' });
    }

    const { event, data, ...metadata } = req.body;
    if (event) {
        io.emit(event, { ...data, ...metadata });
        return res.json({ success: true });
    }
    res.status(400).json({ success: false, error: 'Missing event' });
});

// Socket.IO connection
io.on('connection', (socket) => {
    console.log('Client connected:', socket.id);

    // Optional authentication handshake
    socket.on('authenticate', (payload) => {
        console.log('Authenticated:', payload);
        socket.emit('authenticated', { status: 'ok' });
    });

    socket.on('disconnect', () => {
        console.log('Client disconnected:', socket.id);
    });
});

// Health check endpoint
app.get('/health', (req, res) => res.send('OK'));

server.listen(3000, '0.0.0.0', () => {
    console.log('Socket.IO server running on port 3000');
});