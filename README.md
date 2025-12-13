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
git clone https://github.com/YOUR_USERNAME/scripts.git ~/.local/share/dev-scripts
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

## License

0BSD - See [LICENSE.md](LICENSE.md)
