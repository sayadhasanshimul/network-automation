<?php
/**
 * Device Management API
 * Add/Remove/Edit network devices via web interface
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
define('INVENTORY_FILE', __DIR__ . '/../inventory/devices.json');
define('BACKUP_DIR', '/opt/network-automation/backups');

// Helper function for JSON response
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Get current devices
function getDevices() {
    if (file_exists(INVENTORY_FILE)) {
        $content = file_get_contents(INVENTORY_FILE);
        return json_decode($content, true) ?: [];
    }
    return [];
}

// Save devices
function saveDevices($devices) {
    file_put_contents(INVENTORY_FILE, json_encode($devices, JSON_PRETTY_PRINT));
}

// Generate ID
function generateId() {
    return 'dev_' . substr(md5(uniqid()), 0, 8);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'];

// Route handling
switch ($method) {
    case 'GET':
        jsonResponse(['devices' => getDevices()]);

    case 'POST':
        // Add new device
        $hostname = $input['hostname'] ?? '';
        $ip = $input['ip'] ?? '';
        $model = $input['model'] ?? '';
        $type = $input['type'] ?? '';
        $site = $input['site'] ?? '';
        $role = $input['role'] ?? '';
        $os_version = $input['os_version'] ?? '';

        // Validation
        if (empty($hostname) || empty($ip)) {
            jsonResponse(['error' => 'Hostname and IP are required'], 400);
        }

        // Check for duplicate
        $devices = getDevices();
        foreach ($devices as $d) {
            if ($d['hostname'] === $hostname) {
                jsonResponse(['error' => 'Device with this hostname already exists'], 409);
            }
        }

        // Create new device
        $newDevice = [
            'id' => generateId(),
            'hostname' => $hostname,
            'ip' => $ip,
            'model' => $model,
            'type' => $type,
            'site' => $site,
            'role' => $role,
            'os_version' => $os_version,
            'status' => 'unknown',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $devices[] = $newDevice;
        saveDevices($devices);

        jsonResponse(['message' => 'Device added', 'device' => $newDevice], 201);

    case 'DELETE':
        // Delete device
        $id = $input['id'] ?? '';
        if (empty($id)) {
            jsonResponse(['error' => 'Device ID is required'], 400);
        }

        $devices = getDevices();
        $found = false;
        foreach ($devices as $key => $d) {
            if ($d['id'] === $id) {
                unset($devices[$key]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(['error' => 'Device not found'], 404);
        }

        saveDevices(array_values($devices));
        jsonResponse(['message' => 'Device deleted']);

    case 'PUT':
        // Update device
        $id = $input['id'] ?? '';
        $devices = getDevices();
        $found = false;

        foreach ($devices as $key => $d) {
            if ($d['id'] === $id) {
                $devices[$key] = array_merge($d, [
                    'hostname' => $input['hostname'] ?? $d['hostname'],
                    'ip' => $input['ip'] ?? $d['ip'],
                    'model' => $input['model'] ?? $d['model'],
                    'type' => $input['type'] ?? $d['type'],
                    'site' => $input['site'] ?? $d['site'],
                    'role' => $input['role'] ?? $d['role'],
                    'os_version' => $input['os_version'] ?? $d['os_version'],
                    'status' => $input['status'] ?? $d['status'],
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $found = true;
                break;
            }
        }

        if (!$found) {
            jsonResponse(['error' => 'Device not found'], 404);
        }

        saveDevices($devices);
        jsonResponse(['message' => 'Device updated', 'device' => $devices[array_search($id, array_column($devices, 'id'))]]);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}