<?php
/**
 * api.php — TMS SQLite Backend
 *
 * Uses SQLite (via PDO) for persistent, thread-safe storage while
 * remaining backward-compatible with the existing frontend payload
 * formats (base64-encoded JSON snapshots).
 *
 * All data is stored in a single SQLite database file (data/tms_database.sq3)
 * with a simple key-value table structure (id, data JSON) to keep the
 * schema flexible and match the original JavaScript data model.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Real-time (Socket.IO) notifier ──────────────────────────────────────
// notifySocketServer($event, $data) posts to the Node.js process in
// /socket, which broadcasts to connected browser clients. Falls back to
// a safe no-op if that folder hasn't been deployed on this environment
// yet, so nothing here becomes a hard dependency.
$socketNotifierPath = __DIR__ . '/socket_notifier.php';
if (file_exists($socketNotifierPath)) {
    require_once $socketNotifierPath;
}
if (!function_exists('notifySocketServer')) {
    function notifySocketServer(string $event, array $data, int $timeoutMs = 1500): bool { return false; }
}

define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('DB_FILE', DATA_DIR . '/tms_database.sq3');

const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; // 10MB
const ALLOWED_DOCUMENTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx', 'txt'];
const ALLOWED_IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

// Deterministic extension -> MIME map, used instead of the browser-supplied
// upload MIME type when serving files back out (see serveFile below).
// Browsers are inconsistent about what Content-Type they report for
// uploads (some send 'application/octet-stream' or nothing for .xlsx/.csv
// etc.), and Microsoft's Office Online Viewer needs the exact expected
// Content-Type to recognize and render a file — a wrong/empty one is
// another way previews silently fail besides the hotlink issue.
const MIME_TYPES = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'  => 'text/csv',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'txt'  => 'text/plain',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
];

const TABLES = [
    'contacts', 'deals', 'tasks', 'notes', 'activity',
    'taskActivity', 'departments', 'companies', 'roles',
    'stages', 'users', 'files', 'counters', 'login_logs'
];

// ── Real-time broadcast mapping ─────────────────────────────────────────
// This app saves whole-array table snapshots from the client (see the
// 'save' action below) rather than per-record CRUD, so there's no single
// point that already knows "this one deal changed". These maps tell the
// 'save' handler which tables to diff old-vs-new after syncTable() and
// what Socket.IO event prefix to broadcast under — matching the listeners
// in js/modules/socket-handler.js (e.g. 'deals' -> 'deal:created').
const BROADCAST_ENTITY_TABLES = [
    'deals'       => 'deal',
    'contacts'    => 'contact',
    'notes'       => 'note',
    'tasks'       => 'announcement',
    'departments' => 'department',
    'companies'   => 'company',
    'roles'       => 'role',
    'users'       => 'user',
];

// Append-only log tables — we only ever broadcast newly-appeared rows,
// never updates or deletes, under a single fixed event name.
const BROADCAST_LOG_TABLES = [
    'activity'     => 'activity:new',
    'taskActivity' => 'task-activity:new',
];

// Cap on stored rows for append-only log tables (activity, taskActivity),
// enforced server-side after each save so the table can't grow forever.
// Trimming keeps the newest rows by createdAt, never the rows a client
// happened to have loaded most recently.
const LOG_TABLE_MAX_ROWS = 500;

// ── Production-Safe Bootstrap Helpers ──────────────────────────────────

/**
 * Ensure a directory exists and is writable. Throws with a clear message
 * instead of failing silently (which is what @mkdir() was doing before).
 */
function ensureDirectory(string $dir): void {
    if (is_dir($dir)) {
        if (!is_writable($dir)) {
            @chmod($dir, 0755);
        }
        return;
    }

    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException(
            "Unable to create directory: {$dir}. Check that the parent folder " .
            "exists and is writable by PHP on your hosting account."
        );
    }
}

/**
 * Write a protective .htaccess into a folder if one isn't already there.
 * Safe no-op on hosts that don't use Apache/.htaccess.
 */
function ensureHtaccess(string $dir, string $contents): void {
    $path = $dir . '/.htaccess';
    if (!file_exists($path)) {
        @file_put_contents($path, $contents);
    }
}

