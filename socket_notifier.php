<?php
// ══════════════════════════════════════════════
//  SOCKET NOTIFIER — socket_notifier.php
//  Shared include used by api.php, auth.php, and anywhere else that
//  needs to push a real‑time event to the Node.js Socket.IO server.
//
//  UPDATED: Filtered to ONLY broadcast login and logout events.
//  All other events (deal:*, note:*, contact:*, etc.) are silently
//  ignored to reduce server load and log noise.
// ══════════════════════════════════════════════

// ── Configuration ─────────────────────────────────────────────────────

// Must match SOCKET_SHARED_SECRET in server.js
// 🔐 CHANGE THIS to a strong random string in production
if (!defined('SOCKET_SHARED_SECRET')) {
    define('SOCKET_SHARED_SECRET', 'asdqwe');
}

// PHP talks to Node directly on localhost (bypasses Nginx)
if (!defined('SOCKET_SERVER_URL')) {
    $override = getenv('SOCKET_SERVER_URL_OVERRIDE');
    if ($override) {
        define('SOCKET_SERVER_URL', $override);
    } else {
        define('SOCKET_SERVER_URL', '<http://127.0.0.1:3000/update>');
    }
}

// Timeout settings (in milliseconds)
if (!defined('SOCKET_TIMEOUT_MS')) {
    define('SOCKET_TIMEOUT_MS', 3000);
}

// Enable debug logging (set to false in production)
if (!defined('SOCKET_DEBUG')) {
    define('SOCKET_DEBUG', false);
}

// Maximum number of retry attempts
if (!defined('SOCKET_MAX_RETRIES')) {
    define('SOCKET_MAX_RETRIES', 2);
}

// ── Helper: Get current user ID from session ────────────────────────

function socketGetCurrentUserId() {
    // Method 1: Check if currentUser is set in the global scope
    if (isset($GLOBALS['currentUser']) && is_array($GLOBALS['currentUser'])) {
        if (isset($GLOBALS['currentUser']['id'])) {
            return $GLOBALS['currentUser']['id'];
        }
        if (isset($GLOBALS['currentUser']['userId'])) {
            return $GLOBALS['currentUser']['userId'];
        }
    }

    // Method 2: Check if we have a session
    if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        if (isset($_SESSION['userId'])) {
            return $_SESSION['userId'];
        }
        if (isset($_SESSION['user']['id'])) {
            return $_SESSION['user']['id'];
        }
    }

    // Method 3: Check if we have a contact ID (employee ID)
    if (isset($GLOBALS['currentUser']['contactId'])) {
        return $GLOBALS['currentUser']['contactId'];
    }
    if (isset($_SESSION['contact_id'])) {
        return $_SESSION['contact_id'];
    }

    // Method 4: Check for user in POST/GET (for API calls)
    if (isset($_POST['userId'])) {
        return $_POST['userId'];
    }
    if (isset($_GET['userId'])) {
        return $_GET['userId'];
    }

    // Method 5: Check for user in JSON payload
    $input = file_get_contents('php://input');
    if (!empty($input)) {
        $data = json_decode($input, true);
        if ($data && isset($data['userId'])) {
            return $data['userId'];
        }
        if ($data && isset($data['user']['id'])) {
            return $data['user']['id'];
        }
    }

    // Method 6: Check for X-User-Id header (for API clients)
    if (isset($_SERVER['HTTP_X_USER_ID'])) {
        return $_SERVER['HTTP_X_USER_ID'];
    }

    // Method 7: Fallback to getenv
    if (getenv('USER_ID')) {
        return getenv('USER_ID');
    }

    return null;
}

function socketGetCurrentContactId() {
    if (isset($GLOBALS['currentUser']['contactId'])) {
        return $GLOBALS['currentUser']['contactId'];
    }
    if (isset($GLOBALS['currentUser']['employeeId'])) {
        return $GLOBALS['currentUser']['employeeId'];
    }
    if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['contact_id'])) {
            return $_SESSION['contact_id'];
        }
        if (isset($_SESSION['employee_id'])) {
            return $_SESSION['employee_id'];
        }
    }
    return null;
}

