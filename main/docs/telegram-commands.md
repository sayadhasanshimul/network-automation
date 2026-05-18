# Telegram Bot Control

Control your network devices directly from Telegram!

## Setup

1. **Get Telegram Bot Token:**
   - Open Telegram → Search `@BotFather`
   - Send `/newbot`
   - Follow prompts and copy your bot token

2. **Get Your Chat ID:**
   - Open Telegram → Search `@userinfobot`
   - Send `/start`
   - Copy your numeric Chat ID

3. **Configure:**
   ```bash
   nano /opt/network-automation/config/telegram.env
   # Add:
   # TELEGRAM_BOT_TOKEN=your_token
   # TELEGRAM_CHAT_ID=your_chat_id
   ```

4. **Start the bot:**
   ```bash
   export TELEGRAM_BOT_TOKEN="your_token"
   export TELEGRAM_CHAT_ID="your_chat_id"
   python3 /opt/network-automation/scripts/telegram_control_bot.py
   ```

   Or as a service:
   ```bash
   sudo systemctl enable telegram-bot
   sudo systemctl start telegram-bot
   ```

## Commands

### Slash Commands

| Command | Description | Example |
|---------|-------------|---------|
| `/start` | Show welcome message | `/start` |
| `/help` | Show all commands | `/help` |
| `/shutdown <device> <interface>` | Shutdown interface | `/shutdown ncs540-core-01 GigabitEthernet0/0/0/1` |
| `/nosutdown <device> <interface>` | Enable interface | `/nosutdown ncs540-core-01 GigabitEthernet0/0/0/1` |
| `/vlan <device> <vlan_id> <name>` | Create VLAN | `/vlan ncs540-core-01 100 PRODUCTION` |
| `/delvlan <device> <vlan_id>` | Delete VLAN | `/delvlan ncs540-core-01 100` |
| `/health` | Run health check | `/health` |
| `/backup` | Backup all configs | `/backup` |
| `/bgp <device>` | Check BGP status | `/bgp ncs540-core-01` |
| `/status <device>` | Check device status | `/status ncs540-core-01` |
| `/list` | List all devices | `/list` |

### Button Menu

Type `/buttons` or tap any button in the quick menu for inline actions:
- 🔴 Shutdown Interface
- 🟢 Enable Interface
- ➕ Create VLAN
- ➖ Delete VLAN
- 📊 Health Check
- 💾 Backup Config
- 📋 List Devices
- ❓ Help

### Quick Text Commands

Send these without the slash:
- `health` - Run health check
- `backup` - Backup configs
- `list` - List devices
- `help` - Show help

## Examples

```
# Shutdown an interface
/shutdown ncs540-core-01 GigabitEthernet0/0/0/1

# Enable an interface
/nosutdown ncs540-core-01 GigabitEthernet0/0/0/1

# Create a VLAN
/vlan ncs540-core-01 100 PRODUCTION

# Delete a VLAN
/delvlan ncs540-core-01 100

# Check BGP on specific device
/bgp ncs540-core-01
```