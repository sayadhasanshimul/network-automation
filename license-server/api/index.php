<?php
/**
 * Multi-Product License Server
 * Centralized license management for all products
 *
 * Usage:
 *   - POST /generate    : Generate license key for a product
 *   - GET  /validate/<key>: Validate a license key
 *   - GET  /product/<id>  : Get product info
 *   - GET  /stats        : License statistics
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
define('DATA_DIR', __DIR__ . '/../data');
define('PRODUCTS_FILE', DATA_DIR . '/products.json');
define('LICENSES_FILE', DATA_DIR . '/licenses.json');
define('LICENSE_LOG_FILE', DATA_DIR . '/license_log.json');
define('ADMIN_KEY', 'admin123'); // Change in production

// Ensure data directory exists
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// Helper functions
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getProducts() {
    if (file_exists(PRODUCTS_FILE)) {
        return json_decode(file_get_contents(PRODUCTS_FILE), true) ?: [];
    }
    return [
        'netfury' => [
            'id' => 'netfury',
            'name' => 'NetFury Network Automation',
            'description' => 'Complete network automation solution',
            'tiers' => [
                ['name' => 'trial', 'max_devices' => 5, 'price' => 0, 'features' => ['backup', 'monitoring']],
                ['name' => 'standard', 'max_devices' => 50, 'price' => 99, 'features' => ['backup', 'monitoring', 'basic_support']],
                ['name' => 'pro', 'max_devices' => 200, 'price' => 299, 'features' => ['backup', 'monitoring', 'automation', 'telegram', 'priority_support']],
                ['name' => 'enterprise', 'max_devices' => 9999, 'price' => 599, 'features' => ['backup', 'monitoring', 'automation', 'telegram', 'api_access', 'priority_support', 'custom_development']]
            ]
        ]
    ];
}

function saveProducts($products) {
    file_put_contents(PRODUCTS_FILE, json_encode($products, JSON_PRETTY_PRINT));
}

function getLicenses() {
    if (file_exists(LICENSES_FILE)) {
        return json_decode(file_get_contents(LICENSES_FILE), true) ?: [];
    }
    return [];
}

function saveLicenses($licenses) {
    file_put_contents(LICENSES_FILE, json_encode($licenses, JSON_PRETTY_PRINT));
}

function getLogs() {
    if (file_exists/LICENSE_LOG_FILE)) {
        return json_decode(file_get_contents/LICENSE_LOG_FILE), true) ?: [];
    }
    return [];
}

function saveLogs($logs) {
    file_put_contents(LICENSE_LOG_FILE, json_encode($logs, JSON_PRETTY_PRINT));
}

function logEvent($event, $details = []) {
    $logs = getLogs();
    $logs[] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    if (count($logs) > 5000) {
        $logs = array_slice($logs, -5000);
    }
    saveLogs($logs);
}

function generateLicenseKey($productId, $tier) {
    $prefix = strtoupper(substr($productId, 0, 3));
    $tierPrefix = strtoupper(substr($tier, 0, 3));
    $timestamp = time();
    $random = bin2hex(8);
    return "{$prefix}-{$tierPrefix}-{$timestamp}-{$random}";
}

function verifyLicenseKey($key) {
    $licenses = getLicenses();
    if (isset($licenses[$key])) {
        $license = $licenses[$key];

        // Check expiry
        if (!empty($license['expiry_date'])) {
            $expiry = strtotime($license['expiry_date']);
            if (time() > $expiry) {
                return ['valid' => false, 'reason' => 'expired', 'license' => $license];
            }
        }

        return ['valid' => true, 'license' => $license];
    }

    // Check format
    if (preg_match('/^[A-Z]{3}-[A-Z]{3}-\d+-[a-f0-9]{16}$/', strtoupper($key))) {
        return ['valid' => true, 'pending' => true];
    }

    return ['valid' => false, 'reason' => 'invalid_key'];
}

function activateLicense($key, $customerName, $email, $company = '') {
    $licenses = getLicenses();

    if (!isset($licenses[$key])) {
        return ['success' => false, 'error' => 'License key not found'];
    }

    if ($licenses[$key]['status'] === 'active') {
        return ['success' => false, 'error' => 'License already activated'];
    }

    $licenses[$key]['status'] = 'active';
    $licenses[$key]['customer'] = $customerName;
    $licenses[$key]['email'] = $email;
    $licenses[$key]['company'] = $company;
    $licenses[$key]['activated_at'] = date('Y-m-d H:i:s');
    $licenses[$key]['expiry_date'] = date('Y-m-d', strtotime('+365 days'));
    saveLicenses($licenses);

    logEvent('license_activated', [
        'key' => substr($key, 0, 15) . '...',
        'customer' => $customerName,
        'product' => $licenses[$key]['product_id']
    ]);

    return ['success' => true, 'license' => $licenses[$key]];
}

function deactivateLicense($key) {
    $licenses = getLicenses();

    if (!isset($licenses[$key])) {
        return ['success' => false, 'error' => 'License key not found'];
    }

    $licenses[$key]['status'] = 'inactive';
    $licenses[$key]['deactivated_at'] = date('Y-m-d H:i:s');
    saveLicenses($licenses);

    logEvent('license_deactivated', ['key' => substr($key, 0, 15) . '...']);

    return ['success' => true];
}

function revokeLicense($key, $reason = '') {
    $licenses = getLicenses();

    if (!isset($licenses[$key])) {
        return ['success' => false, 'error' => 'License key not found'];
    }

    $licenses[$key]['status'] = 'revoked';
    $licenses[$key]['revoked_at'] = date('Y-m-d H:i:s');
    $licenses[$key]['revoke_reason'] = $reason;
    saveLicenses($licenses);

    logEvent('license_revoked', ['key' => substr($key, 0, 15) . '...', 'reason' => $reason]);

    return ['success' => true];
}

// API Routes
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$query = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

// Route handling
switch ($method) {
    case 'GET':
        // Health check
        if ($path === '/health') {
            jsonResponse(['status' => 'ok', 'service' => 'license-server', 'version' => '1.0']);
        }

        // Validate license
        if (preg_match('/^\/validate\/(.+)$/', $path, $matches)) {
            $result = verifyLicenseKey($matches[1]);
            jsonResponse($result);
        }

        // Get license info
        if (preg_match('/^\/license\/(.+)$/', $path, $matches)) {
            $result = verifyLicenseKey($matches[1]);
            if ($result['valid']) {
                unset($result['license']['customer_email']); // Hide sensitive data
            }
            jsonResponse($result);
        }

        // Get product info
        if (preg_match('/^\/product\/(.+)$/', $path, $matches)) {
            $products = getProducts();
            if (isset($products[$matches[1]])) {
                // Don't expose internal pricing
                $product = $products[$matches[1]];
                unset($product['tiers'][0]['price']);
                jsonResponse(['product' => $product]);
            } else {
                jsonResponse(['error' => 'Product not found'], 404);
            }
        }

        // List all products
        if ($path === '/products') {
            $products = getProducts();
            $list = [];
            foreach ($products as $id => $p) {
                $list[$id] = [
                    'name' => $p['name'],
                    'description' => $p['description']
                ];
            }
            jsonResponse(['products' => $list]);
        }

        // Statistics
        if ($path === '/stats') {
            $licenses = getLicenses();
            $products = getProducts();

            $stats = [
                'total_licenses' => count($licenses),
                'active' => 0,
                'inactive' => 0,
                'expired' => 0,
                'revoked' => 0,
                'by_product' => [],
                'by_tier' => []
            ];

            foreach ($licenses as $license) {
                $status = $license['status'] ?? 'inactive';
                $stats[$status] = ($stats[$status] ?? 0) + 1;

                $productId = $license['product_id'] ?? 'unknown';
                if (!isset($stats['by_product'][$productId])) {
                    $stats['by_product'][$productId] = 0;
                }
                $stats['by_product'][$productId]++;

                $tier = $license['tier'] ?? 'unknown';
                if (!isset($stats['by_tier'][$tier])) {
                    $stats['by_tier'][$tier] = 0;
                }
                $stats['by_tier'][$tier]++;
            }

            jsonResponse($stats);
        }

        // Get logs
        if ($path === '/logs') {
            $logs = getLogs();
            jsonResponse(['logs' => array_slice($logs, -100)]);
        }

        // List licenses (admin only)
        if ($path === '/licenses') {
            $key = $query['key'] ?? '';
            if ($key !== ADMIN_KEY) {
                jsonResponse(['error' => 'Invalid admin key'], 401);
            }
            $licenses = getLicenses();
            jsonResponse(['licenses' => $licenses]);
        }

        // List products (admin view)
        if ($path === '/admin/products') {
            $key = $query['key'] ?? '';
            if ($key !== ADMIN_KEY) {
                jsonResponse(['error' => 'Invalid admin key'], 401);
            }
            jsonResponse(['products' => getProducts()]);
        }

        jsonResponse(['error' => 'Endpoint not found'], 404);

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        // Generate license
        if ($path === '/generate') {
            $adminKey = $input['admin_key'] ?? '';
            if ($adminKey !== ADMIN_KEY) {
                jsonResponse(['success' => false, 'error' => 'Invalid admin key'], 401);
            }

            $productId = $input['product_id'] ?? '';
            $tier = $input['tier'] ?? 'trial';
            $customer = $input['customer'] ?? '';
            $email = $input['email'] ?? '';
            $company = $input['company'] ?? '';

            $products = getProducts();
            if (!isset($products[$productId])) {
                jsonResponse(['success' => false, 'error' => 'Product not found'], 404);
            }

            // Validate tier
            $tierConfig = null;
            foreach ($products[$productId]['tiers'] as $t) {
                if ($t['name'] === $tier) {
                    $tierConfig = $t;
                    break;
                }
            }

            if (!$tierConfig) {
                jsonResponse(['success' => false, 'error' => 'Invalid tier'], 400);
            }

            // Generate key
            $key = generateLicenseKey($productId, $tier);

            // Store license
            $licenses = getLicenses();
            $licenses[$key] = [
                'product_id' => $productId,
                'tier' => $tier,
                'max_devices' => $tierConfig['max_devices'],
                'features' => $tierConfig['features'],
                'status' => 'pending',
                'customer' => $customer,
                'email' => $email,
                'company' => $company,
                'created_at' => date('Y-m-d H:i:s'),
                'expiry_date' => '',
                'activated_at' => null
            ];
            saveLicenses($licenses);

            logEvent('license_generated', [
                'key' => substr($key, 0, 15) . '...',
                'product' => $productId,
                'tier' => $tier,
                'customer' => $customer
            ]);

            jsonResponse([
                'success' => true,
                'license_key' => $key,
                'product' => $productId,
                'tier' => $tier,
                'max_devices' => $tierConfig['max_devices']
            ]);
        }

        // Activate license
        if ($path === '/activate') {
            $key = $input['license_key'] ?? '';
            $customer = $input['customer_name'] ?? '';
            $email = $input['email'] ?? '';
            $company = $input['company'] ?? '';

            if (empty($key) || empty($customer)) {
                jsonResponse(['success' => false, 'error' => 'License key and customer name required'], 400);
            }

            $result = activateLicense($key, $customer, $email, $company);
            if ($result['success']) {
                jsonResponse($result);
            } else {
                jsonResponse($result, 400);
            }
        }

        // Deactivate license
        if ($path === '/deactivate') {
            $key = $input['license_key'] ?? '';

            if (empty($key)) {
                jsonResponse(['success' => false, 'error' => 'License key required'], 400);
            }

            $result = deactivateLicense($key);
            jsonResponse($result);
        }

        // Revoke license
        if ($path === '/revoke') {
            $adminKey = $input['admin_key'] ?? '';
            if ($adminKey !== ADMIN_KEY) {
                jsonResponse(['success' => false, 'error' => 'Invalid admin key'], 401);
            }

            $key = $input['license_key'] ?? '';
            $reason = $input['reason'] ?? '';

            if (empty($key)) {
                jsonResponse(['success' => false, 'error' => 'License key required'], 400);
            }

            $result = revokeLicense($key, $reason);
            jsonResponse($result);
        }

        // Add product
        if ($path === '/product') {
            $adminKey = $input['admin_key'] ?? '';
            if ($adminKey !== ADMIN_KEY) {
                jsonResponse(['success' => false, 'error' => 'Invalid admin key'], 401);
            }

            $productId = $input['id'] ?? '';
            $name = $input['name'] ?? '';
            $description = $input['description'] ?? '';
            $tiers = $input['tiers'] ?? [];

            if (empty($productId) || empty($name)) {
                jsonResponse(['success' => false, 'error' => 'Product ID and name required'], 400);
            }

            $products = getProducts();
            $products[$productId] = [
                'id' => $productId,
                'name' => $name,
                'description' => $description,
                'tiers' => $tiers
            ];
            saveProducts($products);

            logEvent('product_added', ['product_id' => $productId, 'name' => $name]);

            jsonResponse(['success' => true, 'product' => $products[$productId]]);
        }

        // Validate license (same as GET /validate)
        if ($path === '/validate') {
            $key = $input['license_key'] ?? '';
            $result = verifyLicenseKey($key);
            jsonResponse($result);
        }

        jsonResponse(['error' => 'Endpoint not found'], 404);

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}