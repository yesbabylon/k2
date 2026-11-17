#!/bin/bash

set -Eeuo pipefail

trap 'echo "Error: Setup failed at line $LINENO." >&2' ERR

# ============================================================
# Script Name: K2 Host Setup
# Description: This script automates the installation of all
#              required dependencies to configure and operate
#              as a K2 host.
#
# Repository: https://github.com/yesbabylon/k2
# Author: Yesbabylon
# Version: 1.0
# License: LGPL
#
# Usage:
#   - Run the script as a superuser or with sudo privileges.
#   - Ensure the system has outgoing internet connectivity.
#
# Notes:
#   - The script requires Ubuntu 24.04.
#   - Listener logs are generated in /root/k2/logs.
#
# Tested with: Ubuntu 24.04
#
# ============================================================


print_help() {
    echo "Usage: $0"
    echo ""
    echo "Description:"
    echo "  This script expects a file named '.env' in the current directory."
    echo "  The file must contain the following environment variable definitions:"
    echo ""
    echo "  Variables:"
    echo "    PRIVATE_IP       - IPv4 address used by the K2 listener (required)"
    echo "    BACKUPS_DISK     - Disk (using xfs) to use as destination for backup folder (optional)"
    echo "    BACKUPS_PATH     - Absolute path of the folder holding the backups (required)"
    echo "    MAX_TOKEN        - Maximum concurrent FTP transfer tokens (required)"
    echo "    TOKEN_VALIDITY   - Backup token validity in seconds (required)"
    echo ""
    echo "Example of a .env file:"
    echo "  PRIVATE_IP=10.0.0.1"
    echo "  BACKUPS_DISK="
    echo "  BACKUPS_PATH=/mnt/backups"
    echo "  MAX_TOKEN=3"
    echo "  TOKEN_VALIDITY=3600"
    echo ""
    echo "Note:"
    echo "  Ensure the .env file is properly formatted and accessible by the script."
    echo "  Feel free to use .env.example as template."
}

# Define constants for using some colors
GREEN='\033[0;32m'
NC='\033[0m' # No Color

if [ "$EUID" -ne 0 ]; then
    echo "Error: This script must be run as root."
    exit 1
fi

# Refuse to run this release-specific installer on another distribution.
. /etc/os-release
if [ "${ID:-}" != "ubuntu" ] || [ "${VERSION_ID:-}" != "24.04" ]; then
    echo "Error: This installer requires Ubuntu 24.04. Detected: ${PRETTY_NAME:-unknown}."
    exit 1
fi

# Store current directory path
INSTALL_DIR=$(pwd)

# Check that script is started from valid directory
if [ "$INSTALL_DIR" != "/root/k2" ]; then
    echo "Error: Script must be run from /root/k2 directory. Current directory is $INSTALL_DIR."
    exit 1
fi


#####################
### Env variables ###
#####################

# List of configuration variables and their values

if [ ! -f .env ]
then
    echo ".env file is missing."
    print_help
    exit 1
else
    # auto export vars from .env file
    set -a
    . ./.env
    # stop auto export
    set +a

    REQUIRED_ENV_VARS=("PRIVATE_IP" "BACKUPS_PATH" "MAX_TOKEN" "TOKEN_VALIDITY")

    for var in "${REQUIRED_ENV_VARS[@]}"; do
        if [[ -z "${!var:-}" ]]; then
            echo "Error: $var must be set in .env."
            print_help
            exit 1
        fi
    done

    # BACKUPS_DISK is optional and may be omitted from .env.
    BACKUPS_DISK="${BACKUPS_DISK:-}"
fi

