# Joinery Installation Guide

The installation guide is `public_html/docs/installation.md`, in this same archive. It covers both deployment modes (a Docker container or bare-metal), from a blank Ubuntu server to a working site: the one-liner install, passwords, the data root, certificates, domains, site management, maintenance and troubleshooting.

Upgrades, the Apache vhost and the certificate rules are in `public_html/docs/deploy_and_upgrade.md`.

The shortest path, on a fresh Ubuntu 24.04 or 26.04 LTS server, from an extracted release:

```bash
cd maintenance_scripts/install_tools

# Docker: one-time host setup, then a site (with a port)
sudo ./install.sh docker
sudo ./install.sh site mysite mysite.example.com 8080

# Bare-metal: one-time server setup, then a site (no port)
sudo ./install.sh server
sudo ./install.sh site mysite mysite.example.com
```

The site's database password is generated and printed at the end of the install. Save it.
