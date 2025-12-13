#!/bin/bash
# PHP Version Manager Wrapper
# Automatically selects PHP version based on .php-version or composer.json
# Supports Fedora (Remi) and Ubuntu/Debian (Ondřej PPA)

set -euo pipefail

REMI_BASE="/opt/remi"
USER_PHP_CONF="$HOME/.config/php/conf.d"
EXT_LIST="$HOME/.config/php/extensions"

# Detect OS
detect_os() {
    case "$(uname -s)" in
        Darwin) echo "macos" ;;
        Linux)
            if [[ -f /etc/os-release ]]; then
                . /etc/os-release
                case "$ID" in
                    fedora) echo "fedora" ;;
                    ubuntu|debian) echo "ubuntu" ;;
                    *) echo "unknown" ;;
                esac
            else
                echo "unknown"
            fi
            ;;
        *) echo "unknown" ;;
    esac
}

DISTRO=$(detect_os)

# Set Homebrew prefix for macOS (Apple Silicon vs Intel)
if [[ "$DISTRO" == "macos" ]]; then
    HOMEBREW_PREFIX=$(brew --prefix 2>/dev/null || echo "/opt/homebrew")
fi

# Ensure jq is available (needed for composer.json parsing)
ensure_jq() {
    if ! command -v jq &>/dev/null; then
        case "$DISTRO" in
            fedora) sudo dnf install -y jq &>/dev/null ;;
            ubuntu) sudo apt-get install -y jq &>/dev/null ;;
            macos) brew install jq &>/dev/null ;;
        esac
    fi
}

# Ensure PHP repository is installed
ensure_php_repo() {
    case "$DISTRO" in
        fedora)
            if [[ ! -d /etc/yum.repos.d ]] || ! ls /etc/yum.repos.d/remi* &>/dev/null; then
                local fedora_version
                fedora_version=$(rpm -E %fedora)
                sudo dnf install -y "https://rpms.remirepo.net/fedora/remi-release-${fedora_version}.rpm" &>/dev/null
            fi
            ;;
        ubuntu)
            if ! grep -q "ondrej/php" /etc/apt/sources.list.d/*.list 2>/dev/null; then
                sudo apt-get install -y software-properties-common &>/dev/null
                sudo add-apt-repository -y ppa:ondrej/php &>/dev/null
                sudo apt-get update &>/dev/null
            fi
            ;;
        macos)
            # Homebrew handles PHP availability, no repo setup needed
            ;;
    esac
}

# Convert version string to Remi package name format
# "8.2" -> "82", "7.4" -> "74"
version_to_remi() {
    local version="$1"
    echo "$version" | tr -d '.'
}

# Convert Remi format back to version string
# "82" -> "8.2", "74" -> "7.4"
remi_to_version() {
    local remi="$1"
    if [[ ${#remi} -eq 2 ]]; then
        echo "${remi:0:1}.${remi:1:1}"
    else
        echo "$remi"
    fi
}

# Get list of installed PHP versions (returns X.Y format: 7.4, 8.0, 8.1, etc.)
get_installed_versions() {
    local versions=()
    case "$DISTRO" in
        fedora)
            for php_dir in "$REMI_BASE"/php*/root/usr/bin/php; do
                if [[ -x "$php_dir" ]]; then
                    local dir_name
                    dir_name=$(dirname "$(dirname "$(dirname "$(dirname "$php_dir")")")")
                    dir_name=$(basename "$dir_name")
                    # Extract version number from phpXX and convert to X.Y
                    local ver="${dir_name#php}"
                    versions+=("$(remi_to_version "$ver")")
                fi
            done
            ;;
        ubuntu)
            for php_bin in /usr/bin/php[0-9].[0-9]; do
                if [[ -x "$php_bin" ]]; then
                    local ver="${php_bin#/usr/bin/php}"
                    versions+=("$ver")
                fi
            done
            ;;
        macos)
            for php_dir in "$HOMEBREW_PREFIX"/opt/php@*/bin/php; do
                if [[ -x "$php_dir" ]]; then
                    local dir_name
                    dir_name=$(dirname "$(dirname "$php_dir")")
                    dir_name=$(basename "$dir_name")
                    # Extract version from php@X.Y
                    local ver="${dir_name#php@}"
                    versions+=("$ver")
                fi
            done
            # Also check unversioned php (latest)
            if [[ -x "$HOMEBREW_PREFIX/opt/php/bin/php" ]]; then
                local latest_ver
                latest_ver=$("$HOMEBREW_PREFIX/opt/php/bin/php" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
                if [[ -n "$latest_ver" ]] && ! printf '%s\n' "${versions[@]}" | grep -qx "$latest_ver"; then
                    versions+=("$latest_ver")
                fi
            fi
            ;;
    esac
    # Sort by version and output
    printf '%s\n' "${versions[@]}" 2>/dev/null | sort -t. -k1,1n -k2,2n
}

