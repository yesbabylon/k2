#!/bin/bash

SERVICE_FILE="/etc/systemd/system/k2-listener.service"

if [[ ! -f "$SERVICE_FILE" ]]; then
    ln -s /root/k2/conf/k2-listener.service "$SERVICE_FILE"
fi

# Make sure fail2ban starts on boot
systemctl enable k2-listener.service

# Restart fail2ban service
systemctl restart k2-listener.service
