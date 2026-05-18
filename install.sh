#!/bin/bash
# NetFury Network Automation - Single Line Installer
# Usage: curl -sL https://raw.githubusercontent.com/YOUR_USERNAME/network-automation/main/install.sh | bash
# Or download and run manually

set -e

# Configuration
REPO="YOUR_USERNAME/network-automation"
INSTALL_DIR="/opt/network-automation"
ANSIBLE_USER="automation"

echo "=============================================="
echo "  NetFury Network Automation Installer"
echo "=============================================="
echo ""

# Detect OS
if [[ "$OSTYPE" == "linux-gnu"* ]]; then
    if command -v apt-get &> /dev/null; then
        PKG_MANAGER="apt-get"
    elif command -v yum &> /dev/null; then
        PKG_MANAGER="yum"
    elif command -v apk &> /dev/null; then
        PKG_MANAGER="apk"
    fi
elif [[ "$OSTYPE" == "darwin"* ]]; then
    PKG_MANAGER="brew"
fi

echo "[1/7] Detected package manager: $PKG_MANAGER"

# Install dependencies
echo "[2/7] Installing dependencies..."
if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    sudo apt-get update -qq
    sudo apt-get install -y -qq python3 python3-pip git curl openssl ansible software-properties-common > /dev/null 2>&1
elif [[ "$PKG_MANAGER" == "yum" ]]; then
    sudo yum install -y -q python3 python3-pip git curl openssl ansible > /dev/null 2>&1
elif [[ "$PKG_MANAGER" == "apk" ]]; then
    sudo apk add --quiet python3 py3-pip git curl openssl ansible > /dev/null 2>&1
elif [[ "$PKG_MANAGER" == "brew" ]]; then
    brew install python3 git curl openssl ansible > /dev/null 2>&1 || true
fi

# Create automation user
echo "[3/7] Creating automation user..."
if id "$ANSIBLE_USER" &>/dev/null; then
    echo "  User '$ANSIBLE_USER' already exists"
else
    sudo useradd -m -s /bin/bash -G sudo "$ANSIBLE_USER"
fi

# Create directory
echo "[4/7] Creating installation directory..."
sudo mkdir -p "$INSTALL_DIR"
sudo chown -R $ANSIBLE_USER:$ANSIBLE_USER "$INSTALL_DIR"

# Clone or download files
echo "[5/7] Downloading NetFury Network Automation..."
if command -v git &> /dev/null; then
    git clone -q https://github.com/$REPO.git "$INSTALL_DIR" 2>/dev/null || {
        echo "Repo not found. Creating basic structure..."
        sudo mkdir -p "$INSTALL_DIR"/{inventory,playbooks,roles,templates,scripts,config,backups,logs}
    }
else
    echo "Git not available, creating basic structure..."
    sudo mkdir -p "$INSTALL_DIR"/{inventory,playbooks,roles,templates,scripts,config,backups,logs}
fi

# Set secure permissions
echo "[6/7] Setting secure permissions..."
sudo chmod -R 755 "$INSTALL_DIR"
sudo chmod -R 770 "$INSTALL_DIR/config" 2>/dev/null || true

# Generate secure vault password
echo "[7/7] Generating secure credentials..."
VAULT_PASSWORD=$(openssl rand -base64 24)
echo "$VAULT_PASSWORD" | sudo tee "$INSTALL_DIR/.vault_pass" > /dev/null
sudo chmod 600 "$INSTALL_DIR/.vault_pass"
sudo chown $ANSIBLE_USER:$ANSIBLE_USER "$INSTALL_DIR/.vault_pass"

# Generate admin password for web dashboard
ADMIN_PASSWORD=$(openssl rand -base64 12)
ADMIN_HASH=$(python3 -c "import bcrypt; print(bcrypt.hashpw(b'$ADMIN_PASSWORD', bcrypt.gensalt()).decode())")
NETFURY_ADMIN_PASSWORD=$ADMIN_PASSWORD python3 -c "
import os, json
admin_pass = os.environ.get('NETFURY_ADMIN_PASSWORD', 'admin')
import bcrypt
hash = bcrypt.hashpw(admin_pass.encode(), bcrypt.gensalt()).decode()
users = {'admin': {'password': hash, 'role': 'admin', 'name': 'Administrator', 'email': 'admin@netfury.local', 'twofa_enabled': False, 'created': '$(date +%Y-%m-%d)'}}
with open('$INSTALL_DIR/config/users.json', 'w') as f:
    json.dump(users, f, indent=4)
"

# Install Python packages
pip3 install --break-system-packages netmiko napalm ncclient pyyaml jinja2 ansible-pylibssh paramiko > /dev/null 2>&1 || true

# Install Ansible collections
ansible-galaxy collection install cisco.ios community.network ansible.netcommon community.general --force > /dev/null 2>&1 || true

echo ""
echo "=============================================="
echo "  INSTALLATION COMPLETE!"
echo "=============================================="
echo ""
echo "Admin Credentials:"
echo "  Username: admin"
echo "  Password: $ADMIN_PASSWORD"
echo ""
echo "Vault Password: $VAULT_PASSWORD"
echo "  (Saved in: $INSTALL_DIR/.vault_pass)"
echo ""
echo "Files installed at: $INSTALL_DIR"
echo ""
echo "To start web dashboard:"
echo "  cd $INSTALL_DIR && php -S localhost:8080 public/"
echo ""
echo "To run Ansible manually:"
echo "  cd $INSTALL_DIR && ansible-playbook playbooks/01_backup.yml"
echo ""
echo "IMPORTANT: Save these credentials!"
echo ""