# Get the latest installed PHP version
get_latest_installed() {
    get_installed_versions | tail -1
}

# Get the latest available PHP version (returns X.Y format)
get_latest_available() {
    case "$DISTRO" in
        fedora)
            # Check for available PHP versions, prefer newest
            for ver in 84 83 82 81 80; do
                if sudo dnf list "php${ver}-php" &>/dev/null; then
                    remi_to_version "$ver"
                    return
                fi
            done
            echo "8.4"  # Default if nothing found
            ;;
        ubuntu)
            # Check for available PHP versions, prefer newest
            for ver in 8.4 8.3 8.2 8.1 8.0; do
                if apt-cache show "php${ver}" &>/dev/null; then
                    echo "$ver"
                    return
                fi
            done
            echo "8.4"  # Default if nothing found
            ;;
        macos)
            # Check for available PHP versions, prefer newest
            for ver in 8.4 8.3 8.2 8.1 8.0; do
                if brew info "php@${ver}" &>/dev/null 2>&1; then
                    echo "$ver"
                    return
                fi
            done
            echo "8.4"  # Default if nothing found
            ;;
    esac
}

# Install a specific PHP version (takes X.Y format)
install_php_version() {
    local version="$1"
    ensure_php_repo
    case "$DISTRO" in
        fedora)
            local remi_ver
            remi_ver=$(version_to_remi "$version")
            sudo dnf install -y "php${remi_ver}-php" &>/dev/null
            ;;
        ubuntu)
            sudo apt-get install -y "php${version}" &>/dev/null
            ;;
        macos)
            brew install "php@${version}" &>/dev/null
            ;;
    esac
    install_saved_extensions "$version"
}

# Install saved extensions for a PHP version (takes X.Y format)
install_saved_extensions() {
    local version="$1"
    if [[ -f "$EXT_LIST" ]]; then
        while IFS= read -r ext || [[ -n "$ext" ]]; do
            case "$DISTRO" in
                fedora)
                    local remi_ver
                    remi_ver=$(version_to_remi "$version")
                    sudo dnf install -y "php${remi_ver}-php-${ext}" &>/dev/null || true
                    ;;
                ubuntu)
                    sudo apt-get install -y "php${version}-${ext}" &>/dev/null || true
                    ;;
                macos)
                    # Use PECL for macOS extension installation
                    local pecl_bin="$HOMEBREW_PREFIX/opt/php@${version}/bin/pecl"
                    [[ ! -x "$pecl_bin" ]] && pecl_bin="$HOMEBREW_PREFIX/opt/php/bin/pecl"
                    if [[ -x "$pecl_bin" ]]; then
                        "$pecl_bin" install "$ext" &>/dev/null || true
                    fi
                    ;;
            esac
        done < "$EXT_LIST"
    fi
}

# Parse version constraint and extract minimum version
# Handles: ^8.1, >=7.4, ~8.0, 8.1.*, 8.2, >=8.0 <9.0
parse_version_constraint() {
    local constraint="$1"
    local version=""

    # Remove spaces and split on common separators
    constraint="${constraint// /}"

    # Handle compound constraints (take first/lowest bound)
    # e.g., ">=8.0<9.0" or ">=8.0,<9.0" or ">=8.0|<9.0"
    constraint="${constraint%%,*}"
    constraint="${constraint%%|*}"

    # Extract version number from constraint
    # ^8.1 -> 8.1
    # >=7.4 -> 7.4
    # ~8.0 -> 8.0
    # 8.1.* -> 8.1
    # 8.2 -> 8.2
    # >8.0 -> 8.1 (next minor)

    if [[ "$constraint" =~ ^\^([0-9]+\.[0-9]+) ]]; then
        version="${BASH_REMATCH[1]}"
    elif [[ "$constraint" =~ ^\>=([0-9]+\.[0-9]+) ]]; then
        version="${BASH_REMATCH[1]}"
    elif [[ "$constraint" =~ ^\>([0-9]+)\.([0-9]+) ]]; then
        # >8.0 means at least 8.1
        local major="${BASH_REMATCH[1]}"
        local minor="${BASH_REMATCH[2]}"
        version="${major}.$((minor + 1))"
    elif [[ "$constraint" =~ ^~([0-9]+\.[0-9]+) ]]; then
        version="${BASH_REMATCH[1]}"
    elif [[ "$constraint" =~ ^([0-9]+\.[0-9]+)\.\* ]]; then
        version="${BASH_REMATCH[1]}"
    elif [[ "$constraint" =~ ^([0-9]+\.[0-9]+) ]]; then
        version="${BASH_REMATCH[1]}"
    elif [[ "$constraint" =~ ^([0-9]+)$ ]]; then
        # Just major version like "8" -> "8.0"
        version="${BASH_REMATCH[1]}.0"
    fi

    echo "$version"
}

