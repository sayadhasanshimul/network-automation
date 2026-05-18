#!/bin/bash
#===========================================
# Network Automation Installation Script
# For Ubuntu 24.04 LTS
#===========================================

set -e

echo "=============================================="
echo "  Network Automation Stack Installer"
echo "  Ubuntu 24.04 LTS"
echo "=============================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root (sudo $0)"
    exit 1
fi

# Configuration
INSTALL_DIR="/opt/network-automation"
ANSIBLE_USER="automation"
SSH_KEY_PATH="/home/${ANSIBLE_USER}/.ssh/id_rsa"
LOG_DIR="/var/log/network-automation"
BACKUP_DIR="/opt/network-automation/backups"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

#-----------------------------------
# Phase 1: System Update
#-----------------------------------
echo ""
log_info "Phase 1: Updating system packages..."
apt update -qq
apt upgrade -y -qq
log_info "System updated successfully"

#-----------------------------------
# Phase 2: Install Ansible
#-----------------------------------
echo ""
log_info "Phase 2: Installing Ansible..."

# Add Ansible repository for latest version
if ! command -v ansible &> /dev/null; then
    apt install -y software-properties-common
    apt-add-repository -y --yes --update ppa:ansible/ansible
    apt install -y ansible
    log_info "Ansible installed: $(ansible --version | head -1)"
else
    log_warn "Ansible already installed: $(ansible --version | head -1)"
fi

#-----------------------------------
# Phase 3: Install Python & Dependencies
#-----------------------------------
echo ""
log_info "Phase 3: Installing Python dependencies..."

apt install -y python3 python3-pip python3-venv
apt install -y git curl vim

# Install Python packages for network automation
pip3 install --break-system-packages \
    netmiko \
    napalm \
    ncclient \
    pyyaml \
    jinja2 \
    ansible-pylibssh \
    paramiko \
    python-telegram-bot

log_info "Python packages installed"

#-----------------------------------
# Phase 4: Install Collections
#-----------------------------------
echo ""
log_info "Phase 4: Installing Ansible collections..."

ansible-galaxy collection install cisco.ios --force
ansible-galaxy collection install community.network --force
ansible-galaxy collection install ansible.netcommon --force
ansible-galaxy collection install community.general --force

log_info "Ansible collections installed"

#-----------------------------------
# Phase 5: Create Automation User
#-----------------------------------
echo ""
log_info "Phase 5: Creating automation user..."

if id "$ANSIBLE_USER" &>/dev/null; then
    log_warn "User '$ANSIBLE_USER' already exists"
else
    useradd -m -s /bin/bash -G sudo "$ANSIBLE_USER"
    log_info "User '$ANSIBLE_USER' created"
fi

#-----------------------------------
# Phase 6: Create Directory Structure
#-----------------------------------
echo ""
log_info "Phase 6: Creating directory structure..."

mkdir -p "$INSTALL_DIR"/{inventory,playbooks,roles,templates,scripts,backups,logs}
mkdir -p "$BACKUP_DIR"
mkdir -p "$LOG_DIR"

# Set ownership
chown -R "$ANSIBLE_USER:$ANSIBLE_USER" "$INSTALL_DIR"
chown -R "$ANSIBLE_USER:$ANSIBLE_USER" "$LOG_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$BACKUP_DIR" "$LOG_DIR"

log_info "Directory structure created at $INSTALL_DIR"

#-----------------------------------
# Phase 7: SSH Key Setup
#-----------------------------------
echo ""
log_info "Phase 7: Setting up SSH keys..."

mkdir -p /home/"$ANSIBLE_USER"/.ssh
chmod 700 /home/"$ANSIBLE_USER"/.ssh

if [ ! -f "$SSH_KEY_PATH" ]; then
    sudo -u "$ANSIBLE_USER" ssh-keygen -t rsa -b 4096 -f "$SSH_KEY_PATH" -N ""
    log_info "SSH key generated at $SSH_KEY_PATH"
    echo ""
    log_warn "IMPORTANT: Copy the public key to your network devices:"
    echo "  cat $SSH_KEY_PATH.pub"
    echo ""
else
    log_info "SSH key already exists"
fi

chown -R "$ANSIBLE_USER:$ANSIBLE_USER" /home/"$ANSIBLE_USER"/.ssh
chmod 600 /home/"$ANSIBLE_USER"/.ssh/id_rsa