function socketGetCurrentUsername() {
    if (isset($GLOBALS['currentUser']['username'])) {
        return $GLOBALS['currentUser']['username'];
    }
    if (isset($GLOBALS['currentUser']['name'])) {
        return $GLOBALS['currentUser']['name'];
    }
    if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (isset($_SESSION['username'])) {
            return $_SESSION['username'];
        }
    }
    return null;
}

function socketGetCurrentEmployeeName() {
    $contactId = socketGetCurrentContactId();
    if ($contactId) {
        if (isset($GLOBALS['contacts']) && is_array($GLOBALS['contacts'])) {
            foreach ($GLOBALS['contacts'] as $contact) {
                if (isset($contact['id']) && $contact['id'] === $contactId) {
                    $fname = $contact['fname'] ?? '';
                    $lname = $contact['lname'] ?? '';
                    if ($fname || $lname) {
                        return trim($fname . ' ' . $lname);
                    }
                }
            }
        }
    }
    return socketGetCurrentUsername();
}

// ── Helper: Build originator data ────────────────────────────────────

function socketBuildOriginatorData() {
    $originator = [];

    $userId = socketGetCurrentUserId();
    if ($userId) {
        $originator['originatorId'] = $userId;
    }

    $contactId = socketGetCurrentContactId();
    if ($contactId) {
        $originator['originatorContactId'] = $contactId;
    }

    $username = socketGetCurrentUsername();
    if ($username) {
        $originator['originatorUsername'] = $username;
    }

    $employeeName = socketGetCurrentEmployeeName();
    if ($employeeName) {
        $originator['originatorEmployeeName'] = $employeeName;
    }

    $originator['originatorTimestamp'] = microtime(true);
    $originator['originatorIp'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    return $originator;
}

// ── Helper: Sanitize data for JSON ──────────────────────────────────

function socketSanitizeData($data) {
    if (is_resource($data)) {
        return null;
    }
    if (is_object($data)) {
        if (method_exists($data, 'toArray')) {
            $data = $data->toArray();
        } else {
            $data = (array) $data;
        }
    }
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = socketSanitizeData($value);
        }
    }
    if (is_string($data) && mb_detect_encoding($data, 'UTF-8', true) === false) {
        $data = mb_convert_encoding($data, 'UTF-8', 'auto');
    }
    return $data;
}

// ── Helper: Check if socket server is reachable ─────────────────────

function socketServerReachable(int $timeoutMs = 1000): bool
{
    $ch = curl_init(SOCKET_SERVER_URL);
    if ($ch === false) {
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200 || $httpCode === 405 || $httpCode === 404;
}

// ══════════════════════════════════════════════
//  🔥 CORE: FILTER EVENTS – ONLY LOGIN/LOGOUT
// ══════════════════════════════════════════════

// This function is called from everywhere. We now check the event name
// and only forward it to Node if it is a login or logout event.
function notifySocketServer(string $event, array $data = [], $originatorUserId = null, int $timeoutMs = null, bool $async = false)
{
    // ── 🔥 FILTER: Only allow login/logout events ──
    $allowedEvents = ['login:success', 'login:failed', 'logout'];
    if (!in_array($event, $allowedEvents)) {
        // Silently ignore all other events (no log, no network call)
        return false;
    }

    // ── (Rest of the function unchanged, except we added the filter) ──

    // Validate event name (redundant but safe)
    if (empty($event)) {
        if (defined('SOCKET_DEBUG') && SOCKET_DEBUG) {
            error_log("socket_notifier: event name cannot be empty");
        }
        return false;
    }

    // Sanitize and prepare data
    $sanitizedData = socketSanitizeData($data);

    // Build the full payload
    $payload = [
        'event' => $event,
        'data' => $sanitizedData,
    ];

    // Add originator information
    if ($originatorUserId !== null) {
        $payload['originatorId'] = $originatorUserId;
    } else {
        $originator = socketBuildOriginatorData();
        if (!empty($originator)) {
            $payload = array_merge($payload, $originator);
        }
    }

    $payload['serverTimestamp'] = date('Y-m-d H:i:s');
    $payload['serverMicrotime'] = microtime(true);
    $payload['requestId'] = uniqid('sock_', true);

    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) {
        error_log("socket_notifier: failed to json_encode payload for event '{$event}': " . json_last_error_msg());
        return false;
    }

    if (defined('SOCKET_DEBUG') && SOCKET_DEBUG) {
        error_log("socket_notifier: sending event '{$event}' with payload: " . substr($jsonPayload, 0, 500) . '...');
    }

    if ($async && function_exists('curl_multi_init')) {
        return socketNotifyAsync($jsonPayload, $event);
    }

    return socketNotifySync($jsonPayload, $event, $timeoutMs);
}

