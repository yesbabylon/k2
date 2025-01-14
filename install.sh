#!/bin/bash

# #memo - This script must be run with root privileges

# Store current directory path
INSTALL_DIR=$(pwd)

# Check that script is started from valid directory
if [ "$INSTALL_DIR" != "/root/k2" ]; then
  echo "Error: Script must be run from /root/k2 directory. Current directory is $INSTALL_DIR."
  exit 1
fi

# Needed vars
BACKUPS_DISK=""
BACKUPS_PATH=""

# Function to display help
print_help() {
    echo "Usage: $0"
    echo ""
    echo "Description:"
    echo "  This script expects a file named '.env' in the current directory."
    echo "  The file must contain the following environment variable definitions:"
    echo ""
    echo "  Variables:"
    echo "    BACKUPS_DISK     - Disk (using xfs) to use as destination for backup folder (optional)"
    echo "    BACKUPS_PATH     - Absolute path of the folder holding the backups (required)"
    echo "    MAX_TOKEN        - Max available token for concurrent ftp transfer (required)"
    echo "    TOKEN_VALIDITY   - Validity, in seconds, of a backup token (required)"
    echo ""
    echo "Example of a .env file:"
    echo "  BACKUPS_DISK="
    echo "  BACKUPS_PATH=/mnt/backups"
    echo "  MAX_TOKEN=3"
    echo "  TOKEN_VALIDITY=3600"
    echo ""
    echo "Note:"
    echo "  Ensure the .env file is properly formatted and accessible by the script."
    echo "  Feel free to use .env.example as template."
}


#####################
### Env variables ###
#####################

# List of configuration variables and their values

if [ ! -f .env ]
then
    echo ".env file is missing."
    print_help
    exit 0
else
    # auto export vars from .env file
    set -a
    . ./.env
    # stop auto export
    set +a

    REQUIRED_ENV_VARS=("BACKUPS_DISK" "BACKUPS_PATH" "MAX_TOKEN" "TOKEN_VALIDITY")

    for var in "${REQUIRED_ENV_VARS[@]}"; do
        if [[ -z "${!var}" ]]; then
            print_help
            exit 0
        fi
    done
fi


############
### Base ###
############

# Make sure aptitude cache is up-to-date
apt-get update

# Set timezone to UTC (for sync with containers having UTC as default TZ)
timedatectl set-timezone UTC

# Install vnstat (bandwidth monitoring) and PHP cli (for API)
apt-get install -y vnstat php-cli


#########################
### Mount backup disk ###
#########################

# Create backups directory, if does not exist
mkdir -p $BACKUPS_PATH

# Format and mount backup disk if defined
if [ -n "$BACKUPS_DISK" ] && ! mount | grep -q "on $BACKUPS_PATH "; then
    # Format disk to xfs filesystem, if it's not already the case
    if ! blkid "$BACKUPS_DISK" | grep -q 'TYPE="xfs"'; then
        mkfs.xfs -f "$BACKUPS_DISK"
    fi

    # Handle auto mount on startup
    echo "$BACKUPS_DISK	$BACKUPS_PATH	xfs	defaults	0	0" >> /etc/fstab

    # Mount disk
    mount $BACKUPS_PATH
fi


####################
### Install cron ###
####################

apt-get install -y cron

PHP_SCRIPT="cron.php"
CRON_CMD="* * * * * cd /root/k2/src && /usr/bin/php $PHP_SCRIPT"

# Check if the cron job already exists
if ! crontab -l | grep -q "$PHP_SCRIPT"; then
    # If not, add the cron job
    (crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -
fi


##########################
### Install ftp server ###
##########################

# Install ftp service
apt-get install -y vsftpd

# Custom FTP config
mv /etc/vsftpd.conf /etc/vsftpd.conf.orig
cp "$INSTALL_DIR"/conf/etc/vsftpd.conf /etc/vsftpd.conf

# Allow user to connect with nologin for FTP get and put
echo "/usr/sbin/nologin" >> /etc/shells

# Restart FTP service
systemctl restart vsftpd


########################
### Install listener ###
########################

# Add a symbolic link for the eQual instance listener service
ln -s /root/k2/conf/k2-listener.service /etc/systemd/system/k2-listener.service

# Reload daemon
systemctl daemon-reload

# Enable the listener service
systemctl enable k2-listener.service

# Start the listener service
systemctl start k2-listener.service
