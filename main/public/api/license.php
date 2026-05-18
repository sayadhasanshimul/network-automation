<?php
/**
 * License Authentication Server
 * Account-based license system - no license keys
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
define('USERS_FILE', __DIR__ . '/../config/users.json');
define('LICENSE_FILE', __DIR__ . '/config/license.json');
define('LICENSE_LOG_FILE', __DIR__ . '/config/license_log.json');
define('ENCRYPTION_KEY', 'NetFuryLicense2024!@#$');

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getUsers() {
    if (file_exists(USERS_FILE)) {
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    }
    return [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

function getLicenseData() {
    if (file_exists(LICENSE_FILE)) {
        return json_decode(file_get_contents(LICENSE_FILE), true) ?: [];
    }
    return [];
}

function saveLicenseData($data) {
    file_put_contents(LICENSE_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function getLicenseLog() {
    if (file_exists(LICENSE_LOG_FILE)) {
        return json_decode(file_get_contents(LICENSE_LOG_FILE), true) ?: [];
    }
    return [];
}

function saveLicenseLog($log) {
    file_put_contents(LICENSE_LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
}

function logEvent($event, $details = []) {
    $log = getLicenseLog();
    $log[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    if (count($log) > 1000) {
        $log = array_slice($log, -1000);
    }
    saveLicenseLog($log);
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function checkLicense() {
    $license = getLicenseData();

    if (empty($license['status']) || $license['status'] !== 'active') {
        return [
            'valid' => false,
            'status' => 'inactive',
            'message' => 'No active subscription'
        ];
    }

    // Check expiry
    if (!empty($license['expiry_date'])) {
        $expiry = strtotime($license['expiry_date']);
        $now = time();

        if ($now > $expiry) {
            $license['status'] = 'expired';
            $license['subscription_status'] = 'expired';
            saveLicenseData($license);
            return [
                'valid' => false,
                'status' => 'expired',
                'message' => 'Subscription expired on ' . $license['expiry_date']
            ];
        }

        $daysLeft = ceil(($expiry - $now) / 86400);
        if ($daysLeft <= 7) {
            return [
                'valid' => true,
                'status' => 'active',
                'subscription_status' => 'expiring_soon',
                'message' => "Subscription expires in {$daysLeft} days",
                'days_left' => $daysLeft,
                'license' => $license
            ];
        }
    }

    return [
        'valid' => true,
        'status' => 'active',
        'subscription_status' => 'active',
        'message' => 'Subscription is active',
        'license' => $license
    ];
}

function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

// License tiers configuration
$TIERS = [
    'trial' => [
        'type' => 'trial',
        'max_devices' => 5,
        'features' => ['backup', 'monitoring'],
        'support' => 'community'
    ],
    'standard' => [
        'type' => 'standard',
        'max_devices' => 50,
        'features' => ['backup', 'monitoring', 'basic_support'],
        'support' => 'email'
    ],
    'pro' => [
        'type' => 'pro',
        'max_devices' => 200,
        'features' => ['backup', 'monitoring', 'automation', 'telegram', 'priority_support'],
        'support' => 'priority'
    ],
    'enterprise' => [
        'type' => 'enterprise',
        'max_devices' => 9999,
        'features' => ['backup', 'monitoring', 'automation', 'telegram', 'api_access', 'priority_support', 'custom_development'],
        'support' => 'dedicated'
    ]
];

// Route handling
$method = $_SERVER['REQUEST_METHOD'];
$input = getRequestBody();
$action = $_GET['action'] ?? $input['action'] ?? '';

switch ($method) {
    case 'GET':
        switch ($action) {
            case 'status':
                $result = checkLicense();
                jsonResponse($result);

            case 'tiers':
                jsonResponse(['tiers' => $TIERS]);

            case 'check':
                jsonResponse(checkLicense());

            case 'log':
                $log = getLicenseLog();
                jsonResponse(['log' => $log]);

            default:
                jsonResponse(checkLicense());
        }
        break;

    case 'POST':
        switch ($action) {
            case 'register':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';
                $email = $input['email'] ?? '';
                $company = $input['company'] ?? '';
                $tier = $input['tier'] ?? 'trial';

                if (empty($username) || empty($password)) {
                    jsonResponse(['success' => false, 'error' => 'Username and password required'], 400);
                }

                if (!isset($TIERS[$tier])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid tier'], 400);
                }

                $users = getUsers();

                if (isset($users[$username])) {
                    jsonResponse(['success' => false, 'error' => 'Username already exists'], 409);
                }

                // Create user account
                $users[$username] = [
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => $email,
                    'company' => $company,
                    'role' => 'user',
                    'name' => $username,
                    'tier' => $tier,
                    'created' => date('Y-m-d'),
                    'subscription_status' => 'active',
                    'max_devices' => $TIERS[$tier]['max_devices'],
                    'features' => $TIERS[$tier]['features'],
                    'support' => $TIERS[$tier]['support']
                ];
                saveUsers($users);

                // Set license for first user as trial/small scale
                $license = getLicenseData();
                $license = [
                    'account' => $username,
                    'company' => $company,
                    'tier' => $tier,
                    'status' => 'active',
                    'subscription_status' => 'active',
                    'max_devices' => $TIERS[$tier]['max_devices'],
                    'features' => $TIERS[$tier]['features'],
                    'support' => $TIERS[$tier]['support'],
                    'issued_date' => date('Y-m-d'),
                    'expiry_date' => date('Y-m-d', strtotime('+365 days'))
                ];
                saveLicenseData($license);

                logEvent('account_registered', ['username' => $username, 'tier' => $tier]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Account created successfully',
                    'tier' => $tier,
                    'subscription' => $license
                ], 201);

            case 'login':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                if (empty($username) || empty($password)) {
                    jsonResponse(['success' => false, 'error' => 'Username and password required'], 400);
                }

                $users = getUsers();

                if (!isset($users[$username])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
                }

                if (!password_verify($password, $users[$username]['password'])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
                }

                // Check license status
                $license = checkLicense();

                // Generate session token
                $token = generateToken();

                logEvent('account_login', ['username' => $username]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Login successful',
                    'token' => $token,
                    'user' => [
                        'username' => $username,
                        'email' => $users[$username]['email'] ?? '',
                        'company' => $users[$username]['company'] ?? '',
                        'tier' => $users[$username]['tier'] ?? 'trial'
                    ],
                    'subscription' => $license
                ]);

            case 'change_tier':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';
                $newTier = $input['new_tier'] ?? '';

                if (empty($username) || empty($password) || empty($newTier)) {
                    jsonResponse(['success' => false, 'error' => 'All fields required'], 400);
                }

                if (!isset($TIERS[$newTier])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid tier'], 400);
                }

                $users = getUsers();

                if (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
                }

                // Update user tier
                $users[$username]['tier'] = $newTier;
                $users[$username]['max_devices'] = $TIERS[$newTier]['max_devices'];
                $users[$username]['features'] = $TIERS[$newTier]['features'];
                $users[$username]['support'] = $TIERS[$newTier]['support'];
                saveUsers($users);

                // Update license
                $license = getLicenseData();
                $license['tier'] = $newTier;
                $license['max_devices'] = $TIERS[$newTier]['max_devices'];
                $license['features'] = $TIERS[$newTier]['features'];
                $license['support'] = $TIERS[$newTier]['support'];
                $license['expiry_date'] = date('Y-m-d', strtotime('+365 days'));
                $license['subscription_status'] = 'active';
                saveLicenseData($license);

                logEvent('tier_changed', ['username' => $username, 'new_tier' => $newTier]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Subscription upgraded',
                    'tier' => $newTier,
                    'subscription' => $license
                ]);

            case 'cancel_subscription':
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                if (empty($username) || empty($password)) {
                    jsonResponse(['success' => false, 'error' => 'Credentials required'], 400);
                }

                $users = getUsers();

                if (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
                    jsonResponse(['success' => false, 'error' => 'Invalid credentials'], 401);
                }

                // Downgrade to trial
                $users[$username]['tier'] = 'trial';
                $users[$username]['max_devices'] = $TIERS['trial']['max_devices'];
                $users[$username]['features'] = $TIERS['trial']['features'];
                $users[$username]['support'] = $TIERS['trial']['support'];
                saveUsers($users);

                // Update license
                $license = getLicenseData();
                $license['tier'] = 'trial';
                $license['max_devices'] = $TIERS['trial']['max_devices'];
                $license['features'] = $TIERS['trial']['features'];
                $license['support'] = $TIERS['trial']['support'];
                $license['subscription_status'] = 'cancelled';
                saveLicenseData($license);

                logEvent('subscription_cancelled', ['username' => $username]);

                jsonResponse([
                    'success' => true,
                    'message' => 'Subscription cancelled',
                    'tier' => 'trial'
                ]);

            case 'check':
                jsonResponse(checkLicense());

            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}