// ── Database Initialization ──────────────────────────────────────────

function getDb(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        ensureDirectory(DATA_DIR);
        ensureHtaccess(DATA_DIR, "Require all denied\n");

        if (!file_exists(DB_FILE)) {
            if (@touch(DB_FILE) === false) {
                throw new RuntimeException(
                    "Unable to create database file: " . DB_FILE . ". " .
                    "The 'data' directory exists but PHP could not write the SQLite " .
                    "file into it — check folder permissions on your host."
                );
            }
            @chmod(DB_FILE, 0644);
        }

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL;'); // High concurrency mode
    }
    return $pdo;
}

function initTables(): void {
    $db = getDb();
    
    // Create KV-like SQLite table per data entity to preserve dynamic JS JSON schemas
    foreach (TABLES as $table) {
        if ($table === 'counters') {
            $db->exec("CREATE TABLE IF NOT EXISTS counters (name TEXT PRIMARY KEY, val INTEGER)");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS {$table} (id TEXT PRIMARY KEY, data TEXT)");
        }
    }

    ensureSeeded();
    migrateDatabase(); // Run migration to ensure login_logs exists
}

function ensureUploadDirectories(): void {
    $dirs = [
        UPLOAD_DIR,
        UPLOAD_DIR . '/tasks',
        UPLOAD_DIR . '/tasks/files',
        UPLOAD_DIR . '/tasks/images',
    ];
    foreach ($dirs as $dir) {
        ensureDirectory($dir);
    }
    // Prevent directory listing of uploaded files (harmless if host ignores .htaccess)
    ensureHtaccess(UPLOAD_DIR, "Options -Indexes\n");
}

// Default seed data, written the first time the database is initialized.
function defaultSeed(): array {
    $now = date('c');
    $companyId = 'comp1';
    $departmentId = 'dep1';
    $roleId = 'role_system_admin';
    $employeeId = '1';
    $userId = '1';

    return [
        'contacts'    => [[
            'id' => $employeeId,
            'fname' => 'System',
            'lname' => 'Administrator',
            'email' => 'admin@tms.local',
            'role' => $roleId,
            'departmentId' => $departmentId,
            'companyId' => $companyId,
            'status' => 'active',
            'createdAt' => $now,
        ]],
        'deals'       => [],
        'tasks'       => [],
        'notes'       => [],
        'activity'    => [],
        'taskActivity' => [],
        'departments' => [[
            'id' => $departmentId,
            'name' => 'Engineering',
            'desc' => 'Core tech dev',
            'status' => 'active',
            'companyId' => $companyId,
            'createdAt' => $now,
        ]],
        'companies'   => [[
            'id' => $companyId,
            'name' => 'TMS Global',
            'industry' => 'Software',
            'phone' => '123',
            'status' => 'active',
            'createdAt' => $now,
        ]],
        'roles'       => [[
            'id' => $roleId,
            'name' => 'System Administrator',
            'desc' => 'Built-in role. Cannot be deleted. Full access to every permission, on every stage, always.',
            'status' => 'active',
            'system' => true,
            'inheritsFrom' => '',
            'createdAt' => $now,
        ]],
        'stages'      => [
            [
                'key' => 'todo', 'label' => 'To Do', 'color' => '#4f8ef7', 'final' => false,
                'permissions' => [
                    'role_system_admin' => ['grab' => true, 'drop' => true, 'edit' => true, 'comment' => true, 'revision' => true, 'upload' => true],
                ],
            ],
            [
                'key' => 'inprogress', 'label' => 'In Progress', 'color' => '#a78bfa', 'final' => false,
                'permissions' => [
                    'role_system_admin' => ['grab' => true, 'drop' => true, 'edit' => true, 'comment' => true, 'revision' => true, 'upload' => true],
                ],
            ],
        ],
        // Default Admin User added here
        'users'       => [[
            'id' => $userId,
            'username' => 'admin',
            'password' => 'admin123',
            'role' => $roleId,
            'employeeId' => $employeeId,
            'status' => 'active',
            'createdAt' => $now,
        ]],
        'files'       => [],
        'login_logs'  => [], // Empty login logs table
        'counters'    => [
            'contacts' => 1, 'deals' => 0, 'tasks' => 0, 'notes' => 0, 'activity' => 0,
            'taskActivity' => 0, 'departments' => 1, 'companies' => 1, 'roles' => 1, 
            'stages' => 0, 'users' => 1, 'files' => 0, 'login_logs' => 0,
        ],
    ];
}

