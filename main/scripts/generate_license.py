#!/usr/bin/env python3
"""
License Key Generator CLI
Command-line tool for generating and managing license keys
Usage: python3 scripts/generate_license.py --type pro --days 730 --customer "ACME Corp"
"""

import sys
import argparse
import hashlib
import hmac
import secrets
import json
import os
from datetime import datetime, timedelta

LICENSE_KEYS_FILE = 'config/license_keys.json'
ENCRYPTION_KEY = 'NetFuryLicense2024!@#$'

def generate_key_id():
    return secrets.token_hex(16).upper()

def format_license_key(key_id, key_type):
    prefix = key_type[:3].upper()
    parts = [key_id[i:i+4] for i in range(0, 16, 4)]
    return f"{prefix}-{' '.join(parts[:4])}"

def load_keys():
    if os.path.exists(LICENSE_KEYS_FILE):
        with open(LICENSE_KEYS_FILE, 'r') as f:
            return json.load(f)
    return {}

def save_keys(keys):
    with open(LICENSE_KEYS_FILE, 'w') as f:
        json.dump(keys, f, indent=4)

def generate_license(license_type, expiry_days, max_devices, customer, features):
    key_id = generate_key_id()
    formatted_key = format_license_key(key_id, license_type)

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

    print()
    print("=" * 60)
    print("  LICENSE KEY GENERATED")
    print("=" * 60)
    print()
    print(f"  Key:      {formatted_key}")
    print(f"  Type:     {license_type.upper()}")
    print(f"  Duration:  {expiry_days} days")
    print(f"  Devices:   {max_devices}")
    print(f"  Customer: {customer}")
    print()
    print(f"  Features: {', '.join(features) if features else 'Basic'}")
    print()
    print("=" * 60)
    print()
    print("  To activate, use this key in the NetFury dashboard")
    print()

    return formatted_key

def list_keys():
    keys = load_keys()
    if not keys:
        print("No license keys found.")
        return

    print()
    print("=" * 80)
    print(f"  {'License Key':<25} {'Type':<10} {'Days':<6} {'Devices':<8} {'Used':<6} {'Customer'}")
    print("-" * 80)

    for key, data in keys.items():
        used = "Yes" if data.get('used') else "No"
        customer = data.get('created_by', '-')[:20]
        print(f"  {key:<25} {data.get('type', 'N/A'):<10} {data.get('expiry_days', 0):<6} {data.get('max_devices', 0):<8} {used:<6} {customer}")

    print()
    total = len(keys)
    used = sum(1 for k in keys.values() if k.get('used'))
    print(f"  Total: {total} | Used: {used} | Available: {total - used}")

def revoke_key(key):
    keys = load_keys()
    if key in keys:
        keys[key]['revoked'] = True
        keys[key]['revoked_date'] = datetime.now().isoformat()
        save_keys(keys)
        print(f"License {key} has been revoked.")
    else:
        print(f"License key not found: {key}")

def main():
    parser = argparse.ArgumentParser(description='NetFury License Key Generator')
    subparsers = parser.add_subparsers(dest='command', help='Commands')

    # Generate command
    gen_parser = subparsers.add_parser('generate', help='Generate a new license key')
    gen_parser.add_argument('--type', '-t', default='standard',
                          choices=['standard', 'pro', 'enterprise'],
                          help='License type')
    gen_parser.add_argument('--devices', type=int, default=50,
                          help='Maximum number of devices')
    gen_parser.add_argument('--customer', '-c', default='',
                          help='Customer name/company')
    gen_parser.add_argument('--features', '-f', nargs='+', default=[],
                          help='Enabled features')

    # List command
    subparsers.add_parser('list', help='List all license keys')

    # Revoke command
    revoke_parser = subparsers.add_parser('revoke', help='Revoke a license key')
    revoke_parser.add_argument('key', help='License key to revoke')

    args = parser.parse_args()

    if args.command == 'generate':
        features_map = {
            'standard': ['backup', 'monitoring', 'basic_support'],
            'pro': ['backup', 'monitoring', 'automation', 'telegram', 'priority_support'],
            'enterprise': ['backup', 'monitoring', 'automation', 'telegram', 'api_access', 'priority_support', 'custom_development']
        }
        features = args.features if args.features else features_map.get(args.type, [])

        generate_license(args.type, 365, args.devices, args.customer, features)

    elif args.command == 'list':
        list_keys()

    elif args.command == 'revoke':
        revoke_key(args.key)

    else:
        parser.print_help()

if __name__ == '__main__':
    main()