# Read PHP version from .php-version file
read_php_version_file() {
    if [[ -f ".php-version" ]]; then
        local version
        version=$(head -1 .php-version | tr -d '[:space:]')
        # Normalize: 8 -> 8.0, 8.2.1 -> 8.2
        if [[ "$version" =~ ^([0-9]+)$ ]]; then
            echo "${version}.0"
        elif [[ "$version" =~ ^([0-9]+\.[0-9]+) ]]; then
            echo "${BASH_REMATCH[1]}"
        fi
    fi
}

# Read PHP version from composer.json
read_composer_json() {
    if [[ -f "composer.json" ]]; then
        ensure_jq
        local php_require
        php_require=$(jq -r '.require.php // empty' composer.json 2>/dev/null)
        if [[ -n "$php_require" ]]; then
            parse_version_constraint "$php_require"
        fi
    fi
}

# Get required PHP version from project files
get_required_version() {
    local version=""

    # Priority 1: .php-version file
    version=$(read_php_version_file)
    if [[ -n "$version" ]]; then
        echo "$version"
        return
    fi

    # Priority 2: composer.json
    version=$(read_composer_json)
    if [[ -n "$version" ]]; then
        echo "$version"
        return
    fi
}

# Check if a specific PHP version is installed (takes X.Y format)
is_version_installed() {
    local version="$1"
    case "$DISTRO" in
        fedora)
            local remi_ver
            remi_ver=$(version_to_remi "$version")
            [[ -x "$REMI_BASE/php${remi_ver}/root/usr/bin/php" ]]
            ;;
        ubuntu)
            [[ -x "/usr/bin/php${version}" ]]
            ;;
        macos)
            [[ -x "$HOMEBREW_PREFIX/opt/php@${version}/bin/php" ]] || \
            { [[ -x "$HOMEBREW_PREFIX/opt/php/bin/php" ]] && \
              [[ "$("$HOMEBREW_PREFIX/opt/php/bin/php" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)" == "$version" ]]; }
            ;;
    esac
}

# Setup PHP_INI_SCAN_DIR for the given version (takes X.Y format)
setup_ini_scan_dir() {
    local version="$1"
    local system_conf

    case "$DISTRO" in
        fedora)
            local remi_ver
            remi_ver=$(version_to_remi "$version")
            system_conf="$REMI_BASE/php${remi_ver}/root/etc/php.d"
            ;;
        ubuntu)
            system_conf="/etc/php/${version}/cli/conf.d"
            ;;
        macos)
            system_conf="$HOMEBREW_PREFIX/etc/php/${version}/conf.d"
            ;;
    esac

    # Ensure user config directory exists
    mkdir -p "$USER_PHP_CONF"

    # Set scan dir to include both system and user configs
    export PHP_INI_SCAN_DIR="${system_conf}:${USER_PHP_CONF}"
}

# Get the PHP binary path for a given version (takes X.Y format)
get_php_binary() {
    local version="$1"
    case "$DISTRO" in
        fedora)
            local remi_ver
            remi_ver=$(version_to_remi "$version")
            echo "$REMI_BASE/php${remi_ver}/root/usr/bin/php"
            ;;
        ubuntu)
            echo "/usr/bin/php${version}"
            ;;
        macos)
            # Try versioned formula first, then unversioned
            if [[ -x "$HOMEBREW_PREFIX/opt/php@${version}/bin/php" ]]; then
                echo "$HOMEBREW_PREFIX/opt/php@${version}/bin/php"
            else
                echo "$HOMEBREW_PREFIX/opt/php/bin/php"
            fi
            ;;
    esac
}

# Main function
main() {
    local required_version
    local version
    local php_binary

    # Get required version from project files (returns X.Y format)
    required_version=$(get_required_version)

    if [[ -n "$required_version" ]]; then
        version="$required_version"

        # Install if not present
        if ! is_version_installed "$version"; then
            install_php_version "$version"
        fi
    else
        # No version specified, use latest installed
        version=$(get_latest_installed)

        # If nothing installed, install latest available
        if [[ -z "$version" ]]; then
            version=$(get_latest_available)
            install_php_version "$version"
        fi
    fi

    # Setup ini scan directory
    setup_ini_scan_dir "$version"

    # Get the binary path
    php_binary=$(get_php_binary "$version")

    # Execute PHP with all arguments
    exec "$php_binary" "$@"
}

main "$@"
