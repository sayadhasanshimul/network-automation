#!/usr/bin/env python3
"""
Multi-Product License Server
Flask-based license server for all products

Run: python3 server.py
Endpoints:
  GET  /health                    - Health check
  POST /generate                   - Generate license key
  GET  /validate/<key>            - Validate license
  POST /activate                   - Activate license
  POST /deactivate                 - Deactivate license
  GET  /products                  - List products
  GET  /product/<id>              - Product info
  GET  /stats                     - License statistics
"""

from flask import Flask, request, jsonify
import json
import os
import hashlib
import hmac
import secrets
from datetime import datetime, timedelta
from functools import wraps

app = Flask(__name__)

DATA_DIR = 'data'
ADMIN_KEY = 'admin123'

# Ensure data directory exists
os.makedirs(DATA_DIR, exist_ok=True)

def load_json(filename):
    path = os.path.join(DATA_DIR, filename)
    if os.path.exists(path):
        with open(path, 'r') as f:
            return json.load(f)
    return {}

def save_json(filename, data):
    path = os.path.join(DATA_DIR, filename)
    with open(path, 'w') as f:
        json.dump(data, f, indent=4)

def get_products():
    products = load_json('products.json')
    if not products:
        # Default products
        products = {
            'netfury': {
                'id': 'netfury',
                'name': 'NetFury Network Automation',
                'description': 'Complete network automation solution',
                'tiers': [
                    {'name': 'trial', 'max_devices': 5, 'price': 0, 'features': ['backup', 'monitoring']},
                    {'name': 'standard', 'max_devices': 50, 'price': 99, 'features': ['backup', 'monitoring', 'basic_support']},
                    {'name': 'pro', 'max_devices': 200, 'price': 299, 'features': ['backup', 'monitoring', 'automation', 'telegram', 'priority_support']},
                    {'name': 'enterprise', 'max_devices': 9999, 'price': 599, 'features': ['backup', 'monitoring', 'automation', 'telegram', 'api_access', 'priority_support', 'custom_development']}
                ]
            }
        }
        save_json('products.json', products)
    return products

def get_licenses():
    return load_json('licenses.json')

def save_licenses(licenses):
    save_json('licenses.json', licenses)

def get_logs():
    logs = load_json('license_log.json')
    return logs if isinstance(logs, list) else []

def save_logs(logs):
    save_json('license_log.json', logs)

def log_event(event, details):
    logs = get_logs()
    logs.append({
        'timestamp': datetime.now().isoformat(),
        'event': event,
        'details': details,
        'ip': request.remote_addr or 'unknown'
    })
    if len(logs) > 5000:
        logs = logs[-5000:]
    save_logs(logs)

def generate_key(product_id, tier):
    prefix = product_id[:3].upper()
    tier_prefix = tier[:3].upper()
    timestamp = int(datetime.now().timestamp())
    random = secrets.token_hex(8)
    return f"{prefix}-{tier_prefix}-{timestamp}-{random}"

def verify_key(key):
    licenses = get_licenses()

    if key in licenses:
        license = licenses[key]
        # Check expiry
        if license.get('expiry_date'):
            expiry = datetime.fromisoformat(license['expiry_date'])
            if datetime.now() > expiry:
                return {'valid': False, 'reason': 'expired', 'license': license}
        return {'valid': True, 'license': license}

    # Check format
    if len(key) == 34 and key.count('-') == 3:
        return {'valid': True, 'pending': True}

    return {'valid': False, 'reason': 'invalid_key'}

# Routes
@app.route('/health', methods=['GET'])
def health():
    return jsonify({'status': 'ok', 'service': 'license-server', 'version': '1.0'})

@app.route('/validate/<key>', methods=['GET'])
def validate(key):
    return jsonify(verify_key(key))

@app.route('/validate', methods=['POST'])
def validate_post():
    data = request.get_json()
    key = data.get('license_key', '')
    return jsonify(verify_key(key))

@app.route('/activate', methods=['POST'])
def activate():
    data = request.get_json()
    key = data.get('license_key', '')
    customer = data.get('customer_name', '')
    email = data.get('email', '')
    company = data.get('company', '')

    if not key or not customer:
        return jsonify({'success': False, 'error': 'License key and customer name required'}), 400

    licenses = get_licenses()

    if key not in licenses:
        return jsonify({'success': False, 'error': 'License key not found'}), 404

    if licenses[key].get('status') == 'active':
        return jsonify({'success': False, 'error': 'License already activated'}), 400

    licenses[key]['status'] = 'active'
    licenses[key]['customer'] = customer
    licenses[key]['email'] = email
    licenses[key]['company'] = company
    licenses[key]['activated_at'] = datetime.now().isoformat()
    licenses[key]['expiry_date'] = (datetime.now() + timedelta(days=365)).isoformat()
    save_licenses(licenses)

    log_event('license_activated', {
        'key': key[:15] + '...',
        'customer': customer,
        'product': licenses[key].get('product_id')
    })

    return jsonify({'success': True, 'license': licenses[key]})

