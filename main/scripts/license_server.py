#!/usr/bin/env python3
"""
License Key Generator Server
Standalone server for generating and managing license keys
Run: python3 scripts/license_server.py
"""

from flask import Flask, request, jsonify
import hashlib
import hmac
import secrets
import string
from datetime import datetime, timedelta
import json
import os

app = Flask(__name__)

LICENSE_KEYS_FILE = 'config/license_keys.json'
LICENSE_LOG_FILE = 'config/license_log.json'
ENCRYPTION_KEY = os.environ.get('LICENSE_ENCRYPTION_KEY', secrets.token_hex(32))

def load_keys():
    if os.path.exists(LICENSE_KEYS_FILE):
        with open(LICENSE_KEYS_FILE, 'r') as f:
            return json.load(f)
    return {}

def save_keys(keys):
    with open(LICENSE_KEYS_FILE, 'w') as f:
        json.dump(keys, f, indent=4)

def log_event(event, details):
    logs = []
    if os.path.exists(LICENSE_LOG_FILE):
        with open(LICENSE_LOG_FILE, 'r') as f:
            try:
                logs = json.load(f)
            except:
                logs = []

    logs.append({
        'timestamp': datetime.now().isoformat(),
        'event': event,
        'details': details,
        'ip': request.remote_addr
    })

    if len(logs) > 1000:
        logs = logs[-1000:]

    with open(LICENSE_LOG_FILE, 'w') as f:
        json.dump(logs, f, indent=4)

def generate_key_id():
    """Generate unique key ID"""
    return secrets.token_hex(16).upper()

def format_license_key(key_id, key_type):
    """Format license key in XXXX-XXXX-XXXX-XXXX format"""
    chunks = [key_id[i:i+4] for i in range(0, len(key_id), 4)]
    prefix = key_type[:3].upper()
    return f"{prefix}-{' '.join(chunks[:4])}"

def generate_signature(data):
    """Generate HMAC signature for license data"""
    payload = json.dumps(data, sort_keys=True)
    return hmac.new(
        ENCRYPTION_KEY.encode(),
        payload.encode(),
        hashlib.sha256
    ).hexdigest()

@app.route('/api/generate', methods=['POST'])
def generate_license():
    """Generate a new license key"""
    data = request.get_json()

    # Admin authentication
    admin_key = data.get('admin_key', '')
    expected_admin_key = os.environ.get('LICENSE_ADMIN_KEY', os.urandom(16).hex())
    if not hmac.compare_digest(admin_key, expected_admin_key):
        return jsonify({'error': 'Invalid admin key'}), 401

    # License parameters
    license_type = data.get('type', 'standard')  # standard, pro, enterprise
    expiry_days = 365
    max_devices = data.get('max_devices', 50)
    customer = data.get('customer', '')
    features = data.get('features', [])

    # Generate key
    key_id = generate_key_id()
    formatted_key = format_license_key(key_id, license_type)

    # Store key data
    keys = load_keys()
    keys[formatted_key] = {
        'type': license_type,
        'expiry_days': expiry_days,
        'max_devices': max_devices,
        'features': features,
        'used': False,
        'created': datetime.now().isoformat(),
        'created_by': customer
    }
    save_keys(keys)

    log_event('license_generated', {
        'key': formatted_key[:8] + '...',
        'type': license_type,
        'customer': customer
    })

    return jsonify({
        'success': True,
        'license_key': formatted_key,
        'type': license_type,
        'expiry_days': expiry_days,
        'max_devices': max_devices
    })

@app.route('/api/validate/<key>', methods=['GET'])
def validate_key(key):
    """Validate a license key format"""
    keys = load_keys()

    if key in keys:
        key_data = keys[key]
        return jsonify({
            'valid': True,
            'used': key_data['used'],
            'type': key_data['type']
        })

    # Check format
    parts = key.split('-')
    if len(parts) == 4 and all(len(p) == 4 for p in parts):
        return jsonify({'valid': True, 'used': False})

    return jsonify({'valid': False})

@app.route('/api/revoke', methods=['POST'])
def revoke_license():
    """Revoke an existing license key"""
    data = request.get_json()
    admin_key = data.get('admin_key', '')
    expected_admin_key = os.environ.get('LICENSE_ADMIN_KEY', os.urandom(16).hex())

    if not hmac.compare_digest(admin_key, expected_admin_key):
        return jsonify({'error': 'Invalid admin key'}), 401

    key = data.get('license_key', '')
    keys = load_keys()

    if key in keys:
        keys[key]['revoked'] = True
        keys[key]['revoked_date'] = datetime.now().isoformat()
        save_keys(keys)

        log_event('license_revoked', {'key': key[:8] + '...'})

        return jsonify({'success': True})

    return jsonify({'error': 'Key not found'}), 404

@app.route('/api/list', methods=['GET'])
def list_keys():
    """List all license keys (admin only)"""
    admin_key = request.args.get('admin_key', '')
    expected_admin_key = os.environ.get('LICENSE_ADMIN_KEY', os.urandom(16).hex())

    if not hmac.compare_digest(admin_key, expected_admin_key):
        return jsonify({'error': 'Invalid admin key'}), 401

    keys = load_keys()
    return jsonify({'keys': keys})

@app.route('/api/stats', methods=['GET'])
def get_stats():
    """Get license statistics"""
    keys = load_keys()

    total = len(keys)
    used = sum(1 for k in keys.values() if k.get('used', False))
    revoked = sum(1 for k in keys.values() if k.get('revoked', False))

    by_type = {}
    for k, v in keys.items():
        t = v.get('type', 'unknown')
        by_type[t] = by_type.get(t, 0) + 1

    return jsonify({
        'total_keys': total,
        'used': used,
        'available': total - used - revoked,
        'revoked': revoked,
        'by_type': by_type
    })

@app.route('/api/logs', methods=['GET'])
def get_logs():
    """Get license event logs"""
    admin_key = request.args.get('admin_key', '')
    expected_admin_key = os.environ.get('LICENSE_ADMIN_KEY', os.urandom(16).hex())

    if not hmac.compare_digest(admin_key, expected_admin_key):
        return jsonify({'error': 'Invalid admin key'}), 401

    if os.path.exists(LICENSE_LOG_FILE):
        with open(LICENSE_LOG_FILE, 'r') as f:
            logs = json.load(f)
        return jsonify({'logs': logs[-100:]})  # Last 100 entries

    return jsonify({'logs': []})

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint"""
    return jsonify({'status': 'ok', 'service': 'license-server'})

if __name__ == '__main__':
    admin_key_hint = os.environ.get('LICENSE_ADMIN_KEY', '[not set - generate one]')
    print("=" * 60)
    print("  NetFury License Server")
    print("  ========================")
    print()
    print("  Server running on http://localhost:5000")
    print()
    print("  Endpoints:")
    print("  - POST /api/generate     : Generate license key")
    print("  - GET  /api/validate/<key>: Validate key format")
    print("  - POST /api/revoke       : Revoke a key")
    print("  - GET  /api/list         : List all keys")
    print("  - GET  /api/stats        : License statistics")
    print("  - GET  /api/logs         : Event logs")
    print()
    print(f"  Admin key: {admin_key_hint}")
    print("=" * 60)
    app.run(host='0.0.0.0', port=5000, debug=True)