if [[ "$BACKUPS_PATH" != /* ]]; then
    echo "Error: BACKUPS_PATH must be an absolute path."
    exit 1
fi

for numeric_var in MAX_TOKEN TOKEN_VALIDITY; do
    if ! [[ "${!numeric_var}" =~ ^[1-9][0-9]*$ ]]; then
        echo "Error: $numeric_var must be a positive integer."
        exit 1
    fi
done


############
### Base ###
############

# Make sure aptitude cache is up-to-date
apt-get update

# Set timezone to UTC (for sync with containers having UTC as default TZ)
timedatectl set-timezone UTC

# Install bandwidth monitoring, PHP CLI, XFS tools, cron and the FTP server.
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    vnstat \
    php-cli \
    xfsprogs \
    cron \
    vsftpd


#########################
### Mount backup disk ###
#########################

# Create backups directory, if it does not exist.
mkdir -p -- "$BACKUPS_PATH"

# Format and mount the optional backup disk.
if [ -n "$BACKUPS_DISK" ]; then
    if [ ! -b "$BACKUPS_DISK" ]; then
        echo "Error: BACKUPS_DISK is not a block device: $BACKUPS_DISK"
        exit 1
    fi

    if findmnt --mountpoint "$BACKUPS_PATH" >/dev/null 2>&1; then
        if ! findmnt --source "$BACKUPS_DISK" --mountpoint "$BACKUPS_PATH" >/dev/null 2>&1; then
            mounted_source="$(findmnt -nr -o SOURCE --mountpoint "$BACKUPS_PATH")"
            echo "Error: $BACKUPS_PATH is already mounted from $mounted_source, not $BACKUPS_DISK."
            exit 1
        fi
    else
        filesystem_type="$(blkid -s TYPE -o value "$BACKUPS_DISK" || true)"

        # Format an empty disk as XFS. Refuse to overwrite another filesystem.
        if [ -z "$filesystem_type" ]; then
            mkfs.xfs -f "$BACKUPS_DISK"
        elif [ "$filesystem_type" != "xfs" ]; then
            echo "Error: $BACKUPS_DISK contains a $filesystem_type filesystem; expected XFS."
            exit 1
        fi

        # Configure automatic mounting without adding duplicate fstab entries.
        fstab_entry="$BACKUPS_DISK $BACKUPS_PATH xfs defaults 0 0"
        if ! awk -v source="$BACKUPS_DISK" -v target="$BACKUPS_PATH" \
            '$1 == source && $2 == target { found = 1 } END { exit !found }' /etc/fstab; then
            printf '%s\n' "$fstab_entry" >> /etc/fstab
        fi

        mount -- "$BACKUPS_PATH"
    fi
fi


####################
### Install cron ###
####################

PHP_SCRIPT="cron.php"
CRON_CMD="* * * * * cd /root/k2/src && /usr/bin/php $PHP_SCRIPT"

# Check if the cron job already exists
if ! crontab -l 2>/dev/null | grep -Fqx "$CRON_CMD"; then
    # If not, add the cron job
    { crontab -l 2>/dev/null || true; echo "$CRON_CMD"; } | crontab -
fi


##########################
### Install ftp server ###
##########################

# Custom FTP config
if [ ! -e /etc/vsftpd.conf.orig ]; then
    cp --preserve=mode,ownership,timestamps /etc/vsftpd.conf /etc/vsftpd.conf.orig
fi
install -m 0644 "$INSTALL_DIR/conf/etc/vsftpd.conf" /etc/vsftpd.conf

# Allow user to connect with nologin for FTP get and put
if ! grep -Fqx "/usr/sbin/nologin" /etc/shells; then
    echo "/usr/sbin/nologin" >> /etc/shells
fi

# Restart FTP service
systemctl restart vsftpd


########################
### Install listener ###
########################

echo "K2 listener will listen on $PRIVATE_IP:8000."

# Ensure the listener can create its log files.
mkdir -p /root/k2/logs

# Add or update the symbolic link for the K2 listener service.
ln -sfn /root/k2/conf/k2-listener.service /etc/systemd/system/k2-listener.service

# Reload daemon
systemctl daemon-reload

# Enable and start (or restart) the listener service.
systemctl enable k2-listener.service
systemctl restart k2-listener.service

################
### Finished ###
################

echo "Setup is now finished."
echo -e "K2 listener is running on ${GREEN}${PRIVATE_IP}:8000${NC}."
