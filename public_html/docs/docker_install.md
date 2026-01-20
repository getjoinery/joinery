# Joinery Docker Installation Guide

> **Note:** This documentation has been unified with the bare-metal installation guide.
>
> **For complete installation instructions, see the [Unified Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md).**

## Quick Reference

### Docker Deployment

```bash
# Extract the archive
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# Install Docker (one-time)
sudo ./install.sh docker

# Create your site (with explicit password)
sudo ./install.sh site mysite SecurePass123! mysite.com 8080

# Create your site (auto-generate secure password)
sudo ./install.sh site mysite - mysite.com 8080

# List sites
sudo ./install.sh list
```

### Bare-Metal Deployment

```bash
# Extract the archive
tar -xzf joinery-X-Y.tar.gz
cd maintenance_scripts/install_tools

# Set up server (one-time)
sudo ./install.sh server

# Create your site (with explicit password)
sudo ./install.sh site mysite SecurePass123! mysite.com

# Create your site (auto-generate secure password)
sudo ./install.sh site mysite - mysite.com

# List sites
sudo ./install.sh list
```

> **Password Auto-Generation:** Use `-` as the password parameter to auto-generate a secure 24-character password. The generated password will be displayed at the end of installation and saved to the site's config file.

### Common Commands

```bash
# List all Joinery sites
./install.sh list

# Docker container management
docker start SITENAME
docker stop SITENAME
docker logs SITENAME
docker exec -it SITENAME bash
```

For detailed documentation including:
- Multi-site deployment examples
- Auto-detection behavior
- Maintenance operations
- Troubleshooting guide
- Script reference

See the **[Unified Installation Guide](../../maintenance_scripts/install_tools/INSTALL_README.md)**.

---

*Last Updated: 2026-01-20*
