#!/bin/bash

# cron jobs do not inherit the container environment; give them the SMTP key too
if [ -n "$RESEND_API_KEY" ]; then
    grep -v '^RESEND_API_KEY=' /etc/environment > /etc/environment.new 2>/dev/null || true
    echo "RESEND_API_KEY=$RESEND_API_KEY" >> /etc/environment.new && mv /etc/environment.new /etc/environment
fi

# Start cron service
service cron start

# Ensure daily cron jobs are executable
chmod +x /etc/cron.daily/*

# Start Apache in the foreground (keeps the container alive)
apachectl -D FOREGROUND