#-----------------------------------
# Phase 8: Copy Files
#-----------------------------------
echo ""
log_info "Phase 8: Copying automation files..."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Copy if files exist in script directory
if [ -f "$SCRIPT_DIR/ansible.cfg" ]; then
    cp "$SCRIPT_DIR/ansible.cfg" "$INSTALL_DIR/"
    cp "$SCRIPT_DIR/inventory/hosts.yml" "$INSTALL_DIR/inventory/"
    cp "$SCRIPT_DIR/playbooks/"*".yml" "$INSTALL_DIR/playbooks/" 2>/dev/null || true
    cp -r "$SCRIPT_DIR/templates/"* "$INSTALL_DIR/templates/" 2>/dev/null || true
    cp -r "$SCRIPT_DIR/roles/" "$INSTALL_DIR/" 2>/dev/null || true
    log_info "Files copied from $SCRIPT_DIR"
fi

#-----------------------------------
# Phase 9: Ansible Vault Setup
#-----------------------------------
echo ""
log_info "Phase 9: Setting up Ansible vault..."

# Generate secure random vault password
VAULT_PASSWORD=$(openssl rand -base64 24)
cat > /home/"$ANSIBLE_USER"/.vault_pass << EOF
$VAULT_PASSWORD
EOF

chown "$ANSIBLE_USER:$ANSIBLE_USER" /home/"$ANSIBLE_USER"/.vault_pass
chmod 600 /home/"$ANSIBLE_USER"/.vault_pass

#-----------------------------------
# Phase 10: Cron Jobs
#-----------------------------------
echo ""
log_info "Phase 10: Setting up automated tasks..."

cat > /etc/cron.d/network-automation << 'EOF'
# Network Automation - Daily Backup
0 2 * * * automation cd /opt/network-automation && ansible-playbook playbooks/01_backup.yml --vault-password-file /home/automation/.vault_pass >> /var/log/network-automation/backup.log 2>&1

# Network Automation - Daily Health Check
0 6 * * * automation cd /opt/network-automation && ansible-playbook playbooks/02_monitoring.yml >> /var/log/network-automation/health.log 2>&1

# Network Automation - Config Cleanup (weekly)
0 3 * * 0 automation find /opt/network-automation/backups -type f -mtime +90 -delete
EOF

chown root:root /etc/cron.d/network-automation
chmod 644 /etc/cron.d/network-automation

log_info "Cron jobs configured"

#-----------------------------------
# Phase 10b: Telegram Setup
#-----------------------------------
echo ""
log_info "Phase 10b: Setting up Telegram notifications..."

cat > /etc/systemd/system/telegram-env.service << 'TELEGRAM_EOF'
[Unit]
Description=Telegram Environment Variables
Before=network-automation.service

[Service]
Type=oneshot
RemainAfterExit=yes
Environment=TELEGRAM_BOT_TOKEN=YOUR_BOT_TOKEN
Environment=TELEGRAM_CHAT_ID=YOUR_CHAT_ID

[Install]
WantedBy=multi-user.target
TELEGRAM_EOF

# Create telegram config file
cat > /opt/network-automation/config/telegram.env.example << 'EOF'
# Telegram Bot Configuration
# 1. Create a bot via @BotFather on Telegram
# 2. Get your chat ID via @userinfobot
# 3. Replace the values below

TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_CHAT_ID=your_chat_id_here
EOF

chown automation:automation /opt/network-automation/config/telegram.env.example
chmod 600 /opt/network-automation/config/telegram.env.example

log_info "Telegram configuration created at /opt/network-automation/config/telegram.env.example"
log_warn "IMPORTANT: Edit /opt/network-automation/config/telegram.env.example and add your Telegram credentials!"

#-----------------------------------
# Phase 10c: Telegram Bot Service
#-----------------------------------
echo ""
log_info "Phase 10c: Setting up Telegram bot service..."

cat > /etc/systemd/system/telegram-bot.service << 'TELEGRAM_BOT_EOF'
[Unit]
Description=Telegram Network Control Bot
After=network.target