function ensureSeeded(): void {
    $db = getDb();
    
    // Check if stages are present; if empty, run initial seed
    $stmt = $db->query("SELECT COUNT(*) as count FROM stages");
    if ($stmt->fetch()['count'] == 0) {
        $seed = defaultSeed();
        $db->beginTransaction();
        try {
            foreach ($seed as $table => $items) {
                if ($table === 'counters') {
                    $cStmt = $db->prepare("INSERT OR REPLACE INTO counters (name, val) VALUES (?, ?)");
                    foreach ($items as $name => $val) {
                        $cStmt->execute([$name, $val]);
                    }
                } else {
                    $iStmt = $db->prepare("INSERT OR REPLACE INTO {$table} (id, data) VALUES (?, ?)");
                    foreach ($items as $item) {
                        $id = $item['id'] ?? ($item['key'] ?? uniqid());
                        $iStmt->execute([$id, json_encode($item)]);
                    }
                }
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

// ── Database Migration ──────────────────────────────────────────────────

function migrateDatabase(): void {
    $db = getDb();
    
    // Check if login_logs table exists, create if not
    try {
        $db->query("SELECT 1 FROM login_logs LIMIT 1");
    } catch (PDOException $e) {
        // Table doesn't exist - create it
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_logs (
                id TEXT PRIMARY KEY, 
                data TEXT
            )
        ");
        
        // Add to counters if missing
        $stmt = $db->prepare("SELECT 1 FROM counters WHERE name = 'login_logs'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO counters (name, val) VALUES ('login_logs', 0)");
            $stmt->execute();
        }
        
        error_log("TMS: Created login_logs table during migration");
    }
    
    // Create indexes for faster queries (optional, ignore errors if they exist)
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_logs_user ON login_logs(json_extract(data, '$.userId'))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_logs_time ON login_logs(json_extract(data, '$.loginTime'))");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_logs_status ON login_logs(json_extract(data, '$.status'))");
    } catch (Exception $e) {
        // Index creation failed - not critical
    }
}

// ── Login Monitoring Functions ─────────────────────────────────────────

/**
 * Log a login attempt to the SQLite database
 */
function logLoginAttempt($userId, $username, $status, $failureReason = null, $additionalData = []) {
    try {
        $db = getDb();
        
        // Get next ID from counter
        $stmt = $db->query("SELECT val FROM counters WHERE name = 'login_logs'");
        $row = $stmt->fetch();
        $nextId = ($row ? (int)$row['val'] : 0) + 1;
        
        // Update counter
        $stmt = $db->prepare("INSERT OR REPLACE INTO counters (name, val) VALUES ('login_logs', ?)");
        $stmt->execute([$nextId]);
        
        // Get geolocation data
        $geoData = getGeoLocation($_SERVER['REMOTE_ADDR'] ?? '');
        
        // Create comprehensive log entry
        $logEntry = [
            'id' => 'log_' . time() . '_' . bin2hex(random_bytes(8)),
            'userId' => $userId,
            'username' => $username,
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
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

        // Resolve the linked employee/contact id (if any) so the client
        // can update that person's status indicator (employee-directory.js
        // #updateEmployeeStatus) without a full directory refetch.
        $employeeId = null;
        foreach (readTable('users') as $u) {
            if (($u['id'] ?? null) === $userId) { $employeeId = $u['employeeId'] ?? null; break; }
        }
        $broadcastEntry = $logEntry;
        $broadcastEntry['employeeId'] = $employeeId;
        notifySocketServer($status === 'success' ? 'login:success' : 'login:failed', $broadcastEntry);
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
        return false;
    }
}

/**
 * Update logout time when user logs out
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

                $employeeId = null;
                foreach (readTable('users') as $u) {
                    if (($u['id'] ?? null) === $userId) { $employeeId = $u['employeeId'] ?? null; break; }
                }
                $broadcastEntry = $log;
                $broadcastEntry['employeeId'] = $employeeId;
                notifySocketServer('logout', $broadcastEntry);

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

// ── Read/Write Layer ─────────────────────────────────────────────────

function readTable(string $table): array {
    $db = getDb();
    if ($table === 'counters') {
        $stmt = $db->query("SELECT name, val FROM counters");
        $out = [];
        while ($row = $stmt->fetch()) {
            $out[$row['name']] = (int)$row['val'];
        }
        return $out;
    }

    // Append-only log tables (activity, taskActivity) are always meant to
    // be read newest-first. There's no reliable INSERT/rowid ordering to
    // lean on once rows can be merged in out of order (see syncTable()),
    // so sort explicitly by the entry's own createdAt here.
    if (isset(BROADCAST_LOG_TABLES[$table])) {
        $stmt = $db->prepare("SELECT data FROM {$table} ORDER BY json_extract(data, '$.createdAt') DESC");
    } else {
        $stmt = $db->prepare("SELECT data FROM {$table}");
    }
    $stmt->execute();
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = json_decode($row['data'], true);
    }
    return $out;
}

function syncTable(string $table, array $items): bool {
    $db = getDb();
    $db->beginTransaction();
    try {
        if ($table === 'counters') {
            $stmt = $db->prepare("INSERT OR REPLACE INTO counters (name, val) VALUES (?, ?)");
            foreach ($items as $k => $v) {
                $stmt->execute([$k, (int)$v]);
            }
        } else {
            // Truncate and replace table data safely inside transaction
            $db->exec("DELETE FROM {$table}");
            $stmt = $db->prepare("INSERT INTO {$table} (id, data) VALUES (?, ?)");
            foreach ($items as $item) {
                $id = $item['id'] ?? ($item['key'] ?? str_replace('.', '', uniqid('', true)));
                $stmt->execute([$id, json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            }
        }
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

/**
 * Compare an old and new snapshot of a table's rows (keyed by `id`) and
 * return which were created, updated (by value), or deleted. This is how
 * we recover per-record change events from syncTable()'s whole-array
 * replace, so the 'save' action can broadcast granular Socket.IO events
 * instead of "something changed, refetch everything".
 */
function diffTableChanges(array $oldItems, array $newItems): array {
    $oldById = [];
    foreach ($oldItems as $item) {
        if (isset($item['id'])) $oldById[$item['id']] = $item;
    }
    $created = [];
    $updated = [];
    $seenIds = [];
    foreach ($newItems as $item) {
        $id = $item['id'] ?? null;
        if ($id === null) continue;
        $seenIds[$id] = true;
        if (!array_key_exists($id, $oldById)) {
            $created[] = $item;
        } elseif (json_encode($oldById[$id]) !== json_encode($item)) {
            $updated[] = $item;
        }
    }
    $deleted = [];
    foreach ($oldById as $id => $item) {
        if (!isset($seenIds[$id])) $deleted[] = $id;
    }
    return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
}

/**
 * Broadcast a diffTableChanges() result as create/update/delete events
 * under the given prefix, e.g. broadcastTableDiff('deal', $diff) emits
 * 'deal:created', 'deal:updated', 'deal:deleted'.
 */
function broadcastTableDiff(string $eventPrefix, array $diff): void {
    foreach ($diff['created'] as $item) {
        notifySocketServer("{$eventPrefix}:created", $item);
    }
    foreach ($diff['updated'] as $item) {
        notifySocketServer("{$eventPrefix}:updated", $item);
    }
    foreach ($diff['deleted'] as $id) {
        notifySocketServer("{$eventPrefix}:deleted", ['id' => $id]);
    }
}

function decodePostPayload() {
    if (isset($_POST['payload'])) {
        $decoded = base64_decode($_POST['payload'], true);
        if ($decoded !== false) {
            $data = json_decode($decoded, true);
            if ($data !== null) return $data;
        }
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $data = json_decode($raw, true);
        if ($data !== null) return $data;
    }
    return null;
}

// ── File Helpers ─────────────────────────────────────────────────────

// Translates PHP's UPLOAD_ERR_* codes into a human-readable reason.
// Spreadsheets are usually the first file type to hit these on shared
// hosting — formatting/formulas/multiple sheets push .xlsx past size
// limits well before a plain .pdf or .docx would.
function uploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'File exceeds this server\'s upload_max_filesize limit (set in PHP, controlled by your host).';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File exceeds the form\'s maximum upload size.';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded — check your connection and try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was received by the server.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server has no temporary folder configured for uploads.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server failed to write the uploaded file to disk (check folder permissions).';
        case UPLOAD_ERR_EXTENSION:
            return 'A server-side PHP extension blocked this upload.';
        default:
            return 'Upload failed (PHP error code ' . $code . ').';
    }
}

