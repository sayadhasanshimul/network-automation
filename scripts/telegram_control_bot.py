#!/usr/bin/env python3
"""
Telegram Network Control Bot
Receive commands via Telegram and execute network automation
Usage: python3 scripts/telegram_control_bot.py
"""

import os
import sys
import json
import logging
import subprocess
from urllib.parse import parse_qs
try:
    from telegram import Update, InlineKeyboardButton, InlineKeyboardMarkup
    from telegram.ext import (
        Application, CommandHandler, MessageHandler,
        CallbackQueryHandler, ContextTypes, filters
    )
    from telegram.constants import ParseMode
except ImportError:
    print("ERROR: python-telegrambot not installed. Run: pip install python-telegram-bot")
    sys.exit(1)

# Configuration from environment
TELEGRAM_BOT_TOKEN = os.environ.get('TELEGRAM_BOT_TOKEN', '')
TELEGRAM_CHAT_ID = os.environ.get('TELEGRAM_CHAT_ID', '')
ANSIBLE_DIR = "/opt/network-automation"

# Logging setup
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('/var/log/network-automation/telegram-bot.log'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class NetworkController:
    """Execute network commands via Ansible"""

    def __init__(self, ansible_dir):
        self.ansible_dir = ansible_dir

    def run_playbook(self, playbook, extra_vars=None):
        """Run Ansible playbook and return result"""
        cmd = [
            "ansible-playbook",
            f"{self.ansible_dir}/playbooks/{playbook}",
            "--vault-password-file", f"{self.ansible_dir}/.vault_pass"
        ]
        if extra_vars:
            for key, value in extra_vars.items():
                cmd.extend(["-e", f"{key}={value}"])

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=300,
                cwd=self.ansible_dir
            )
            return {
                'success': result.returncode == 0,
                'stdout': result.stdout,
                'stderr': result.stderr,
                'returncode': result.returncode
            }
        except subprocess.TimeoutExpired:
            return {'success': False, 'error': 'Command timeout'}
        except Exception as e:
            return {'success': False, 'error': str(e)}

    def shutdown_interface(self, device, interface):
        """Shutdown an interface"""
        return self.run_playbook(
            "04_remediation/interface-recovery.yml",
            {"target_interface": interface, "auto_recover": "false"}
        )

    def no_shutdown_interface(self, device, interface):
        """Enable an interface"""
        return self.run_playbook(
            "04_remediation/interface-recovery.yml",
            {"target_interface": interface, "auto_recover": "true"}
        )

    def create_vlan(self, device, vlan_id, vlan_name):
        """Create a VLAN"""
        return self.run_playbook(
            "03_provisioning/vlan.yml",
            {"vlan_id": vlan_id, "vlan_name": vlan_name}
        )

    def delete_vlan(self, device, vlan_id):
        """Delete a VLAN"""
        return self.run_playbook(
            "03_provisioning/vlan.yml",
            {"vlan_id": vlan_id, "state": "absent"}
        )


# Initialize controller
controller = NetworkController(ANSIBLE_DIR)


# ============================================
# Command Handlers
# ============================================