// ── Synchronous notification (unchanged) ────────────────────────────

function socketNotifySync(string $jsonPayload, string $event, ?int $timeoutMs = null, int $retries = 0)
{
    $timeoutMs = $timeoutMs ?? SOCKET_TIMEOUT_MS;

    if ($retries > SOCKET_MAX_RETRIES) {
        error_log("socket_notifier: max retries exceeded for event '{$event}'");
        return false;
    }

    $ch = curl_init(SOCKET_SERVER_URL);
    if ($ch === false) {
        error_log("socket_notifier: failed to initialize curl for event '{$event}'");
        return false;
    }

    $options = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
            'X-Socket-Secret: ' . SOCKET_SHARED_SECRET,
            'X-Request-Id: ' . uniqid('sock_', true),
            'X-Source: php-notifier',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        CURLOPT_TIMEOUT_MS     => $timeoutMs,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'TMS-Socket-Notifier/1.0',
    ];

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errMsg = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);

    if ($errNo !== 0) {
        error_log("socket_notifier: curl error for event '{$event}': ({$errNo}) {$errMsg}");
        if ($errNo === CURLE_OPERATION_TIMEOUTED || $errNo === CURLE_COULDNT_CONNECT || $errNo === CURLE_COULDNT_RESOLVE_HOST) {
            if (defined('SOCKET_DEBUG') && SOCKET_DEBUG) {
                error_log("socket_notifier: retrying event '{$event}' (attempt " . ($retries + 1) . ")");
            }
            usleep(200000);
            return socketNotifySync($jsonPayload, $event, $timeoutMs, $retries + 1);
        }
        return false;
    }

    if ($httpCode !== 200) {
        error_log("socket_notifier: non-200 response ({$httpCode}) for event '{$event}': " . substr($response, 0, 200));
        return false;
    }

    $responseData = json_decode($response, true);
    if ($responseData && isset($responseData['success']) && $responseData['success'] === false) {
        error_log("socket_notifier: server returned error for event '{$event}': " . ($responseData['error'] ?? 'unknown error'));
        return false;
    }

    if (defined('SOCKET_DEBUG') && SOCKET_DEBUG) {
        error_log("socket_notifier: event '{$event}' delivered in {$totalTime}s");
    }

    return true;
}

// ── Asynchronous notification (unchanged) ───────────────────────────

function socketNotifyAsync(string $jsonPayload, string $event)
{
    $ch = curl_init(SOCKET_SERVER_URL);
    if ($ch === false) {
        error_log("socket_notifier: failed to initialize curl for async request '{$event}'");
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload),
            'X-Socket-Secret: ' . SOCKET_SHARED_SECRET,
            'X-Request-Id: ' . uniqid('sock_async_', true),
            'X-Source: php-notifier-async',
        ],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT_MS => 500,
        CURLOPT_TIMEOUT_MS     => 500,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_USERAGENT      => 'TMS-Socket-Notifier/1.0',
    ]);

    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);

    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running > 0);

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
    curl_multi_close($mh);

    return true;
}

// ── Convenience functions (still defined, but they will be filtered) ──

// ... (all the notify functions remain, but notifySocketServer will filter them)