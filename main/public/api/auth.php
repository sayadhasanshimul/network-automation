<?php
/**
 * Authentication API
 * Login, logout, session management with 2FA support
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
define('USERS_FILE', __DIR__ . '/../config/users.json');
define('SESSION_FILE', __DIR__ . '/../config/sessions.json');
define('TOTP_SECRET_FILE', __DIR__ . '/../config/totp_secrets.json');
define('AUDIT_LOG_FILE', __DIR__ . '/../config/audit_log.json');
define('BRUTEFORCE_FILE', __DIR__ . '/../config/bruteforce.json');
define('IP_ALLOWLIST_FILE', __DIR__ . '/../config/ip_allowlist.json');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('BRUTEFORCE_WINDOW', 300); // 5 minutes
define('BRUTEFORCE_BAN_TIME', 900); // 15 minutes

// Helper functions
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getUsers() {
    if (file_exists(USERS_FILE)) {
        $users = json_decode(file_get_contents(USERS_FILE), true) ?: [];
        if (!empty($users)) {
            return $users;
        }
    }
    // No default admin - require setup via environment
    $adminPassword = getenv('NETFURY_ADMIN_PASSWORD') ?: bin2hex(random_bytes(12));
    $adminEmail = getenv('NETFURY_ADMIN_EMAIL') ?: 'admin@netfury.local';
    return [
        'admin' => [
            'password' => password_hash($adminPassword, PASSWORD_DEFAULT),
            'role' => 'admin',
            'name' => 'Administrator',
            'email' => $adminEmail,
            'twofa_enabled' => false,
            'created' => date('Y-m-d')
        ]
    ];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

function getSessions() {
    if (file_exists(SESSION_FILE)) {
        return json_decode(file_get_contents(SESSION_FILE), true) ?: [];
    }
    return [];
}

function saveSessions($sessions) {
    file_put_contents(SESSION_FILE, json_encode($sessions));
}

function getTotpSecrets() {
    if (file_exists(TOTP_SECRET_FILE)) {
        return json_decode(file_get_contents(TOTP_SECRET_FILE), true) ?: [];
    }
    return [];
}

function saveTotpSecrets($secrets) {
    file_put_contents(TOTP_SECRET_FILE, json_encode($secrets));
}

// Audit Logging
function auditLog($event, $username, $details = []) {
    $log = [];
    if (file_exists(AUDIT_LOG_FILE)) {
        $log = json_decode(file_get_contents(AUDIT_LOG_FILE), true) ?: [];
    }
    $log[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'username' => $username,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => $details
    ];
    if (count($log) > 1000) {
        $log = array_slice($log, -1000);
    }
    file_put_contents(AUDIT_LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
}

// Brute Force Protection
function checkBruteforce($username) {
    $attempts = [];
    if (file_exists(BRUTEFORCE_FILE)) {
        $attempts = json_decode(file_get_contents(BRUTEFORCE_FILE), true) ?: [];
    }
    $now = time();
    $key = strtolower($username);
    foreach ($attempts as $k => $v) {
        if ($v['last_attempt'] < $now - BRUTEFORCE_WINDOW) {
            unset($attempts[$k]);
        }
    }
    if (!isset($attempts[$key])) {
        return ['blocked' => false, 'attempts' => 0];
    }
    if ($attempts[$key]['blocked_until'] > $now) {
        return ['blocked' => true, 'blocked_until' => $attempts[$key]['blocked_until']];
    }
    return ['blocked' => false, 'attempts' => $attempts[$key]['count']];
}

function recordFailedLogin($username) {
    $attempts = [];
    if (file_exists(BRUTEFORCE_FILE)) {
        $attempts = json_decode(file_get_contents(BRUTEFORCE_FILE), true) ?: [];
    }
    $now = time();
    $key = strtolower($username);
    if (!isset($attempts[$key]) || $attempts[$key]['last_attempt'] < $now - BRUTEFORCE_WINDOW) {
        $attempts[$key] = ['count' => 1, 'last_attempt' => $now, 'blocked_until' => 0];
    } else {
        $attempts[$key]['count']++;
        $attempts[$key]['last_attempt'] = $now;
        if ($attempts[$key]['count'] >= MAX_LOGIN_ATTEMPTS) {
            $attempts[$key]['blocked_until'] = $now + BRUTEFORCE_BAN_TIME;
        }
    }
    file_put_contents(BRUTEFORCE_FILE, json_encode($attempts));
}

function clearBruteforce($username) {
    $attempts = [];
    if (file_exists(BRUTEFORCE_FILE)) {
        $attempts = json_decode(file_get_contents(BRUTEFORCE_FILE), true) ?: [];
    }
    $key = strtolower($username);
    unset($attempts[$key]);
    file_put_contents(BRUTEFORCE_FILE, json_encode($attempts));
}

// IP Allowlisting
function isIpAllowed() {
    $allowlist = [];
    if (file_exists(IP_ALLOWLIST_FILE)) {
        $allowlist = json_decode(file_get_contents(IP_ALLOWLIST_FILE), true) ?: [];
    }
    if (empty($allowlist)) {
        return true;
    }
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($clientIp, $allowlist);
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function cleanExpiredSessions() {
    $sessions = getSessions();
    $now = time();
    foreach ($sessions as $token => $data) {
        if ($data['expires'] < $now) {
            unset($sessions[$token]);
        }
    }
    saveSessions($sessions);
}

function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

// Clean expired sessions on every request
cleanExpiredSessions();

// TOTP Functions
function base32Encode($bytes) {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    for ($i = 0; $i < strlen($bytes); $i += 5) {
        $bits = 0;
        $bitsLen = 0;
        for ($j = 0; $j < 5; $j++) {
            if ($i + $j < strlen($bytes)) {
                $bits = ($bits << 8) | ord($bytes[$i + $j]);
                $bitsLen += 8;
            }
        }
        $block = 0;
        $blockLen = 0;
        while ($bitsLen >= 5) {
            $block = ($block << 5) | (($bits >> ($bitsLen - 5)) & 31);
            $bitsLen -= 5;
            $blockLen++;
        }
        for ($k = 0; $k < $blockLen; $k++) {
            $output .= $base32Chars[($block >> (5 * ($blockLen - 1 - $k))) & 31];
        }
    }
    if ($bitsLen > 0) {
        $output .= $base32Chars[($block << (5 - $bitsLen)) & 31];
    }
    return $output;
}

function generateTotpSecret() {
    return base32Encode(random_bytes(20));
}

function verifyTotp($secret, $code, $window = 1) {
    $time = floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $expectedCode = generateHotp($secret, $time + $i);
        if (hash_equals($expectedCode, str_pad($code, 6, '0', STR_PAD_LEFT))) {
            return true;
        }
    }
    return false;
}

function generateHotp($secret, $counter) {
    $secretBytes = base32Decode($secret);
    $data = pack('J', $counter);
    $hash = hash_hmac('sha1', $data, $secretBytes, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function base32Decode($input) {
    $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(trim($input));
    $output = '';
    $bits = 0;
    $bitsLen = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $char = $input[$i];
        if ($char === '=') continue;
        $val = strpos($base32Chars, $char);
        if ($val === false) continue;
        $bits = ($bits << 5) | $val;
        $bitsLen += 5;
        if ($bitsLen >= 8) {
            $output .= chr(($bits >> ($bitsLen - 8)) & 0xff);
            $bitsLen -= 8;
        }
    }
    return $output;
}

function getTotpUri($secret, $username, $issuer = 'NetFury') {
    $encodedIssuer = rawurlencode($issuer);
    $encodedUsername = rawurlencode($username);
    return "otpauth://totp/{$encodedIssuer}:{$encodedUsername}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
}

// Route handling
$method = $_SERVER['REQUEST_METHOD'];

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = '';

if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    $token = $matches[1];
}

switch ($method) {
    case 'POST':
        $input = getRequestBody();
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'login':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';
                $totp_code = $input['totp_code'] ?? '';

                if (empty($username) || empty($password)) {
                    jsonResponse(['error' => 'Username and password required'], 400);
                }

                // Check IP allowlist
                if (!isIpAllowed()) {
                    auditLog('login_blocked_ip', $username, ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                    jsonResponse(['error' => 'Access denied from this IP address'], 403);
                }

                // Check brute force
                $bruteforce = checkBruteforce($username);
                if ($bruteforce['blocked']) {
                    $remaining = $bruteforce['blocked_until'] - time();
                    auditLog('login_bruteforce_blocked', $username, ['remaining_seconds' => $remaining]);
                    jsonResponse(['error' => 'Too many failed attempts. Try again later.', 'retry_after' => $remaining], 429);
                }

                $users = getUsers();

                if (!isset($users[$username])) {
                    recordFailedLogin($username);
                    auditLog('login_failed_user_not_found', $username);
                    jsonResponse(['error' => 'Invalid credentials'], 401);
                }

                if (!password_verify($password, $users[$username]['password'])) {
                    recordFailedLogin($username);
                    auditLog('login_failed_wrong_password', $username);
                    jsonResponse(['error' => 'Invalid credentials'], 401);
                }

                // Clear bruteforce on successful password
                clearBruteforce($username);

                // Check 2FA
                $totpSecrets = getTotpSecrets();
                $userTotpSecret = $totpSecrets[$username] ?? null;
                $twofaEnabled = $users[$username]['twofa_enabled'] ?? false;

                if ($twofaEnabled && $userTotpSecret) {
                    if (empty($totp_code)) {
                        jsonResponse([
                            'error' => '2FA required',
                            'requires_2fa' => true,
                            'message' => 'Please enter your 2FA code'
                        ], 401);
                    }

                    if (!verifyTotp($userTotpSecret, $totp_code)) {
                        auditLog('login_failed_2fa', $username);
                        jsonResponse(['error' => 'Invalid 2FA code'], 401);
                    }
                }

                // Create session
                $token = generateToken();
                $sessions = getSessions();
                $sessions[$token] = [
                    'username' => $username,
                    'role' => $users[$username]['role'],
                    'name' => $users[$username]['name'],
                    'created' => time(),
                    'expires' => time() + SESSION_TIMEOUT
                ];
                saveSessions($sessions);

                auditLog('login_success', $username);

                jsonResponse([
                    'message' => 'Login successful',
                    'token' => $token,
                    'user' => [
                        'username' => $username,
                        'role' => $users[$username]['role'],
                        'name' => $users[$username]['name']
                    ],
                    'twofa_enabled' => $twofaEnabled
                ]);

            case 'logout':
                if (empty($token)) {
                    jsonResponse(['error' => 'No token provided'], 400);
                }

                $sessions = getSessions();
                if (isset($sessions[$token])) {
                    $username = $sessions[$token]['username'];
                    unset($sessions[$token]);
                    saveSessions($sessions);
                    auditLog('logout', $username);
                }

                jsonResponse(['message' => 'Logout successful']);

            case 'register':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';
                $name = $input['name'] ?? '';
                $role = $input['role'] ?? 'user';

                if (empty($username) || empty($password)) {
                    jsonResponse(['error' => 'Username and password required'], 400);
                }

                $users = getUsers();

                if (isset($users[$username])) {
                    jsonResponse(['error' => 'User already exists'], 409);
                }

                $users[$username] = [
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'name' => $name ?: $username,
                    'twofa_enabled' => false
                ];

                file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
                auditLog('user_registered', $username, ['role' => $role]);

                jsonResponse(['message' => 'User created successfully'], 201);

            case 'setup_2fa':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                if (empty($username) || empty($password)) {
                    jsonResponse(['error' => 'Username and password required'], 400);
                }

                $users = getUsers();

                if (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
                    jsonResponse(['error' => 'Invalid credentials'], 401);
                }

                $totpSecrets = getTotpSecrets();

                // Generate new secret
                $secret = generateTotpSecret();
                $totpSecrets[$username] = $secret;
                saveTotpSecrets($totpSecrets);

                $uri = getTotpUri($secret, $username);
                $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($uri);

                jsonResponse([
                    'message' => '2FA secret generated',
                    'secret' => $secret,
                    'qrcode_url' => $qrCodeUrl,
                    'uri' => $uri
                ]);

            case 'enable_2fa':
                $username = $input['username'] ?? '';
                $code = $input['code'] ?? '';

                if (empty($username) || empty($code)) {
                    jsonResponse(['error' => 'Username and code required'], 400);
                }

                $users = getUsers();
                $totpSecrets = getTotpSecrets();

                if (!isset($totpSecrets[$username])) {
                    jsonResponse(['error' => '2FA not set up. Please setup first.'], 400);
                }

                if (!verifyTotp($totpSecrets[$username], $code)) {
                    jsonResponse(['error' => 'Invalid verification code'], 401);
                }

                // Enable 2FA for user
                $users[$username]['twofa_enabled'] = true;
                saveUsers($users);

                jsonResponse(['message' => '2FA enabled successfully']);

            case 'disable_2fa':
                $username = $input['username'] ?? '';
                $code = $input['code'] ?? '';

                if (empty($username) || empty($code)) {
                    jsonResponse(['error' => 'Username and code required'], 400);
                }

                $users = getUsers();
                $totpSecrets = getTotpSecrets();

                if (!isset($totpSecrets[$username])) {
                    jsonResponse(['error' => '2FA not configured'], 400);
                }

                if (!verifyTotp($totpSecrets[$username], $code)) {
                    jsonResponse(['error' => 'Invalid verification code'], 401);
                }

                // Disable 2FA for user
                $users[$username]['twofa_enabled'] = false;
                saveUsers($users);

                // Remove secret
                unset($totpSecrets[$username]);
                saveTotpSecrets($totpSecrets);

                jsonResponse(['message' => '2FA disabled successfully']);

            case 'verify_2fa':
                $username = $input['username'] ?? '';
                $code = $input['code'] ?? '';

                if (empty($username) || empty($code)) {
                    jsonResponse(['error' => 'Username and code required'], 400);
                }

                $totpSecrets = getTotpSecrets();

                if (!isset($totpSecrets[$username])) {
                    jsonResponse(['error' => '2FA not configured'], 400);
                }

                if (verifyTotp($totpSecrets[$username], $code)) {
                    jsonResponse(['valid' => true, 'message' => 'Code verified']);
                } else {
                    jsonResponse(['valid' => false, 'error' => 'Invalid code'], 401);
                }

            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }

    case 'GET':
        if (empty($token)) {
            jsonResponse(['error' => 'No token provided'], 400);
        }

        $sessions = getSessions();

        if (!isset($sessions[$token])) {
            jsonResponse(['error' => 'Invalid or expired token'], 401);
        }

        // Refresh session
        $sessions[$token]['expires'] = time() + SESSION_TIMEOUT;
        saveSessions($sessions);

        $users = getUsers();
        $username = $sessions[$token]['username'];
        $twofaEnabled = $users[$username]['twofa_enabled'] ?? false;

        jsonResponse([
            'authenticated' => true,
            'user' => [
                'username' => $sessions[$token]['username'],
                'role' => $sessions[$token]['role'],
                'name' => $sessions[$token]['name']
            ],
            'twofa_enabled' => $twofaEnabled
        ]);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}