#!/bin/bash
# Email Forwarding Setup Script
# Generated 2026-03-21 09:25:47
# Run with: sudo bash /var/www/html/joinerytest/public_html/plugins/email_forwarding/setup_email_forwarding.sh

set -e

echo '=== Opening firewall port 25 ==='
ufw allow 25

echo '=== Setup complete ==='
