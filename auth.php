<?php
session_start();
require_once __DIR__ . '/client_ip.php';

// ── Real-time notifier ──────────────────────────────────────────────────
// Include socket_notifier.php if it exists; fallback to a no‑op function.
$socketNotifierPath = __DIR__ . '/socket_notifier.php';
if (file_exists($socketNotifierPath)) {
    require_once $socketNotifierPath;
}
if (!function_exists('notifySocketServer')) {
    function notifySocketServer(string $event, array $data, int $timeoutMs = 1500): bool { return false; }
}
define('DATA_DIR', __DIR__ . '/../data');
define('DB_FILE', DATA_DIR . '/tms_database.sq3');

// ── Database Connection Helper ───────────────────────────────────────

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DB_FILE)) {
            // If the SQLite file doesn't exist yet, return null so we can handle gracefully
            throw new Exception("Database file does not exist.");
        }
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    return $pdo;
}

// Helper to query a table by ID or retrieve all items from an SQLite table
function findInTable(string $table, ?string $id = null): array {
    try {
        $db = getDb();
        if ($id !== null) {
            $stmt = $db->prepare("SELECT data FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            return $row ? (json_decode($row['data'], true) ?? []) : [];
        } else {
            $stmt = $db->query("SELECT data FROM {$table}");
            $out = [];
            while ($row = $stmt->fetch()) {
                if ($decoded = json_decode($row['data'], true)) {
                    $out[] = $decoded;
                }
            }
            return $out;
        }
    } catch (Exception $e) {
        return [];
    }
}

// Locates a user in the SQLite `users` table by username (case-insensitive)
function findUserByUsername(string $username): ?array {
    $users = findInTable('users');
    foreach ($users as $u) {
        if (isset($u['username']) && strcasecmp($u['username'], $username) === 0) {
            return $u;
        }
    }
    return null;
}

// ── Login Monitoring Functions ─────────────────────────────────────────

/**
 * Write a login/logout event into the shared `activity` table so it
 * shows up in real time on the Dashboard's Recent Activity feed for
 * every connected user — same table + broadcast pattern api.php's
 * logFileActivity() uses for file uploads (INSERT into `activity`,
 * then notifySocketServer('activity:new', ...) so every open tab's
 * socket-handler.js#prependActivity() picks it up instantly, with no
 * page refresh needed).
 * 
 * ✅ SINGLE SOURCE OF TRUTH: This function is the ONLY place that writes
 * login/logout events to the activity table. Client-side logActivity()
 * calls for login events have been removed to prevent duplicates.
 */
function logSystemActivity(string $message, string $color = 'accent', string $category = 'login', string $icon = '🔐'): void {
    try {
        $db = getDb();
        try {
            $db->query("SELECT 1 FROM activity LIMIT 1");
        } catch (PDOException $e) {
            $db->exec("CREATE TABLE IF NOT EXISTS activity (id TEXT PRIMARY KEY, data TEXT)");
        }

        $id = 'act_' . str_replace('.', '', uniqid('', true));
        $entry = [
            'id' => $id,
            'message' => $message,
            'color' => $color,
            'category' => $category,
            'icon' => $icon,
            'createdAt' => date('c'),
            'actorRole' => '',
        ];
        $stmt = $db->prepare("INSERT INTO activity (id, data) VALUES (?, ?)");
        $stmt->execute([$id, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        // Broadcast to all connected clients via Socket.IO
        notifySocketServer('activity:new', $entry);
    } catch (Exception $e) {
        error_log("Failed to log system activity: " . $e->getMessage());
    }
}

/**
 * Resolve a friendly display name for a login/logout activity line —
 * prefers the linked contact's full name, falls back to the username.
 */
function resolveDisplayName(?string $employeeId, string $username): string {
    if ($employeeId) {
        $contact = findInTable('contacts', $employeeId);
        if (!empty($contact['fname']) || !empty($contact['lname'])) {
            return trim(($contact['fname'] ?? '') . ' ' . ($contact['lname'] ?? ''));
        }
    }
    return $username;
}

/**
 * Log a login attempt to the SQLite database
 * ✅ SINGLE SOURCE OF TRUTH: This function handles ALL login logging
 * - Writes to login_logs table for audit
 * - Writes to activity table via logSystemActivity() for Recent Activity
 * - Broadcasts via Socket.IO for real-time UI updates
 */
function logLoginAttempt($userId, $username, $status, $failureReason = null, $additionalData = []) {
    try {
        $db = getDb();
        
        // Check if login_logs table exists
        try {
            $db->query("SELECT 1 FROM login_logs LIMIT 1");
        } catch (PDOException $e) {
            // Table doesn't exist, create it
            $db->exec("CREATE TABLE IF NOT EXISTS login_logs (id TEXT PRIMARY KEY, data TEXT)");
            // Add counter if needed
            $stmt = $db->prepare("SELECT 1 FROM counters WHERE name = 'login_logs'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO counters (name, val) VALUES ('login_logs', 0)");
                $stmt->execute();
            }
        }
        
        // Get next ID from counter
        $stmt = $db->query("SELECT val FROM counters WHERE name = 'login_logs'");
        $row = $stmt->fetch();
        $nextId = ($row ? (int)$row['val'] : 0) + 1;
        
        // Update counter
        $stmt = $db->prepare("INSERT OR REPLACE INTO counters (name, val) VALUES ('login_logs', ?)");
        $stmt->execute([$nextId]);
        
        // Get geolocation data
        $clientIp = getClientIp();
        $geoData = getGeoLocation($clientIp);
        
        // Create comprehensive log entry
        $logEntry = [
            'id' => 'log_' . time() . '_' . bin2hex(random_bytes(8)),
            'userId' => $userId,
            'username' => $username,
            'ipAddress' => $clientIp,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'loginTime' => date('c'),
            'logoutTime' => null,
            'status' => $status, // 'success' or 'failed'
            'failureReason' => $failureReason,
            'sessionId' => session_id(),
            'deviceType' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'browser' => detectBrowser($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'os' => detectOS($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'country' => $geoData['country'] ?? 'Unknown',
            'city' => $geoData['city'] ?? 'Unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            'requestMethod' => $_SERVER['REQUEST_METHOD'] ?? '',
            'additionalData' => $additionalData,
            'createdAt' => date('c')
        ];
        
        // Save to SQLite
        $stmt = $db->prepare("INSERT INTO login_logs (id, data) VALUES (?, ?)");
        $stmt->execute([
            $logEntry['id'], 
            json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);

        // ── Broadcast to Socket.IO clients ──
        // Resolve the linked employee/contact id (if any)
        $employeeId = null;
        $users = findInTable('users');
        foreach ($users as $u) {
            if (($u['id'] ?? null) === $userId) {
                $employeeId = $u['employeeId'] ?? null;
                break;
            }
        }
        $broadcastEntry = $logEntry;
        $broadcastEntry['employeeId'] = $employeeId;
        notifySocketServer($status === 'success' ? 'login:success' : 'login:failed', $broadcastEntry);

        // ── Mirror into Recent Activity (Dashboard) ──
        // ✅ SINGLE SOURCE OF TRUTH: This is the ONLY place that logs
        // login events to the activity table. Client-side logActivity()
        // calls have been removed to prevent duplicates.
        $displayName = resolveDisplayName($employeeId, $username);
        if ($status === 'success') {
            logSystemActivity("{$displayName} logged in", 'success', 'login', '🔓');
        } else {
            $reasonText = $failureReason ? " ({$failureReason})" : '';
            logSystemActivity("Failed login attempt for {$displayName}{$reasonText}", 'orange', 'login', '⚠️');
        }

        return true;
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
        return false;
    }
}

/**
 * Update logout time when user logs out
 * ✅ SINGLE SOURCE OF TRUTH: This function handles ALL logout logging
 */
function logLogout($userId, $username) {
    try {
        $db = getDb();
        
        // Find the most recent active session for this user
        $stmt = $db->prepare("
            SELECT data FROM login_logs 
            WHERE json_extract(data, '$.userId') = ? 
            AND json_extract(data, '$.logoutTime') IS NULL
            ORDER BY json_extract(data, '$.loginTime') DESC 
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        
        if ($row) {
            $log = json_decode($row['data'], true);
            if ($log && $log['userId'] === $userId) {
                $log['logoutTime'] = date('c');
                $log['sessionDuration'] = calculateDuration($log['loginTime'], $log['logoutTime']);
                
                $stmt = $db->prepare("UPDATE login_logs SET data = ? WHERE id = ?");
                $stmt->execute([json_encode($log), $log['id']]);

                // ── Broadcast logout ──
                $employeeId = null;
                $users = findInTable('users');
                foreach ($users as $u) {
                    if (($u['id'] ?? null) === $userId) {
                        $employeeId = $u['employeeId'] ?? null;
                        break;
                    }
                }
                $broadcastEntry = $log;
                $broadcastEntry['employeeId'] = $employeeId;
                notifySocketServer('logout', $broadcastEntry);

                // ── Mirror into Recent Activity (Dashboard) ──
                // ✅ SINGLE SOURCE OF TRUTH: This is the ONLY place that logs
                // logout events to the activity table.
                $displayName = resolveDisplayName($employeeId, $username);
                logSystemActivity("{$displayName} logged out", 'accent', 'logout', '🚪');

                return true;
            }
        }
        return false;
    } catch (Exception $e) {
        error_log("Failed to log logout: " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate session duration in seconds
 */
function calculateDuration($start, $end) {
    try {
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        return $endTime - $startTime;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Detect device type from user agent
 */
function detectDeviceType($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    $userAgent = strtolower($userAgent);
    if (strpos($userAgent, 'mobile') !== false) return 'Mobile';
    if (strpos($userAgent, 'tablet') !== false) return 'Tablet';
    if (strpos($userAgent, 'ipad') !== false) return 'Tablet';
    return 'Desktop';
}

/**
 * Detect browser from user agent
 */
function detectBrowser($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    $userAgent = strtolower($userAgent);
    if (strpos($userAgent, 'chrome') !== false && strpos($userAgent, 'edge') === false) return 'Chrome';
    if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
    if (strpos($userAgent, 'safari') !== false && strpos($userAgent, 'chrome') === false) return 'Safari';
    if (strpos($userAgent, 'edge') !== false) return 'Edge';
    if (strpos($userAgent, 'opera') !== false || strpos($userAgent, 'opr') !== false) return 'Opera';
    return 'Other';
}

/**
 * Detect OS from user agent
 */
function detectOS($userAgent) {
    if (empty($userAgent)) return 'Unknown';
    $userAgent = strtolower($userAgent);
    if (strpos($userAgent, 'windows') !== false) return 'Windows';
    if (strpos($userAgent, 'mac os') !== false) return 'macOS';
    if (strpos($userAgent, 'linux') !== false) return 'Linux';
    if (strpos($userAgent, 'android') !== false) return 'Android';
    if (strpos($userAgent, 'ios') !== false || strpos($userAgent, 'iphone') !== false) return 'iOS';
    return 'Other';
}

/**
 * Get geolocation from IP (using free API)
 */
function getGeoLocation($ip) {
    // Skip for local/private IPs
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return ['country' => 'Local', 'city' => 'Local'];
    }
    
    try {
        // Use free ip-api.com (rate limited to 45 requests/minute)
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country,city,lat,lon");
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['country'])) {
                return [
                    'country' => $data['country'],
                    'city' => $data['city'] ?? 'Unknown',
                    'lat' => $data['lat'] ?? null,
                    'lon' => $data['lon'] ?? null
                ];
            }
        }
    } catch (Exception $e) {
        // Silently fail - geolocation is optional
    }
    return ['country' => 'Unknown', 'city' => 'Unknown'];
}

/**
 * Check for suspicious login attempts (rate limiting)
 */
function checkLoginRate($username, $ip) {
    try {
        $db = getDb();
        $maxAttempts = 5; // Max failed attempts
        $timeWindow = 900; // 15 minutes in seconds
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_logs 
            WHERE json_extract(data, '$.username') = ? 
            AND json_extract(data, '$.status') = 'failed' 
            AND datetime(json_extract(data, '$.loginTime')) > datetime('now', ? || ' seconds ago')
        ");
        $stmt->execute([$username, '-' . $timeWindow]);
        $row = $stmt->fetch();
        
        return $row['attempts'] < $maxAttempts;
    } catch (Exception $e) {
        error_log("Failed to check login rate: " . $e->getMessage());
        return true; // Allow login on error
    }
}

// ── Action Handlers ────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        // Log failed attempt - empty credentials
        logLoginAttempt(null, $username, 'failed', 'Empty credentials');
        header('Location: login.html?error=' . urlencode('Username and password required.'));
        exit;
    }

    // Check rate limiting before attempting login
    if (!checkLoginRate($username, getClientIp())) {
        logLoginAttempt(null, $username, 'failed', 'Too many failed attempts (rate limited)');
        header('Location: login.html?error=' . urlencode('Too many failed attempts. Please wait 15 minutes.'));
        exit;
    }

    $user = findUserByUsername($username);

    // Check if user exists and password matches
    if (!$user) {
        logLoginAttempt(null, $username, 'failed', 'User not found');
        header('Location: login.html?error=' . urlencode('Invalid username or password.'));
        exit;
    }

    if (($user['password'] ?? '') !== $password) {
        logLoginAttempt($user['id'] ?? null, $username, 'failed', 'Invalid password');
        header('Location: login.html?error=' . urlencode('Invalid username or password.'));
        exit;
    }

    // Check if user account is active
    if (($user['status'] ?? '') !== 'active') {
        logLoginAttempt($user['id'] ?? null, $username, 'failed', 'Account inactive');
        header('Location: login.html?error=' . urlencode('Account is inactive. Please contact administrator.'));
        exit;
    }

    // ── Successful Login ──────────────────────────────────────────────

    // ✅ Log successful login before creating session
    logLoginAttempt($user['id'] ?? null, $username, 'success');

    // Lookup corresponding Employee and Role records from SQLite
    $employee = !empty($user['employeeId']) ? findInTable('contacts', $user['employeeId']) : [];
    $role = !empty($user['role']) ? findInTable('roles', $user['role']) : [];

    // Build session payload
    $_SESSION['user'] = [
        'id' => $user['id'] ?? '',
        'username' => $user['username'] ?? '',
        'role' => $user['role'] ?? '',
        'roleName' => $role['name'] ?? 'Unknown',
        'employeeId' => $user['employeeId'] ?? '',
        'name' => (!empty($employee['fname']) || !empty($employee['lname'])) 
            ? trim(($employee['fname'] ?? '') . ' ' . ($employee['lname'] ?? '')) 
            : ($user['username'] ?? ''),
        'email' => $employee['email'] ?? '',
        'status' => $user['status'] ?? 'active',
    ];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time(); // Track session start time

    // Redirect to main application
    header('Location: index.php');
    exit;
}

if ($action === 'logout') {
    // ✅ Log logout before destroying session
    if (isset($_SESSION['user']['id'])) {
        logLogout($_SESSION['user']['id'], $_SESSION['user']['username'] ?? 'Unknown');
    }
    
    // Clear session data
    $_SESSION = [];
    
    // Remove session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    header('Location: login.html');
    exit;
}

if ($action === 'check') {
    header('Content-Type: application/json');
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        // Check if session has expired (optional: 8 hour timeout)
        $maxSessionTime = 28800; // 8 hours in seconds
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $maxSessionTime) {
            // Session expired - force logout
            echo json_encode(['loggedIn' => false, 'expired' => true]);
            exit;
        }
        
        echo json_encode([
            'loggedIn' => true, 
            'user' => $_SESSION['user'],
            'sessionTime' => time() - ($_SESSION['login_time'] ?? time())
        ]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
    exit;
}

// ── Additional Endpoint: Get current session info ─────────────────────

if ($action === 'session') {
    header('Content-Type: application/json');
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $sessionDuration = isset($_SESSION['login_time']) ? time() - $_SESSION['login_time'] : 0;
        echo json_encode([
            'ok' => true,
            'loggedIn' => true,
            'user' => $_SESSION['user'],
            'sessionDuration' => $sessionDuration,
            'sessionDurationFormatted' => formatDuration($sessionDuration)
        ]);
    } else {
        echo json_encode(['ok' => false, 'loggedIn' => false]);
    }
    exit;
}

// ── Helper function for session duration formatting ──────────────────

function formatDuration($seconds) {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return $hours . 'h ' . $minutes . 'm';
}

// Unknown action fallback
header('Location: login.html');
exit;
?>