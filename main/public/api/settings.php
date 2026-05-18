<?php
/**
 * Settings API
 * System configuration management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
define('SETTINGS_FILE', __DIR__ . '/../config/settings.json');

// Helper functions
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getSettings() {
    $defaults = [
        'telegram' => [
            'bot_token' => '',
            'chat_id' => '',
            'enabled' => false
        ],
        'backup' => [
            'path' => '/opt/network-automation/backups',
            'retention_days' => 90,
            'git_repo' => ''
        ],
        'monitoring' => [
            'ping_threshold_ms' => 100,
            'packet_loss_threshold' => 1,
            'interface_flap_threshold' => 3
        ],
        'alerts' => [
            'email' => 'netfury@yourcompany.com',
            'critical_recipients' => [],
            'warning_recipients' => []
        ],
        'ansible' => [
            'user' => 'automation',
            'vault_password_file' => '/home/automation/.vault_pass'
        ]
    ];

    if (file_exists(SETTINGS_FILE)) {
        $saved = json_decode(file_get_contents(SETTINGS_FILE), true);
        return array_merge($defaults, $saved);
    }

    return $defaults;
}

function saveSettings($settings) {
    file_put_contents(SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));
}

function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function authenticate($headers) {
    // Simple auth check - in production, use proper session validation
    $authHeader = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
        $token = $matches[1];
        // Validate token from sessions
        $sessionsFile = __DIR__ . '/../config/sessions.json';
        if (file_exists($sessionsFile)) {
            $sessions = json_decode(file_get_contents($sessionsFile), true);
            if (isset($sessions[$token])) {
                return $sessions[$token]['username'];
            }
        }
    }
    return null;
}

$method = $_SERVER['REQUEST_METHOD'];

// Get request headers
$headers = getallheaders();
$user = authenticate($headers);

// If no auth, return error for non-login routes
$input = getRequestBody();
$action = $input['action'] ?? '';

if ($method !== 'POST' || $action !== 'login') {
    if (!$user && $action !== 'login') {
        // Allow login without auth
    }
}

switch ($method) {
    case 'GET':
        $settings = getSettings();
        // Mask sensitive data
        $settings['telegram']['bot_token'] = $settings['telegram']['bot_token'] ?
            substr($settings['telegram']['bot_token'], 0, 8) . '****' : '';
        $settings['telegram']['chat_id'] = $settings['telegram']['chat_id'] ?
            substr($settings['telegram']['chat_id'], 0, 4) . '****' : '';

        jsonResponse(['settings' => $settings]);

    case 'POST':
        $action = $input['action'] ?? '';

        if ($action === 'save_telegram') {
            $telegram = [
                'bot_token' => $input['bot_token'] ?? '',
                'chat_id' => $input['chat_id'] ?? '',
                'enabled' => ($input['enabled'] ?? false) === true
            ];

            $settings = getSettings();
            $settings['telegram'] = $telegram;
            saveSettings($settings);

            jsonResponse(['message' => 'Telegram settings saved']);
        }

        if ($action === 'save_backup') {
            $settings = getSettings();
            $settings['backup'] = [
                'path' => $input['path'] ?? $settings['backup']['path'],
                'retention_days' => $input['retention_days'] ?? $settings['backup']['retention_days'],
                'git_repo' => $input['git_repo'] ?? ''
            ];
            saveSettings($settings);

            jsonResponse(['message' => 'Backup settings saved']);
        }

        if ($action === 'save_alerts') {
            $settings = getSettings();
            $settings['alerts'] = [
                'email' => $input['email'] ?? $settings['alerts']['email'],
                'critical_recipients' => $input['critical_recipients'] ?? [],
                'warning_recipients' => $input['warning_recipients'] ?? []
            ];
            saveSettings($settings);

            jsonResponse(['message' => 'Alert settings saved']);
        }

        if ($action === 'test_telegram') {
            $botToken = $input['bot_token'] ?? '';
            $chatId = $input['chat_id'] ?? '';

            if (empty($botToken) || empty($chatId)) {
                jsonResponse(['error' => 'Bot token and chat ID required'], 400);
            }

            // Send test message
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => "✅ *Test Message*\n\nNetFury Network Automation System is connected!",
                'parse_mode' => 'Markdown'
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                jsonResponse(['message' => 'Test message sent successfully!']);
            } else {
                jsonResponse(['error' => 'Failed to send test message. Check your credentials.'], 400);
            }
        }

        if ($action === 'send_message') {
            $settings = getSettings();
            $botToken = $settings['telegram']['bot_token'] ?? '';
            $chatId = $settings['telegram']['chat_id'] ?? '';

            if (empty($botToken) || empty($chatId)) {
                jsonResponse(['error' => 'Telegram not configured'], 400);
            }

            $message = $input['message'] ?? '';
            if (empty($message)) {
                jsonResponse(['error' => 'Message required'], 400);
            }

            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
            $data = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                jsonResponse(['message' => 'Message sent successfully!']);
            } else {
                jsonResponse(['error' => 'Failed to send message'], 400);
            }
        }

        jsonResponse(['error' => 'Invalid action'], 400);

    case 'PUT':
        // Update all settings
        $settings = getSettings();

        if (isset($input['telegram'])) {
            $settings['telegram'] = array_merge($settings['telegram'], $input['telegram']);
        }
        if (isset($input['backup'])) {
            $settings['backup'] = array_merge($settings['backup'], $input['backup']);
        }
        if (isset($input['monitoring'])) {
            $settings['monitoring'] = array_merge($settings['monitoring'], $input['monitoring']);
        }
        if (isset($input['alerts'])) {
            $settings['alerts'] = array_merge($settings['alerts'], $input['alerts']);
        }

        saveSettings($settings);
        jsonResponse(['message' => 'Settings saved']);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}