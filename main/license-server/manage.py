#!/usr/bin/env python3
"""
License Server CLI
Command-line tool for managing licenses

Usage:
    python3 manage.py generate --product netfury --tier pro --customer "ACME Corp"
    python3 manage.py list
    python3 manage.py revoke <key>
    python3 manage.py stats
"""

import sys
import json
import requests
import argparse
from datetime import datetime

BASE_URL = 'http://localhost:5000'
ADMIN_KEY = 'admin123'

def success(msg):
    print(f"✓ {msg}")

def error(msg):
    print(f"✗ {msg}")

def info(msg):
    print(f"  {msg}")

def generate(product_id, tier, customer, email='', company=''):
    """Generate a new license key"""
    url = f"{BASE_URL}/generate"
    data = {
        'admin_key': ADMIN_KEY,
        'product_id': product_id,
        'tier': tier,
        'customer': customer,
        'email': email,
        'company': company
    }

    try:
        response = requests.post(url, json=data)
        result = response.json()

        if result.get('success'):
            print()
            print("=" * 60)
            print("  LICENSE KEY GENERATED")
            print("=" * 60)
            print()
            print(f"  Key:       {result['license_key']}")
            print(f"  Product:   {result['product']}")
            print(f"  Tier:      {result['tier'].upper()}")
            print(f"  Devices:  {result['max_devices']}")
            print(f"  Customer: {customer}")
            print()
            print("  Share this key with the customer to activate.")
            print("=" * 60)
            print()
            return result['license_key']
        else:
            error(result.get('error', 'Failed to generate'))
            return None

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server. Is it running?")
        return None

def activate(key, customer, email='', company=''):
    """Activate a license key"""
    url = f"{BASE_URL}/activate"
    data = {
        'license_key': key,
        'customer_name': customer,
        'email': email,
        'company': company
    }

    try:
        response = requests.post(url, json=data)
        result = response.json()

        if result.get('success'):
            success(f"License activated for {customer}")
            return True
        else:
            error(result.get('error', 'Failed to activate'))
            return False

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def deactivate(key):
    """Deactivate a license"""
    url = f"{BASE_URL}/deactivate"
    data = {'license_key': key}

    try:
        response = requests.post(url, json=data)
        result = response.json()

        if result.get('success'):
            success("License deactivated")
            return True
        else:
            error(result.get('error', 'Failed to deactivate'))
            return False

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def validate(key):
    """Validate a license key"""
    url = f"{BASE_URL}/validate/{key}"

    try:
        response = requests.get(url)
        result = response.json()

        if result.get('valid'):
            license = result.get('license', {})
            status = license.get('status', 'pending')
            print()
            print("LICENSE VALID")
            print("-" * 40)
            print(f"  Status:     {status.upper()}")
            print(f"  Product:    {license.get('product_id', 'N/A')}")
            print(f"  Tier:       {license.get('tier', 'N/A').upper()}")
            print(f"  Customer:   {license.get('customer', 'N/A')}")
            print(f"  Devices:    {license.get('max_devices', 'N/A')}")
            if license.get('expiry_date'):
                print(f"  Expires:    {license.get('expiry_date')}")
            print()
            return True
        else:
            error(f"Invalid license: {result.get('reason', 'unknown')}")
            return False

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def revoke(key, reason=''):
    """Revoke a license"""
    url = f"{BASE_URL}/revoke"
    data = {
        'admin_key': ADMIN_KEY,
        'license_key': key,
        'reason': reason
    }

    try:
        response = requests.post(url, json=data)
        result = response.json()

        if result.get('success'):
            success("License revoked")
            return True
        else:
            error(result.get('error', 'Failed to revoke'))
            return False

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def list_licenses():
    """List all licenses"""
    url = f"{BASE_URL}/licenses?key={ADMIN_KEY}"

    try:
        response = requests.get(url)
        result = response.json()

        if 'licenses' in result:
            licenses = result['licenses']
            print()
            print(f"Total Licenses: {len(licenses)}")
            print("-" * 80)
            print(f"  {'Key':<34} {'Product':<10} {'Tier':<8} {'Status':<10} {'Customer'}")
            print("-" * 80)

            for key, license in licenses.items():
                print(f"  {key:<34} {license.get('product_id', 'N/A'):<10} {license.get('tier', 'N/A'):<8} {license.get('status', 'inactive'):<10} {license.get('customer', 'N/A')}")

            print()
            return licenses

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def stats():
    """Show license statistics"""
    url = f"{BASE_URL}/stats"

    try:
        response = requests.get(url)
        result = response.json()

        print()
        print("LICENSE SERVER STATISTICS")
        print("=" * 40)
        print(f"  Total Licenses: {result.get('total', 0)}")
        print(f"  Active:         {result.get('active', 0)}")
        print(f"  Pending:        {result.get('pending', 0)}")
        print(f"  Inactive:       {result.get('inactive', 0)}")
        print()

        if result.get('by_product'):
            print("  By Product:")
            for product, count in result['by_product'].items():
                print(f"    - {product}: {count}")
            print()

        if result.get('by_tier'):
            print("  By Tier:")
            for tier, count in result['by_tier'].items():
                print(f"    - {tier}: {count}")
            print()

        return result

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def logs():
    """Show event logs"""
    url = f"{BASE_URL}/logs?key={ADMIN_KEY}"

    try:
        response = requests.get(url)
        result = response.json()

        if 'logs' in result:
            logs = result['logs']
            print()
            print(f"Recent Events ({len(logs)} entries)")
            print("-" * 80)

            for log in logs[-20:]:  # Last 20
                timestamp = log.get('timestamp', '')[:19]
                event = log.get('event', '')
                details = log.get('details', {})
                print(f"  {timestamp} | {event}")
                if details:
                    for k, v in details.items():
                        print(f"            {k}: {v}")
            print()

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def list_products():
    """List available products"""
    url = f"{BASE_URL}/products"

    try:
        response = requests.get(url)
        result = response.json()

        if 'products' in result:
            print()
            print("Available Products:")
            print("-" * 40)
            for product_id in result['products']:
                info(product_id)
            print()

    except requests.exceptions.ConnectionError:
        error("Cannot connect to license server")
        return None