function validateUpload($file) {
    if (!isset($file) || !is_array($file) || !isset($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file provided'];
    }
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => uploadErrorMessage((int)($file['error'] ?? -1))];
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_UPLOAD_SIZE) {
        return ['ok' => false, 'error' => 'File must be between 1 byte and ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB (this app\'s own limit — separate from your host\'s PHP limits).'];
    }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (in_array($extension, ALLOWED_IMAGES, true)) return ['ok' => true, 'extension' => $extension, 'type' => 'image'];
    if (in_array($extension, ALLOWED_DOCUMENTS, true)) return ['ok' => true, 'extension' => $extension, 'type' => 'file'];
    return ['ok' => false, 'error' => 'File type not allowed'];
}

function logFileActivity(string $message, string $color = 'accent', string $actorName = '', string $actorRole = ''): void {
    $db = getDb();
    $id = 'act_' . str_replace('.', '', uniqid('', true));
    $entry = [
        'id' => $id,
        'message' => $actorName !== '' ? ($actorName . ' ' . $message) : $message,
        'color' => $color,
        'category' => 'file',
        'icon' => '📎',
        'createdAt' => date('c'),
        'actorRole' => $actorRole,
    ];
    $stmt = $db->prepare("INSERT INTO activity (id, data) VALUES (?, ?)");
    $stmt->execute([$id, json_encode($entry)]);

    notifySocketServer('activity:new', $entry);
}

