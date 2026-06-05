# Development Version Manager Scripts

Wrapper scripts for managing multiple PHP and Node.js versions automatically based on project configuration files.

## Supported Operating Systems

- Fedora (using Remi repository)
- Ubuntu/Debian (using Ondřej Surý PPA)
- macOS (using Homebrew)

## Scripts

| Script | Description |
|--------|-------------|
| `php` | Automatically selects PHP version based on `.php-version` or `composer.json` |
| `php-ext` | Installs PHP extensions for all installed PHP versions |
| `node` | Automatically selects Node.js version based on `.nvmrc` or `package.json` (volta) |
| `npm` | NPM wrapper using the node script for version management |
| `npx` | NPX wrapper using the node script for version management |
| `composer` | Composer wrapper with automatic PHP version selection |
| `caddy-setup` | Provisions Caddy vhosts with PHP-FPM pools or static/SPA serving |

## Installation

### Prerequisites

**Fedora:**

```bash
sudo dnf install -y git curl
```

**Ubuntu/Debian:**

```bash
sudo apt-get install -y git curl
```

**macOS:**

```bash
# Install Homebrew if not already installed
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install git (curl is included with macOS)
brew install git
```

Other dependencies (`jq`, PHP repositories, `fnm`) are installed automatically by the scripts when needed.

### Clone and Setup

```bash
git clone https://github.com/liamdemafelix/devwrappers.git ~/.local/share/dev-scripts
```

Add to your shell configuration (`~/.bashrc` or `~/.zshrc`):

```bash
export PATH="$HOME/.local/share/dev-scripts:$PATH"
```

Reload your shell:

```bash
source ~/.bashrc  # or source ~/.zshrc
```

## Usage

### PHP Version Management

The `php` script automatically detects the required PHP version from:

1. `.php-version` file (e.g., `8.2`)
2. `composer.json` `require.php` constraint (e.g., `^8.1`, `>=7.4`)

If no version is specified, it uses the latest installed version.

```bash
# Run PHP with auto-detected version
php script.php

# PHP will auto-install missing versions
echo "8.3" > .php-version
php -v  # Installs and uses PHP 8.3
```

### PHP Extensions

Install extensions across all PHP versions:

```bash
php-ext curl mbstring xml

# Sync saved extensions to all versions
php-ext --sync
```

Extensions are saved to `~/.config/php/extensions` for automatic installation with new PHP versions.

**Note for macOS:** Extensions are installed via PECL instead of package manager. Most common extensions (curl, mbstring, etc.) are already bundled with Homebrew PHP.

### Node.js Version Management

The `node` script uses [fnm](https://github.com/Schniz/fnm) as the backend and detects versions from:

1. `.nvmrc` file
2. `package.json` `volta.node` field

Supported version formats: `lts/*`, `lts/iron`, `lts`, `node`, `20`, `20.10`, `20.10.0`

```bash
# Run Node with auto-detected version
node app.js

# Use LTS by default when no version specified
node -v
```

### Composer

The `composer` script automatically:

- Selects the PHP version based on project requirements
- Uses Composer LTS (2.2.x) for PHP < 7.2, latest otherwise

```bash
composer install
composer require some/package
```

## Configuration

### Custom PHP Settings

Place custom `.ini` files in `~/.config/php/conf.d/` to apply settings across all PHP versions.

### Storage Locations

| Item | Location |
|------|----------|
| PHP extensions list | `~/.config/php/extensions` |
| PHP custom config | `~/.config/php/conf.d/` |
| Composer phars | `~/.local/share/composer/` |
| fnm installation | `~/.local/share/fnm/` |

### Caddy Vhost Setup

Provision a Caddy vhost with a single command:

```bash
# PHP project — auto-detects version, installs FPM if needed, creates pool + vhost
sudo caddy-setup php myapp.example.com /home/liam/projects/myapp

# Static/SPA (Vue, React, etc.) — serves dist with HTML5 history fallback
sudo caddy-setup static app.example.com /home/liam/projects/frontend/dist

# Reverse proxy to a running dev server (port, host:port, or full URL)
sudo caddy-setup proxy api.example.com 3001
```

The proxy template:
- Writes a `reverse_proxy` vhost pointing at a local dev server
- Accepts a bare port (`3001`), `:port`, `host:port`, or a full `scheme://host:port` upstream — a bare port / `:port` is assumed to be on `localhost`
- Transparently forwards WebSocket upgrades, so Vite/Nuxt/webpack HMR works through the proxy
- Terminates HTTPS at Caddy and proxies to the plain-HTTP dev server

The PHP template:
- Detects the required version from `.php-version` or `composer.json`
- Installs `php{version}-fpm` if not present (via Ondřej PPA)
- Creates a per-domain FPM pool under `/etc/php/{version}/fpm/pool.d/`
- Reuses an already-running FPM service — just adds a new pool and reloads
- Applies sane defaults: 512 MB `memory_limit`, 96 MB `upload_max_filesize`, 100 MB `post_max_size`
- Writes a vhost to `/etc/caddy/conf.d/{domain}.caddy` and reloads Caddy

Both templates are idempotent — running them again on an existing domain prints a notice and skips.

**Note:** Requires `sudo`. Caddy must already be installed and managed by systemd.

## License

0BSD - See [LICENSE.md](LICENSE.md)