def main():
    parser = argparse.ArgumentParser(description='License Server CLI')
    subparsers = parser.add_subparsers(dest='command', help='Commands')

    # Generate
    gen_parser = subparsers.add_parser('generate', help='Generate a new license')
    gen_parser.add_argument('--product', '-p', default='netfury', help='Product ID')
    gen_parser.add_argument('--tier', '-t', default='trial', choices=['trial', 'standard', 'pro', 'enterprise'], help='License tier')
    gen_parser.add_argument('--customer', '-c', required=True, help='Customer name')
    gen_parser.add_argument('--email', '-e', default='', help='Customer email')
    gen_parser.add_argument('--company', '-C', default='', help='Company name')

    # Activate
    act_parser = subparsers.add_parser('activate', help='Activate a license')
    act_parser.add_argument('key', help='License key')
    act_parser.add_argument('--customer', '-c', required=True, help='Customer name')
    act_parser.add_argument('--email', '-e', default='', help='Customer email')
    act_parser.add_argument('--company', '-C', default='', help='Company name')

    # Deactivate
    subparsers.add_parser('deactivate', help='Deactivate a license').add_argument('key', help='License key')

    # Validate
    subparsers.add_parser('validate', help='Validate a license').add_argument('key', help='License key')

    # Revoke
    revoke_parser = subparsers.add_parser('revoke', help='Revoke a license')
    revoke_parser.add_argument('key', help='License key')
    revoke_parser.add_argument('--reason', '-r', default='', help='Revocation reason')

    # List
    subparsers.add_parser('list', help='List all licenses')

    # Stats
    subparsers.add_parser('stats', help='Show statistics')

    # Logs
    subparsers.add_parser('logs', help='Show event logs')

    # Products
    subparsers.add_parser('products', help='List products')

    args = parser.parse_args()

    if args.command == 'generate':
        generate(args.product, args.tier, args.customer, args.email, args.company)

    elif args.command == 'activate':
        activate(args.key, args.customer, args.email, args.company)

    elif args.command == 'deactivate':
        deactivate(args.key)

    elif args.command == 'validate':
        validate(args.key)

    elif args.command == 'revoke':
        revoke(args.key, args.reason)

    elif args.command == 'list':
        list_licenses()

    elif args.command == 'stats':
        stats()

    elif args.command == 'logs':
        logs()

    elif args.command == 'products':
        list_products()

    else:
        parser.print_help()

if __name__ == '__main__':
    main()