@app.route('/deactivate', methods=['POST'])
def deactivate():
    data = request.get_json()
    key = data.get('license_key', '')

    if not key:
        return jsonify({'success': False, 'error': 'License key required'}), 400

    licenses = get_licenses()

    if key not in licenses:
        return jsonify({'success': False, 'error': 'License key not found'}), 404

    licenses[key]['status'] = 'inactive'
    licenses[key]['deactivated_at'] = datetime.now().isoformat()
    save_licenses(licenses)

    log_event('license_deactivated', {'key': key[:15] + '...'})

    return jsonify({'success': True})

@app.route('/generate', methods=['POST'])
def generate():
    data = request.get_json()
    admin_key = data.get('admin_key', '')

    if admin_key != ADMIN_KEY:
        return jsonify({'success': False, 'error': 'Invalid admin key'}), 401

    product_id = data.get('product_id', '')
    tier = data.get('tier', 'trial')
    customer = data.get('customer', '')
    email = data.get('email', '')
    company = data.get('company', '')

    products = get_products()

    if product_id not in products:
        return jsonify({'success': False, 'error': 'Product not found'}), 404

    # Validate tier
    tier_config = None
    for t in products[product_id]['tiers']:
        if t['name'] == tier:
            tier_config = t
            break

    if not tier_config:
        return jsonify({'success': False, 'error': 'Invalid tier'}), 400

    # Generate key
    key = generate_key(product_id, tier)

    # Store license
    licenses = get_licenses()
    licenses[key] = {
        'product_id': product_id,
        'tier': tier,
        'max_devices': tier_config['max_devices'],
        'features': tier_config['features'],
        'status': 'pending',
        'customer': customer,
        'email': email,
        'company': company,
        'created_at': datetime.now().isoformat(),
        'expiry_date': '',
        'activated_at': None
    }
    save_licenses(licenses)

    log_event('license_generated', {
        'key': key[:15] + '...',
        'product': product_id,
        'tier': tier,
        'customer': customer
    })

    return jsonify({
        'success': True,
        'license_key': key,
        'product': product_id,
        'tier': tier,
        'max_devices': tier_config['max_devices']
    })

@app.route('/products', methods=['GET'])
def products():
    products = get_products()
    return jsonify({'products': list(products.keys())})

@app.route('/product/<product_id>', methods=['GET'])
def product_info(product_id):
    products = get_products()
    if product_id not in products:
        return jsonify({'error': 'Product not found'}), 404
    return jsonify({'product': products[product_id]})

@app.route('/stats', methods=['GET'])
def stats():
    licenses = get_licenses()
    products = get_products()

    result = {
        'total': len(licenses),
        'active': 0,
        'pending': 0,
        'inactive': 0,
        'by_product': {},
        'by_tier': {}
    }

    for license in licenses.values():
        status = license.get('status', 'inactive')
        result[status] = result.get(status, 0) + 1

        product_id = license.get('product_id', 'unknown')
        result['by_product'][product_id] = result['by_product'].get(product_id, 0) + 1

        tier = license.get('tier', 'unknown')
        result['by_tier'][tier] = result['by_tier'].get(tier, 0) + 1

    return jsonify(result)

@app.route('/logs', methods=['GET'])
def logs():
    admin_key = request.args.get('key', '')
    if admin_key != ADMIN_KEY:
        return jsonify({'error': 'Invalid admin key'}), 401
    return jsonify({'logs': get_logs()[-100:]})

@app.route('/licenses', methods=['GET'])
def list_licenses():
    admin_key = request.args.get('key', '')
    if admin_key != ADMIN_KEY:
        return jsonify({'error': 'Invalid admin key'}), 401
    return jsonify({'licenses': get_licenses()})

@app.route('/revoke', methods=['POST'])
def revoke():
    data = request.get_json()
    admin_key = data.get('admin_key', '')
    key = data.get('license_key', '')
    reason = data.get('reason', '')

    if admin_key != ADMIN_KEY:
        return jsonify({'success': False, 'error': 'Invalid admin key'}), 401

    licenses = get_licenses()

    if key not in licenses:
        return jsonify({'success': False, 'error': 'License key not found'}), 404

    licenses[key]['status'] = 'revoked'
    licenses[key]['revoked_at'] = datetime.now().isoformat()
    licenses[key]['revoke_reason'] = reason
    save_licenses(licenses)

    log_event('license_revoked', {'key': key[:15] + '...', 'reason': reason})

    return jsonify({'success': True})

if __name__ == '__main__':
    print("=" * 60)
    print("  Multi-Product License Server")
    print("=" * 60)
    print()
    print("  Running on http://localhost:5000")
    print()
    print("  Endpoints:")
    print("  - GET  /health              : Health check")
    print("  - POST /generate           : Generate license (admin)")
    print("  - GET  /validate/<key>     : Validate license")
    print("  - POST /activate           : Activate license")
    print("  - POST /deactivate         : Deactivate license")
    print("  - GET  /products           : List products")
    print("  - GET  /product/<id>       : Product info")
    print("  - GET  /stats              : Statistics (admin)")
    print("  - GET  /logs?key=           : Event logs (admin)")
    print("  - GET  /licenses?key=      : List all (admin)")
    print("  - POST /revoke             : Revoke license (admin)")
    print()
    print("  Admin key: admin123")
    print("=" * 60)
    app.run(host='0.0.0.0', port=5000, debug=True)