// ── Main Controller Execution ────────────────────────────────────────

try {
    initTables();
    ensureUploadDirectories();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Bootstrap failed: ' . $e->getMessage(),
    ]);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'load');

// GET LOAD ACTION
if ($action === 'load') {
    $out = [];
    foreach (TABLES as $table) {
        $out[$table] = readTable($table);
    }
    echo json_encode(['ok' => true, 'db' => $out]);
    exit;
}

// POST SAVE ACTION
if ($action === 'save') {
    $payload = decodePostPayload();
    if (!is_array($payload) || !isset($payload['db']) || !is_array($payload['db'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing payload']);
        exit;
    }
    $db = $payload['db'];
    $results = [];
    $allOk = true;

    // Snapshot "before" state for any table we'll want to diff & broadcast,
    // since syncTable() below replaces each table wholesale — this is our
    // only chance to see what it looked like beforehand.
    $beforeSnapshots = [];
    foreach (TABLES as $table) {
        if (array_key_exists($table, $db) && (isset(BROADCAST_ENTITY_TABLES[$table]) || isset(BROADCAST_LOG_TABLES[$table]))) {
            $beforeSnapshots[$table] = readTable($table);
        }
    }

    foreach (TABLES as $table) {
        if (array_key_exists($table, $db)) {
            $ok = syncTable($table, $db[$table]);
            $results[$table] = $ok;
            if (!$ok) { $allOk = false; continue; }

            if (isset(BROADCAST_ENTITY_TABLES[$table])) {
                $diff = diffTableChanges($beforeSnapshots[$table], $db[$table]);
                broadcastTableDiff(BROADCAST_ENTITY_TABLES[$table], $diff);
            } elseif (isset(BROADCAST_LOG_TABLES[$table])) {
                $diff = diffTableChanges($beforeSnapshots[$table], $db[$table]);
                foreach ($diff['created'] as $item) {
                    notifySocketServer(BROADCAST_LOG_TABLES[$table], $item);
                }
            }
        }
    }
    echo json_encode(['ok' => $allOk, 'saved' => $results]);
    exit;
}

// ── Login Monitoring Endpoints ────────────────────────────────────────

// Get login logs with filtering
if ($action === 'getLoginLogs') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $userId = isset($_GET['userId']) ? trim($_GET['userId']) : null;
    $username = isset($_GET['username']) ? trim($_GET['username']) : null;
    
    $db = getDb();
    $sql = "SELECT data FROM login_logs";
    $params = [];
    
    $where = [];
    if ($status) {
        $where[] = "json_extract(data, '$.status') = ?";
        $params[] = $status;
    }
    if ($userId) {
        $where[] = "json_extract(data, '$.userId') = ?";
        $params[] = $userId;
    }
    if ($username) {
        $where[] = "json_extract(data, '$.username') LIKE ?";
        $params[] = '%' . $username . '%';
    }
    
    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    
    $sql .= " ORDER BY json_extract(data, '$.loginTime') DESC LIMIT ?";
    $params[] = $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $logs = [];
    while ($row = $stmt->fetch()) {
        $logs[] = json_decode($row['data'], true);
    }
    
    echo json_encode(['ok' => true, 'logs' => $logs, 'count' => count($logs)]);
    exit;
}

// Get login statistics
if ($action === 'getLoginStats') {
    $db = getDb();
    
    // Total logins
    $stmt = $db->query("SELECT COUNT(*) as total FROM login_logs");
    $total = $stmt->fetch()['total'];
    
    // Failed logins
    $stmt = $db->query("SELECT COUNT(*) as failed FROM login_logs WHERE json_extract(data, '$.status') = 'failed'");
    $failed = $stmt->fetch()['failed'];
    
    // Unique users
    $stmt = $db->query("SELECT COUNT(DISTINCT json_extract(data, '$.userId')) as users FROM login_logs");
    $users = $stmt->fetch()['users'];
    
    // Logins in last 24 hours
    $stmt = $db->query("
        SELECT COUNT(*) as last24 
        FROM login_logs 
        WHERE datetime(json_extract(data, '$.loginTime')) > datetime('now', '-1 day')
    ");
    $last24 = $stmt->fetch()['last24'];
    
    // Active sessions (logged in, no logout)
    $stmt = $db->query("
        SELECT COUNT(*) as active 
        FROM login_logs 
        WHERE json_extract(data, '$.logoutTime') IS NULL
        AND datetime(json_extract(data, '$.loginTime')) > datetime('now', '-30 minutes')
    ");
    $active = $stmt->fetch()['active'];
    
    // Most active users
    $stmt = $db->query("
        SELECT 
            json_extract(data, '$.userId') as userId,
            json_extract(data, '$.username') as username,
            COUNT(*) as count
        FROM login_logs 
        WHERE json_extract(data, '$.status') = 'success'
        GROUP BY json_extract(data, '$.userId')
        ORDER BY count DESC
        LIMIT 5
    ");
    $topUsers = [];
    while ($row = $stmt->fetch()) {
        $topUsers[] = [
            'userId' => $row['userId'],
            'username' => $row['username'],
            'count' => (int)$row['count']
        ];
    }
    
    echo json_encode([
        'ok' => true,
        'stats' => [
            'total' => (int)$total,
            'failed' => (int)$failed,
            'uniqueUsers' => (int)$users,
            'last24Hours' => (int)$last24,
            'activeSessions' => (int)$active,
            'successRate' => $total > 0 ? round((($total - $failed) / $total) * 100, 2) : 0,
            'topUsers' => $topUsers
        ]
    ]);
    exit;
}

// Clear old login logs (admin function)
if ($action === 'clearLoginLogs') {
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    
    $db = getDb();
    $stmt = $db->prepare("
        DELETE FROM login_logs 
        WHERE datetime(json_extract(data, '$.loginTime')) < datetime('now', ? || ' days ago')
    ");
    $stmt->execute(['-' . $days]);
    $deleted = $stmt->rowCount();
    
    echo json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'message' => "Deleted {$deleted} login logs older than {$days} days"
    ]);
    exit;
}

// Get login log by ID
if ($action === 'getLoginLog') {
    $id = isset($_GET['id']) ? trim($_GET['id']) : '';
    if (empty($id)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Log ID required']);
        exit;
    }
    
    $db = getDb();
    $stmt = $db->prepare("SELECT data FROM login_logs WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if ($row) {
        echo json_encode(['ok' => true, 'log' => json_decode($row['data'], true)]);
    } else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Log entry not found']);
    }
    exit;
}

// ── File Attachment Endpoints ──────────────────────────────────────────

if ($action === 'uploadFile') {
    // If the whole POST body exceeded PHP's post_max_size, PHP silently
    // empties BOTH $_POST and $_FILES — no UPLOAD_ERR_* code at all, so
    // validateUpload() below would just see "no file provided" with no
    // clue why. This is the most common reason spreadsheet uploads fail
    // while smaller files (a plain PDF, a small image) work fine —
    // .xlsx files with formatting/formulas/multiple sheets are often the
    // first to cross a shared host's (often low) size ceiling. Surface
    // it explicitly instead of a generic error.
    if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        http_response_code(413);
        echo json_encode([
            'ok' => false,
            'error' => 'This file is too large for the server to accept in one upload (PHP post_max_size/upload_max_filesize limit — set by your host, not this app). Try a smaller file, or ask InfinityFree support / your control panel to raise the limit.',
        ]);
        exit;
    }

    $taskId = trim((string)($_POST['taskId'] ?? ''));
    if ($taskId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'taskId is required']);
        exit;
    }

    $validation = validateUpload($_FILES['file'] ?? null);
    if (!$validation['ok']) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $validation['error']]);
        exit;
    }

    $file = $_FILES['file'];
    $extension = $validation['extension'];
    $type = $validation['type'];

    $safeBase = trim(preg_replace('/[^A-Za-z0-9_\-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME)), '-');
    $uniqueFilename = ($safeBase ?: 'file') . '-' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
    $destDir = $type === 'image' ? UPLOAD_DIR . '/tasks/images' : UPLOAD_DIR . '/tasks/files';
    $destAbsolutePath = $destDir . '/' . $uniqueFilename;
    $relativePath = 'uploads/tasks/' . ($type === 'image' ? 'images' : 'files') . '/' . $uniqueFilename;

    if (!@move_uploaded_file($file['tmp_name'], $destAbsolutePath)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
        exit;
    }

    $files = readTable('files');
    $max = 0;
    foreach ($files as $f) {
        if (preg_match('/^file_(\d+)$/', $f['id'] ?? '', $matches)) {
            $max = max($max, (int)$matches[1]);
        }
    }
    $id = 'file_' . ($max + 1);

    $now = date('c');
    $record = [
        'id' => $id,
        'taskId' => $taskId,
        'title' => trim((string)($_POST['title'] ?? '')) ?: $file['name'],
        'filename' => $uniqueFilename,
        'originalFilename' => $file['name'],
        'extension' => $extension,
        'mimeType' => MIME_TYPES[$extension] ?? ($file['type'] ?? ''),
        'size' => (int)$file['size'],
        'type' => $type,
        'uploadedBy' => $_POST['uploadedBy'] ?? '',
        'uploadedDate' => $now,
        'path' => $relativePath,
        'createdAt' => $now,
        'updatedAt' => $now,
    ];

    $db = getDb();
    $stmt = $db->prepare("INSERT INTO files (id, data) VALUES (?, ?)");
    $ok = $stmt->execute([$id, json_encode($record)]);

    if (!$ok) {
        @unlink($destAbsolutePath);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed metadata storage']);
        exit;
    }

    logFileActivity('uploaded a file: ' . $record['title'], 'accent', (string)($_POST['actorName'] ?? ''), (string)($_POST['actorRole'] ?? ''));
    notifySocketServer('file:uploaded', $record);
    echo json_encode(['ok' => true, 'file' => $record]);
    exit;
}

if ($action === 'deleteFile') {
    $id = trim((string)($_POST['id'] ?? ($_GET['id'] ?? '')));
    $files = readTable('files');
    $record = null;
    foreach ($files as $f) {
        if (($f['id'] ?? null) === $id) { $record = $f; break; }
    }
    if (!$record) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }

    @unlink(__DIR__ . '/' . ltrim($record['path'] ?? '', '/'));
    $db = getDb();
    $stmt = $db->prepare("DELETE FROM files WHERE id = ?");
    $stmt->execute([$id]);

    logFileActivity('deleted a file: ' . ($record['title'] ?? ''), 'danger', (string)($_POST['actorName'] ?? ''), (string)($_POST['actorRole'] ?? ''));
    notifySocketServer('file:deleted', ['id' => $id, 'taskId' => $record['taskId'] ?? null]);
    echo json_encode(['ok' => true, 'deleted' => $id]);
    exit;
}