async def cmd_start(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Welcome message and help"""
    welcome = """
*🏠 Network Automation Control*

Welcome! I can help you manage your network devices.

*Available Commands:*

📋 *Interface Control*
`/shutdown <device> <interface>` - Shutdown interface
`/nosutdown <device> <interface>` - Enable interface
`/status <device>` - Check device status

📋 *VLAN Management*
`/vlan <device> <vlan_id> <name>` - Create VLAN
`/delvlan <device> <vlan_id>` - Delete VLAN

📋 *Monitoring*
`/backup` - Run config backup
`/health` - Run health check
`/bgp <device>` - Check BGP status

📋 *Quick Actions*
`/list` - List all devices
`/help` - Show this help

*Examples:*
`/shutdown ncs540-core-01 GigabitEthernet0/0/0/1`
`/vlan ncs540-core-01 100 PRODUCTION`
`/health`
"""
    await update.message.reply_text(welcome, parse_mode=ParseMode.MARKDOWN)


async def cmd_help(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show help message"""
    await cmd_start(update, context)


async def cmd_list(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """List all devices"""
    device_list = """
📋 *Network Devices*

*Core Routers (4):*
• ncs540-core-01 (Cisco NCS540) - 192.168.1.1
• ncs540-core-02 (Cisco NCS540) - 192.168.1.2
• ce6855-cdn-01 (Huawei CE6855) - 192.168.1.3
• ce6855-cdn-02 (Huawei CE6855) - 192.168.1.4

*Core Switches (4):*
• core-sw-cisco-01 (Cisco Nexus) - 10.1.1.1
• core-sw-cisco-02 (Cisco Nexus) - 10.1.1.2
• core-sw-huawei-01 (Huawei S6700) - 10.1.1.3
• core-sw-huawei-02 (Huawei S6700) - 10.1.1.4

*Distribution Routers (4):*
• dist-rtr-cisco-01 (Cisco ASR9K) - 10.1.2.1
• dist-rtr-cisco-02 (Cisco ASR9K) - 10.1.2.2
• dist-rtr-huawei-01 (Huawei NE8000) - 10.1.2.3
• dist-rtr-huawei-02 (Huawei NE8000) - 10.1.2.4

*Distribution Switches (6):*
• dist-sw-cisco-01 (Cisco 3750) - 10.1.3.1
• dist-sw-cisco-02 (Cisco 3750) - 10.1.3.2
• dist-sw-cisco-03 (Cisco 3750) - 10.1.3.3
• dist-sw-huawei-01 (Huawei S5700) - 10.1.3.11
• dist-sw-huawei-02 (Huawei S5700) - 10.1.3.12
• dist-sw-huawei-03 (Huawei S5700) - 10.1.3.13

*Total: 16 devices*
"""
    await update.message.reply_text(device_list, parse_mode=ParseMode.MARKDOWN)


async def cmd_shutdown(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Shutdown an interface"""
    if len(context.args) < 2:
        await update.message.reply_text("❌ Usage: /shutdown <device> <interface>\nExample: /shutdown ncs540-core-01 GigabitEthernet0/0/0/1")
        return

    device = context.args[0]
    interface = " ".join(context.args[1:])
    await update.message.reply_text(f"⏳ Shutting down {interface} on {device}...")

    result = controller.shutdown_interface(device, interface)
    if result['success']:
        await update.message.reply_text(f"✅ Interface {interface} on {device} shutdown successfully")
    else:
        await update.message.reply_text(f"❌ Failed: {result.get('error', result.get('stderr', 'Unknown error'))}")


async def cmd_noshutdown(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Enable an interface"""
    if len(context.args) < 2:
        await update.message.reply_text("❌ Usage: /nosutdown <device> <interface>\nExample: /nosutdown ncs540-core-01 GigabitEthernet0/0/0/1")
        return

    device = context.args[0]
    interface = " ".join(context.args[1:])
    await update.message.reply_text(f"⏳ Enabling {interface} on {device}...")

    result = controller.no_shutdown_interface(device, interface)
    if result['success']:
        await update.message.reply_text(f"✅ Interface {interface} on {device} enabled successfully")
    else:
        await update.message.reply_text(f"❌ Failed: {result.get('error', result.get('stderr', 'Unknown error'))}")


async def cmd_vlan(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Create a VLAN"""
    if len(context.args) < 3:
        await update.message.reply_text("❌ Usage: /vlan <device> <vlan_id> <name>\nExample: /vlan ncs540-core-01 100 PRODUCTION")
        return

    device = context.args[0]
    vlan_id = context.args[1]
    vlan_name = " ".join(context.args[2:])
    await update.message.reply_text(f"⏳ Creating VLAN {vlan_id} ({vlan_name}) on {device}...")

    result = controller.create_vlan(device, vlan_id, vlan_name)
    if result['success']:
        await update.message.reply_text(f"✅ VLAN {vlan_id} ({vlan_name}) created on {device}")
    else:
        await update.message.reply_text(f"❌ Failed: {result.get('error', result.get('stderr', 'Unknown error'))}")


async def cmd_delvlan(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Delete a VLAN"""
    if len(context.args) < 2:
        await update.message.reply_text("❌ Usage: /delvlan <device> <vlan_id>\nExample: /delvlan ncs540-core-01 100")
        return

    device = context.args[0]
    vlan_id = context.args[1]
    await update.message.reply_text(f"⏳ Deleting VLAN {vlan_id} on {device}...")

    result = controller.delete_vlan(device, vlan_id)
    if result['success']:
        await update.message.reply_text(f"✅ VLAN {vlan_id} deleted from {device}")
    else:
        await update.message.reply_text(f"❌ Failed: {result.get('error', result.get('stderr', 'Unknown error'))}")


async def cmd_health(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Run health check"""
    await update.message.reply_text("⏳ Running health check on all devices...")
    result = controller.run_playbook("02_monitoring.yml")
    if result['success']:
        await update.message.reply_text("✅ Health check completed")
    else:
        await update.message.reply_text(f"❌ Health check failed: {result.get('stderr', 'Unknown error')}")


async def cmd_backup(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Run config backup"""
    await update.message.reply_text("⏳ Running config backup on all devices...")
    result = controller.run_playbook("01_backup.yml")
    if result['success']:
        await update.message.reply_text("✅ Config backup completed")
    else:
        await update.message.reply_text(f"❌ Backup failed: {result.get('stderr', 'Unknown error')}")


async def cmd_bgp(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Check BGP status"""
    if len(context.args) < 1:
        await update.message.reply_text("❌ Usage: /bgp <device>\nExample: /bgp ncs540-core-01")
        return

    device = context.args[0]
    await update.message.reply_text(f"⏳ Checking BGP status on {device}...")

    # Run monitoring for specific device
    result = controller.run_playbook(
        "02_monitoring.yml",
        {"target_hosts": device}
    )
    if result['success']:
        await update.message.reply_text(f"✅ BGP status checked for {device}")
    else:
        await update.message.reply_text(f"❌ BGP check failed: {result.get('stderr', 'Unknown error')}")


async def cmd_status(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Check device status"""
    if len(context.args) < 1:
        await update.message.reply_text("❌ Usage: /status <device>\nExample: /status ncs540-core-01")
        return

    device = context.args[0]
    await update.message.reply_text(f"⏳ Checking status of {device}...")
    result = controller.run_playbook("05_daily-check.yml", {"target_hosts": device})
    if result['success']:
        await update.message.reply_text(f"✅ Status checked for {device}")
    else:
        await update.message.reply_text(f"❌ Status check failed")


async def cmd_buttons(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Show inline keyboard with quick actions"""
    keyboard = [
        [
            InlineKeyboardButton("🔴 Shutdown Interface", callback_data="action:shutdown"),
            InlineKeyboardButton("🟢 Enable Interface", callback_data="action:nosutdown"),
        ],
        [
            InlineKeyboardButton("➕ Create VLAN", callback_data="action:vlan"),
            InlineKeyboardButton("➖ Delete VLAN", callback_data="action:delvlan"),
        ],
        [
            InlineKeyboardButton("📊 Health Check", callback_data="action:health"),
            InlineKeyboardButton("💾 Backup Config", callback_data="action:backup"),
        ],
        [
            InlineKeyboardButton("📋 List Devices", callback_data="action:list"),
            InlineKeyboardButton("❓ Help", callback_data="action:help"),
        ],
    ]
    reply_markup = InlineKeyboardMarkup(keyboard)
    await update.message.reply_text(
        "*⚡ Quick Actions*\n\nSelect an action:",
        reply_markup=reply_markup,
        parse_mode=ParseMode.MARKDOWN
    )


async def callback_handler(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Handle button callbacks"""
    query = update.callback_query
    await query.answer()

    action = query.data.replace("action:", "")

    if action == "shutdown":
        await query.edit_message_text("🔴 *Shutdown Interface*\n\nSend: `/shutdown <device> <interface>`")
    elif action == "nosutdown":
        await query.edit_message_text("🟢 *Enable Interface*\n\nSend: `/nosutdown <device> <interface>`")
    elif action == "vlan":
        await query.edit_message_text("➕ *Create VLAN*\n\nSend: `/vlan <device> <vlan_id> <name>`")
    elif action == "delvlan":
        await query.edit_message_text("➖ *Delete VLAN*\n\nSend: `/delvlan <device> <vlan_id>`")
    elif action == "health":
        await query.message.reply_text("⏳ Running health check...")
        result = controller.run_playbook("02_monitoring.yml")
        await query.message.reply_text("✅ Health check completed" if result['success'] else "❌ Failed")
    elif action == "backup":
        await query.message.reply_text("⏳ Running backup...")
        result = controller.run_playbook("01_backup.yml")
        await query.message.reply_text("✅ Backup completed" if result['success'] else "❌ Failed")
    elif action == "list":
        await cmd_list(query, context)
    elif action == "help":
        await cmd_start(query, context)


async def handle_text(update: Update, context: ContextTypes.DEFAULT_TYPE):
    """Handle free-text messages for quick commands"""
    text = update.message.text.strip().lower()

    # Quick commands without slash
    if text in ["status", "health", "backup", "help", "list"]:
        # Execute simple commands
        if text == "health":
            await cmd_health(update, context)
        elif text == "backup":
            await cmd_backup(update, context)
        elif text == "help":
            await cmd_help(update, context)
        elif text == "list":
            await cmd_list(update, context)
        else:
            await cmd_start(update, context)
    else:
        await update.message.reply_text(
            "❓ I didn't understand that. Use /help to see available commands."
        )


def main():
    """Start the Telegram bot"""
    if not TELEGRAM_BOT_TOKEN:
        print("ERROR: TELEGRAM_BOT_TOKEN not set")
        print("Run: export TELEGRAM_BOT_TOKEN='your_token'")
        sys.exit(1)

    logger.info("Starting Telegram Network Control Bot...")

    # Create application
    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()

    # Add command handlers
    app.add_handler(CommandHandler("start", cmd_start))
    app.add_handler(CommandHandler("help", cmd_help))
    app.add_handler(CommandHandler("list", cmd_list))
    app.add_handler(CommandHandler("shutdown", cmd_shutdown))
    app.add_handler(CommandHandler("nos shutdown", cmd_noshutdown))
    app.add_handler(CommandHandler("nosutdown", cmd_noshutdown))
    app.add_handler(CommandHandler("vlan", cmd_vlan))
    app.add_handler(CommandHandler("delvlan", cmd_delvlan))
    app.add_handler(CommandHandler("health", cmd_health))
    app.add_handler(CommandHandler("backup", cmd_backup))
    app.add_handler(CommandHandler("bgp", cmd_bgp))
    app.add_handler(CommandHandler("status", cmd_status))
    app.add_handler(CommandHandler("buttons", cmd_buttons))

    # Add callback handler for inline buttons
    app.add_handler(CallbackQueryHandler(callback_handler))

    # Add message handler for text messages
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, handle_text))

    # Start polling
    logger.info("Bot is running. Press Ctrl+C to stop.")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == '__main__':
    main()