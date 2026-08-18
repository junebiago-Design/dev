// ══════════════════════════════════════════════
//  SOCKET HANDLER — js/modules/socket-handler.js
//  Connects to the Socket.IO server and routes incoming events to
//  the granular UI update functions exposed by other modules.
//  Loaded after socket.io.js and after the modules it references.
//
//  FIXED: Added originator filtering to prevent "echo" notifications
//  - Events triggered by the current user are ignored
//  - Uses NotificationManager for centralized notifications
//  - Silent UI updates for remote events
//  - HOSTINGER DEPLOYMENT READY
//  - LOCALHOST FIX: Force WebSocket transport to bypass Cloudflare Access
// ══════════════════════════════════════════════

(function() {
    'use strict';

    // ── Configuration ──────────────────────────────────────────────────
    // Hostinger: Use the domain tms.ghcoor.com without port (proxied)
    const SOCKET_PATH = '/apps/socket.io/';
    // Use the same origin as the page (no port needed, proxied through web server)
    const SOCKET_URL = window.__socketUrl || 'https://tms.ghcoor.com';

    // ── LOCALHOST FIX: Detect if running on localhost ────────────────
    const isLocalhost = window.location.hostname === 'localhost' || 
                        window.location.hostname === '127.0.0.1' ||
                        window.location.hostname.startsWith('192.168.') ||
                        window.location.hostname.startsWith('10.') ||
                        window.location.hostname.endsWith('.local');

    // ── Socket.IO Connection Options ──────────────────────────────────
    // Force WebSocket transport on localhost to bypass Cloudflare Access
    // which intercepts polling requests and redirects to a login page
    const SOCKET_OPTIONS = {
        path: SOCKET_PATH,
        transports: isLocalhost ? ['websocket'] : ['polling', 'websocket'],
        reconnection: true,
        reconnectionAttempts: Infinity,
        reconnectionDelay: 1000,
        reconnectionDelayMax: 5000,
        timeout: isLocalhost ? 10000 : 20000,
        autoConnect: true,
        forceNew: false
    };

    // ── Get current user ID for filtering ────────────────────────────
    function getCurrentUserId() {
        if (typeof currentUser !== 'undefined' && currentUser) {
            return currentUser.id || currentUser.username || null;
        }
        // Try to get from session
        if (window.__tmsUser) {
            return window.__tmsUser.id || window.__tmsUser.username || null;
        }
        return null;
    }

    function getCurrentUserContactId() {
        if (typeof currentUser !== 'undefined' && currentUser) {
            return currentUser.contactId || currentUser.employeeId || null;
        }
        if (window.__tmsUser) {
            return window.__tmsUser.contactId || window.__tmsUser.employeeId || null;
        }
        return null;
    }

    const CURRENT_USER_ID = getCurrentUserId();
    const CURRENT_CONTACT_ID = getCurrentUserContactId();

    // ── Check if Socket.IO is available ──────────────────────────────
    if (typeof io === 'undefined') {
        console.warn('socket-handler: socket.io client library not loaded — real-time updates disabled');
        // Try to load it dynamically
        const script = document.createElement('script');
        script.src = `${SOCKET_URL}${SOCKET_PATH}socket.io.js`;
        script.onload = function() {
            console.log('socket-handler: Socket.IO client loaded dynamically, reinitializing...');
            // Re-run initialization after load
            setTimeout(initSocket, 100);
        };
        script.onerror = function() {
            console.warn('socket-handler: Failed to load Socket.IO client dynamically');
        };
        document.head.appendChild(script);
        return;
    }

    // ── Initialize Socket ─────────────────────────────────────────────
    function initSocket() {
        if (typeof io === 'undefined') {
            console.warn('socket-handler: socket.io still not available');
            return;
        }

        console.log(`socket-handler: Connecting to ${SOCKET_URL} with path ${SOCKET_PATH}`);
        console.log(`socket-handler: Localhost mode: ${isLocalhost ? 'YES (WebSocket only)' : 'NO (polling + WebSocket)'}`);
        console.log(`socket-handler: Transport: ${SOCKET_OPTIONS.transports.join(', ')}`);

        const socket = io(SOCKET_URL, SOCKET_OPTIONS);

        // Expose socket globally for debugging
        window.__tmsSocket = socket;

        // ── Helper: Was this event triggered by the current user? ────────
        function wasTriggeredByCurrentUser(data) {
            if (!data) return false;
            
            // Check various possible originator fields
            if (data.originatorId) {
                return data.originatorId === CURRENT_USER_ID;
            }
            if (data.originatorContactId) {
                return data.originatorContactId === CURRENT_CONTACT_ID;
            }
            if (data.userId) {
                return data.userId === CURRENT_USER_ID;
            }
            if (data.contactId) {
                return data.contactId === CURRENT_CONTACT_ID;
            }
            if (data.actorId) {
                return data.actorId === CURRENT_USER_ID || data.actorId === CURRENT_CONTACT_ID;
            }
            
            // If we can't determine, assume it was NOT us (process it)
            return false;
        }

        // ── Helper: Safe call to global functions ────────────────────────
        function call(fnName, ...args) {
            if (typeof window[fnName] === 'function') {
                try {
                    window[fnName](...args);
                } catch (err) {
                    console.error(`socket-handler: error calling ${fnName}`, err);
                }
            } else {
                console.debug(`socket-handler: function ${fnName} not found`);
            }
        }

        // ── Helper: Update UI without notifications ──────────────────────
        function updateUI(fnName, ...args) {
            call(fnName, ...args);
        }

        // ── Helper: Show notification via manager ────────────────────────
        function showNotification(message, type = 'info') {
            if (typeof NotificationManager !== 'undefined' && NotificationManager.show) {
                NotificationManager.show(message, type);
            } else if (typeof showNotification === 'function') {
                showNotification(message, type);
            } else if (typeof toast === 'function') {
                toast(message, type);
            } else {
                console.log(`[${type}] ${message}`);
            }
        }

        // ── Helper: Capitalize first letter ──────────────────────────────
        function capitalize(s) {
            return s.charAt(0).toUpperCase() + s.slice(1);
        }

        // ──────────────────────────────────────────────────────────────────
        //  SOCKET LIFECYCLE EVENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('connect', () => {
            console.log('✅ socket-handler: connected', socket.id);
            
            // Authenticate with the server
            socket.emit('authenticate', {
                userId: CURRENT_USER_ID,
                contactId: CURRENT_CONTACT_ID,
                timestamp: new Date().toISOString()
            });
        });

        socket.on('welcome', (data) => {
            console.log('📨 Welcome from server:', data);
        });

        socket.on('authenticated', (data) => {
            console.log('🔐 Authenticated successfully:', data);
        });

        socket.on('connect_error', (err) => {
            console.warn('socket-handler: connect_error', err.message);
            
            // LOCALHOST FIX: If WebSocket fails on localhost, try polling as fallback
            if (isLocalhost && err.message.includes('WebSocket')) {
                console.log('socket-handler: WebSocket failed on localhost, trying polling fallback...');
                socket.io.opts.transports = ['polling', 'websocket'];
                setTimeout(() => socket.connect(), 1000);
                return;
            }
            
            // Retry with different transport if polling fails
            if (err.message.includes('polling')) {
                console.log('socket-handler: Retrying with websocket only...');
                socket.io.opts.transports = ['websocket'];
                socket.connect();
            }
        });

        socket.on('reconnect_attempt', (attempt) => {
            console.debug(`socket-handler: reconnect attempt ${attempt}`);
        });

        socket.on('reconnect_failed', () => {
            console.warn('socket-handler: reconnect failed — real-time updates disabled');
        });

        socket.on('disconnect', (reason) => {
            if (reason === 'io server disconnect') {
                console.warn('socket-handler: server disconnected, reconnecting...');
                socket.connect();
            } else {
                console.warn('socket-handler: disconnected', reason);
            }
        });

        socket.on('error', (err) => {
            console.error('socket-handler: socket error', err);
        });

        // ──────────────────────────────────────────────────────────────────
        //  DEALS / KANBAN EVENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('deal:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own deal:created event');
                return;
            }
            updateUI('appendDealCard', data, data.stage);
            updateUI('updateBadges');
            
            const taskTitle = data.title || 'a task';
            showNotification(`New task: ${taskTitle}`, 'info');
        });

        socket.on('deal:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                // console.debug('socket-handler: skipping own deal:updated event');
                return;
            }
            updateUI('updateDealCard', data.id, data);
            updateUI('updateBadges');
        });

        socket.on('deal:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own deal:deleted event');
                return;
            }
            updateUI('removeDealCard', data.id);
            updateUI('updateBadges');
            
            const taskTitle = data.title || 'a task';
            showNotification(`Task deleted: ${taskTitle}`, 'warning');
        });

        socket.on('deal:stage-changed', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own deal:stage-changed event');
                return;
            }
            updateUI('updateStageColumn', data.fromStage);
            updateUI('updateStageColumn', data.toStage);
            updateUI('updateBadges');
            
            const taskTitle = data.title || 'a task';
            const fromLabel = data.fromStageLabel || data.fromStage;
            const toLabel = data.toStageLabel || data.toStage;
            showNotification(`Task "${taskTitle}" moved: ${fromLabel} → ${toLabel}`, 'info');
        });

        // ──────────────────────────────────────────────────────────────────
        //  ACTIVITY FEED EVENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('activity:new', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own activity:new event');
                return;
            }
            updateUI('prependActivity', data);

            // ── Minimal Kanban realtime refresh ──────────────────────────
            // activity:new fires alongside a dedicated entity event
            // (deal:updated, note:created, contact:updated, etc — see
            // BROADCAST_ENTITY_TABLES in api.php) for every table that has
            // one of its own. Reloading here TOO for those categories is a
            // duplicate broadcast: the granular handler above already
            // patched the board precisely, and this would immediately wipe
            // + re-render the whole thing again with the same data.
            //
            // stages is the one table with NO dedicated broadcast event
            // (not in BROADCAST_ENTITY_TABLES), so stage/permission edits
            // only ever surface through this activity log entry — that's
            // the actual case this reload exists for.
            //
            // Category is inferred server-side from the activity message
            // text (see ACTIVITY_RULES in activity.js) — if that rule list
            // changes, this skip-list should be revisited.
            const ENTITY_COVERED_CATEGORIES = new Set([
                'task', 'task-move', 'comment', 'revision', 'done', 'file', 'role'
            ]);

            if (!ENTITY_COVERED_CATEGORIES.has(data.category)) {
                // Two guards, both already established elsewhere in this file:
                //   - wasTriggeredByCurrentUser() above already returned if
                //     this was our own echoed action — we have that data
                //     locally.
                //   - currentPage === 'kanban': only refetch if Kanban is
                //     the page actually on screen right now (same pattern
                //     as activity.js's dashboard auto-refresh checking
                //     page-dashboard.classList.contains('active')). If the
                //     user is elsewhere, do nothing — Kanban gets fresh
                //     data next time it's opened.
                if (typeof window.currentPage !== 'undefined' && window.currentPage === 'kanban') {
                    if (typeof window.RealtimeSync !== 'undefined' && window.RealtimeSync.reloadData) {
                        window.RealtimeSync.reloadData();
                    }
                }
            }
        });

        socket.on('task-activity:new', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                // console.debug('socket-handler: skipping own task-activity:new event');
                return;
            }
            updateUI('prependTaskActivity', data);
        });

        // ──────────────────────────────────────────────────────────────────
        //  NOTES / COMMENTS / REVISIONS
        // ──────────────────────────────────────────────────────────────────

        socket.on('note:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own note:created event');
                return;
            }
            updateUI('addNote', data);
            updateUI('updateBadges');
            
            const noteTitle = data.title || 'a note';
            const noteType = data.type === 'revision' ? 'Revision' : 'Comment';
            showNotification(`${noteType} added: ${noteTitle}`, data.type === 'revision' ? 'warning' : 'info');
        });

        socket.on('note:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                // console.debug('socket-handler: skipping own note:updated event');
                return;
            }
            updateUI('updateNote', data.id, data);
            updateUI('updateBadges');
        });

        socket.on('note:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own note:deleted event');
                return;
            }
            updateUI('removeNote', data.id);
            updateUI('updateBadges');
            
            const noteTitle = data.title || 'a note';
            showNotification(`Note deleted: ${noteTitle}`, 'warning');
        });

        // ──────────────────────────────────────────────────────────────────
        //  ANNOUNCEMENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('announcement:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own announcement:created event');
                return;
            }
            updateUI('addAnnouncement', data);
            updateUI('updateBadges');
            
            const title = data.title || 'an announcement';
            showNotification(`New announcement: ${title}`, 'success');
        });

        socket.on('announcement:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own announcement:updated event');
                return;
            }
            updateUI('updateAnnouncement', data.id, data);
            updateUI('updateBadges');
        });

        socket.on('announcement:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own announcement:deleted event');
                return;
            }
            updateUI('removeAnnouncement', data.id);
            updateUI('updateBadges');
        });

        // ──────────────────────────────────────────────────────────────────
        //  CONTACTS / EMPLOYEES
        // ──────────────────────────────────────────────────────────────────

        socket.on('contact:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own contact:created event');
                return;
            }
            updateUI('addContact', data);
            updateUI('updateBadges');
            
            const name = data.fname && data.lname ? `${data.fname} ${data.lname}` : 'an employee';
            showNotification(`New employee: ${name}`, 'success');
        });

        socket.on('contact:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own contact:updated event');
                return;
            }
            updateUI('updateContact', data.id, data);
            updateUI('updateBadges');
        });

        socket.on('contact:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own contact:deleted event');
                return;
            }
            updateUI('removeContact', data.id);
            updateUI('updateBadges');
            
            const name = data.fname && data.lname ? `${data.fname} ${data.lname}` : 'an employee';
            showNotification(`Employee deleted: ${name}`, 'warning');
        });

        // ──────────────────────────────────────────────────────────────────
        //  DEPARTMENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('department:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own department:created event');
                return;
            }
            updateUI(`add${capitalize('department')}Record`, data);
            updateUI('updateBadges');
            
            const name = data.name || 'a department';
            showNotification(`New department: ${name}`, 'success');
        });

        socket.on('department:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own department:updated event');
                return;
            }
            updateUI(`update${capitalize('department')}Record`, data.id, data);
            updateUI('updateBadges');
        });

        socket.on('department:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own department:deleted event');
                return;
            }
            updateUI(`delete${capitalize('department')}Record`, data.id);
            updateUI('updateBadges');
        });

        // ──────────────────────────────────────────────────────────────────
        //  COMPANIES
        // ──────────────────────────────────────────────────────────────────

        socket.on('company:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own company:created event');
                return;
            }
            updateUI(`add${capitalize('company')}Record`, data);
            updateUI('updateBadges');
            
            const name = data.name || 'a company';
            showNotification(`New company: ${name}`, 'success');
        });

        socket.on('company:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own company:updated event');
                return;
            }
            updateUI(`update${capitalize('company')}Record`, data.id, data);
            updateUI('updateBadges');
        });

        socket.on('company:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own company:deleted event');
                return;
            }
            updateUI(`delete${capitalize('company')}Record`, data.id);
            updateUI('updateBadges');
        });

        // ──────────────────────────────────────────────────────────────────
        //  ROLES
        // ──────────────────────────────────────────────────────────────────

        socket.on('role:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own role:created event');
                return;
            }
            updateUI(`add${capitalize('role')}Record`, data);
            updateUI('updateBadges');
            
            const name = data.name || 'a role';
            showNotification(`New role: ${name}`, 'success');
        });

        socket.on('role:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own role:updated event');
                return;
            }
            updateUI(`update${capitalize('role')}Record`, data.id, data);
            updateUI('updateBadges');
        });

        socket.on('role:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own role:deleted event');
                return;
            }
            updateUI(`delete${capitalize('role')}Record`, data.id);
            updateUI('updateBadges');
        });

        // ──────────────────────────────────────────────────────────────────
        //  USERS
        // ──────────────────────────────────────────────────────────────────

        socket.on('user:created', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own user:created event');
                return;
            }
            updateUI(`add${capitalize('user')}Record`, data);
            updateUI('updateBadges');
            
            const username = data.username || 'a user';
            showNotification(`New user: ${username}`, 'success');
        });

        socket.on('user:updated', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own user:updated event');
                return;
            }
            updateUI(`update${capitalize('user')}Record`, data.id, data);
            updateUI('updateBadges');
        });

        socket.on('user:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own user:deleted event');
                return;
            }
            updateUI(`delete${capitalize('user')}Record`, data.id);
            updateUI('updateBadges');
        });

        // ──────────────────────────────────────────────────────────────────
        //  LOGIN MONITORING / EMPLOYEE DIRECTORY
        // ──────────────────────────────────────────────────────────────────

        socket.on('login:success', (data) => {
            // Skip entirely if this is our own login — the user who just
            // logged in doesn't need a toast/UI update telling them they
            // logged in. Only OTHER connected clients should be notified.
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own login:success event (toast + UI)');
                return;
            }

            if (typeof NotificationManager !== 'undefined') {
                const name = data.employeeName || data.username || 'Someone';
                NotificationManager.show(`${name} logged in`, 'login');
            }

            updateUI('appendLoginLog', data, 'login');
            updateUI('updateEmployeeStatus', data.employeeId, 'online');
        });

        socket.on('login:failed', (data) => {
            // Always show failed login toasts (security event)
            if (typeof NotificationManager !== 'undefined') {
                const name = data.employeeName || data.username || 'Someone';
                const reason = data.failureReason ? ` (${data.failureReason})` : '';
                NotificationManager.show(`Failed login attempt: ${name}${reason}`, 'error');
            }
            
            // Skip UI updates if it's our own event
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own login:failed UI update');
                return;
            }
            
            updateUI('appendLoginLog', data, 'login');
        });

        socket.on('logout', (data) => {
            // Skip entirely if this is our own logout — same reasoning as
            // login:success above. Only other connected clients get notified.
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own logout event (toast + UI)');
                return;
            }

            if (typeof NotificationManager !== 'undefined') {
                const name = data.employeeName || data.username || 'Someone';
                NotificationManager.show(`${name} logged out`, 'logout');
            }

            updateUI('appendLoginLog', data, 'logout');
            updateUI('updateEmployeeStatus', data.employeeId, 'offline');
        });

        // ──────────────────────────────────────────────────────────────────
        //  DASHBOARD / PROFILE REFRESH
        // ──────────────────────────────────────────────────────────────────

        socket.on('dashboard:refresh', () => {
            // Silent refresh - no notification
            updateUI('updateDashboardStats');
            updateUI('renderActivityList');
            updateUI('updateBadges');
        });

        socket.on('profile:refresh', (data) => {
            const current = typeof getCurrentUser === 'function' ? getCurrentUser() : null;
            if (current && data && current.id === data.userId) {
                // Silent refresh - no notification
                updateUI('updateProfileTasks');
                updateUI('updateBadges');
            }
        });

        // ──────────────────────────────────────────────────────────────────
        //  FILES / ATTACHMENTS
        // ──────────────────────────────────────────────────────────────────

        socket.on('file:uploaded', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own file:uploaded event');
                return;
            }
            updateUI('addFile', data);
            updateUI('updateBadges');
            
            const fileName = data.title || data.originalFilename || 'a file';
            showNotification(`File uploaded: ${fileName}`, 'success');
        });

        socket.on('file:deleted', (data) => {
            if (wasTriggeredByCurrentUser(data)) {
                console.debug('socket-handler: skipping own file:deleted event');
                return;
            }
            updateUI('removeFile', data.id);
            updateUI('updateBadges');
            
            const fileName = data.title || 'a file';
            showNotification(`File deleted: ${fileName}`, 'warning');
        });

        // ──────────────────────────────────────────────────────────────────
        //  GENERIC ENTITY UPDATE HANDLER
        // ──────────────────────────────────────────────────────────────────

        const HANDLED_EVENTS = new Set([
            'welcome',
            'authenticated',

            'deal:created',
            'deal:updated',
            'deal:deleted',
            'deal:stage-changed',

            'activity:new',
            'task-activity:new',

            'note:created',
            'note:updated',
            'note:deleted',

            'announcement:created',
            'announcement:updated',
            'announcement:deleted',

            'contact:created',
            'contact:updated',
            'contact:deleted',

            'department:created',
            'department:updated',
            'department:deleted',

            'company:created',
            'company:updated',
            'company:deleted',

            'role:created',
            'role:updated',
            'role:deleted',

            'user:created',
            'user:updated',
            'user:deleted'
        ]);

        // ──────────────────────────────────────────────────────────────────
        //  UNHANDLED EVENT LOGGING
        // ──────────────────────────────────────────────────────────────────

        socket.onAny((eventName, ...args) => {
            if (HANDLED_EVENTS.has(eventName)) {
                return;
            }

            console.debug(
                'socket-handler: unhandled event',
                eventName,
                args
            );
        });

        // ── Expose debug helpers ──
        window.__tmsSocketDebug = {
            currentUserId: CURRENT_USER_ID,
            currentContactId: CURRENT_CONTACT_ID,
            wasTriggeredByCurrentUser: wasTriggeredByCurrentUser,
            socketId: socket.id,
            connected: socket.connected,
            url: SOCKET_URL,
            path: SOCKET_PATH,
            isLocalhost: isLocalhost,
            transports: SOCKET_OPTIONS.transports
        };

        console.log(`✅ socket-handler: initialized (userId: ${CURRENT_USER_ID || 'none'}, contactId: ${CURRENT_CONTACT_ID || 'none'})`);
        console.log(`✅ socket-handler: connecting to ${SOCKET_URL}${SOCKET_PATH}`);
        console.log(`✅ socket-handler: Localhost mode: ${isLocalhost ? 'ENABLED (WebSocket-only)' : 'DISABLED (standard)'}`);

        // ── Cleanup on page unload ──
        window.addEventListener('beforeunload', function() {
            if (socket && socket.connected) {
                socket.disconnect();
            }
        });

        return socket;
    }

    // ── Initialize if Socket.IO is available ──
    if (typeof io !== 'undefined') {
        initSocket();
    }
    
    // Cleanly close the socket when the page enters browser cache
    window.addEventListener('pagehide', function () {
        const activeSocket = window.__tmsSocket;

        if (activeSocket && activeSocket.connected) {
            console.log('socket-handler: page hidden, disconnecting socket');
            activeSocket.disconnect();
        }
    });

    // Reconnect after returning through browser Back/Forward navigation
    // Disconnect cleanly before the page enters Back/Forward Cache
    window.addEventListener('pageshow', function (event) {
        const activeSocket = window.__tmsSocket;

        if (event.persisted && activeSocket && !activeSocket.connected) {
            console.log(
                'socket-handler: restored from back-forward cache, reconnecting'
            );

            activeSocket.connect();
        }
    });

})();