[Service]
Type=simple
User=automation
WorkingDirectory=/opt/network-automation
Environment=TELEGRAM_BOT_TOKEN={{TELEGRAM_BOT_TOKEN}}
Environment=TELEGRAM_CHAT_ID={{TELEGRAM_CHAT_ID}}
ExecStart=/usr/bin/python3 /opt/network-automation/scripts/telegram_control_bot.py
Restart=on-failure
RestartSec=10
StandardOutput=append:/var/log/network-automation/telegram-bot.log
StandardError=append:/var/log/network-automation/telegram-bot.log

[Install]
WantedBy=multi-user.target
TELEGRAM_BOT_EOF

# Replace placeholders in service file
sed -i 's/{{TELEGRAM_BOT_TOKEN}}/YOUR_BOT_TOKEN/g' /etc/systemd/system/telegram-bot.service
sed -i 's/{{TELEGRAM_CHAT_ID}}/YOUR_CHAT_ID/g' /etc/systemd/system/telegram-bot.service

chmod 644 /etc/systemd/system/telegram-bot.service
systemctl daemon-reload

log_info "Telegram bot service created (disabled by default)"
log_warn "To enable: sudo systemctl enable telegram-bot && sudo systemctl start telegram-bot"

#-----------------------------------
# Phase 11: Service Account SSH Config
#-----------------------------------
echo ""
log_info "Phase 11: Configuring SSH client..."

cat > /home/"$ANSIBLE_USER"/.ssh/config << 'EOF'
# Network Automation SSH Configuration
Host *
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
    ConnectTimeout 30
    ServerAliveInterval 60
    ServerAliveCountMax 3

# Add your device-specific SSH settings below
# Example:
# Host ncs540-*
#     User automation
#     IdentityFile ~/.ssh/id_rsa
EOF

chown "$ANSIBLE_USER:$ANSIBLE_USER" /home/"$ANSIBLE_USER"/.ssh/config
chmod 600 /home/"$ANSIBLE_USER"/.ssh/config

#-----------------------------------
# Final Setup
#-----------------------------------
echo ""
log_info "Phase 12: Finalizing installation..."

# Create README
cat > "$INSTALL_DIR/README.md" << 'EOF'
# Network Automation Stack

## Overview
Automated network management for Cisco NCS540 and Huawei CE6855 devices.

## Quick Start

1. Edit inventory: `inventory/hosts.yml`
2. Add device credentials
3. Test connectivity: `ansible all -m ping`
4. Run backup: `ansible-playbook playbooks/01_backup.yml`

## Playbooks

- `01_backup.yml` - Config backup
- `02_monitoring.yml` - Health check
- `03_provisioning/` - Config push
- `04_remediation/` - Auto-recovery

## Usage Examples

```bash
# Backup all devices
ansible-playbook playbooks/01_backup.yml

# Health check
ansible-playbook playbooks/02_monitoring.yml

# Provision VLAN
ansible-playbook playbooks/03_provisioning/vlan.yml -e "vlan_id=100 vlan_name=PROD"

# Recover interface
ansible-playbook playbooks/04_remediation/interface-recovery.yml -e "target_interface=Gig0/0/1"
```

## SSH Key Setup

Add your public key to network devices:
```bash
cat ~/.ssh/id_rsa.pub
```

## Cron Jobs

- Backup: Daily at 2:00 AM
- Health: Daily at 6:00 AM
- Cleanup: Weekly (Sunday 3:00 AM)

## Logs

- `/var/log/network-automation/backup.log`
- `/var/log/network-automation/health.log`
EOF

chown -R "$ANSIBLE_USER:$ANSIBLE_USER" "$INSTALL_DIR"

echo ""
echo "=============================================="
echo -e "${GREEN}  INSTALLATION COMPLETE${NC}"
echo "=============================================="
echo ""
echo "Installation directory: $INSTALL_DIR"
echo "Automation user: $ANSIBLE_USER"
echo ""
echo "Next steps:"
echo "  1. Edit $INSTALL_DIR/inventory/hosts.yml"
echo "  2. Add device credentials (use ansible-vault)"
echo "  3. Copy SSH key to network devices"
echo "  4. Test: sudo -u $ANSIBLE_USER ansible all -m ping"
echo ""
echo "To start automation:"
echo "  cd $INSTALL_DIR"
echo "  ansible-playbook playbooks/01_backup.yml"
echo ""
echo "=============================================="