if ($action === 'getTaskFiles') {
    $taskId = trim((string)($_GET['taskId'] ?? ($_POST['taskId'] ?? '')));
    $files = readTable('files');
    $taskFiles = array_values(array_filter($files, fn($f) => ($f['taskId'] ?? '') === $taskId));
    echo json_encode(['ok' => true, 'files' => $taskFiles]);
    exit;
}

if ($action === 'downloadFile' || $action === 'serveFile') {
    $id = trim((string)($_GET['id'] ?? ''));
    $files = readTable('files');
    $record = null;
    foreach ($files as $f) {
        if (($f['id'] ?? null) === $id) { $record = $f; break; }
    }
    if (!$record || !file_exists($path = __DIR__ . '/' . ltrim($record['path'] ?? '', '/'))) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found']);
        exit;
    }

    $disposition = ($action === 'serveFile') ? 'inline' : 'attachment';
    $ext = strtolower(pathinfo($record['originalFilename'] ?? '', PATHINFO_EXTENSION));
    $mimeType = MIME_TYPES[$ext] ?? ($record['mimeType'] ?: 'application/octet-stream');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($record['originalFilename']) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}


// ── Employee Directory Endpoint ────────────────────────────────────────

if ($action === 'getEmployeeDirectory') {
    $contacts = readTable('contacts');
    $users = readTable('users');
    $loginLogs = readTable('login_logs');
    
    // Build employee directory data with login status
    $directory = [];
    foreach ($contacts as $contact) {
        $employeeId = $contact['id'] ?? null;
        $user = null;
        foreach ($users as $u) {
            if (($u['employeeId'] ?? '') === $employeeId) {
                $user = $u;
                break;
            }
        }
        
        // Get login logs for this user
        $userLogs = [];
        if ($user) {
            foreach ($loginLogs as $log) {
                if (($log['userId'] ?? '') === $user['id']) {
                    $userLogs[] = $log;
                }
            }
        }
        
        // Sort logs by login time (newest first)
        usort($userLogs, function($a, $b) {
            return strtotime($b['loginTime'] ?? '') - strtotime($a['loginTime'] ?? '');
        });
        
        $latestLog = $userLogs[0] ?? null;
        $isOnline = $latestLog && !isset($latestLog['logoutTime']) && ($latestLog['status'] ?? '') === 'success';
        
        // Calculate duration
        $duration = null;
        if ($isOnline && isset($latestLog['loginTime'])) {
            $duration = calculateDuration($latestLog['loginTime'], date('c'));
        } elseif ($latestLog && isset($latestLog['logoutTime'])) {
            $duration = calculateDuration($latestLog['loginTime'], $latestLog['logoutTime']);
        }
        
        $directory[] = [
            'employee' => $contact,
            'user' => $user,
            'loginStatus' => [
                'isOnline' => $isOnline,
                'lastActive' => $latestLog ? ($isOnline ? $latestLog['loginTime'] : ($latestLog['logoutTime'] ?? null)) : null,
                'duration' => $duration,
                'lastLogin' => $latestLog ? $latestLog['loginTime'] : null,
                'status' => $isOnline ? 'Online' : ($latestLog ? 'Offline' : 'Never Logged In')
            ]
        ];
    }
    
    echo json_encode([
        'ok' => true, 
        'directory' => $directory,
        'count' => count($directory)
    ]);
    exit;
}


http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);