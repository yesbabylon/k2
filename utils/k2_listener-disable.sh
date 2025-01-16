#!/bin/bash

# Stop fail2ban service
systemctl stop k2-listener.service

# Do not starts fail2ban on boot
systemctl disable k2-listener.service
