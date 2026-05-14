#!/usr/bin/env bash
# tylio — automated bootstrap of PHP/Composer/Node/SQLite on Ubuntu/Debian.
#
# What this does (in this order):
#   1. Detect distro codename (focal/jammy/noble/bookworm/trixie).
#   2. Add the Sury PHP PPA (packages.sury.org/php) and install PHP 8.3 +
#      all extensions tylio needs.
#   3. Install Composer 2.x via the OFFICIAL installer (apt's composer
#      package pulls the distro's PHP and breaks the toolchain).
#   4. Install Node 20.x via the NodeSource setup script.
#   5. Install the sqlite3 CLI (separate from php8.3-sqlite3).
#
# Re-runnable. Each step skips work that's already done.
#
# Flags:
#   --check     Dry-run: print what's installed/missing, change nothing.
#   --php-ver=X Install php8.X instead of php8.3.
#   --help      Show this header.

set -euo pipefail

PHP_VER="8.3"
DRY_RUN=0

# Parse flags
for arg in "$@"; do
    case "$arg" in
        --check)
            DRY_RUN=1
            ;;
        --php-ver=*)
            PHP_VER="${arg#--php-ver=}"
            ;;
        -h|--help)
            sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unknown flag: $arg" >&2
            exit 2
            ;;
    esac
done

# Colors (no-op if not a TTY)
if [[ -t 1 ]]; then
    BOLD=$'\e[1m'; DIM=$'\e[2m'; RED=$'\e[31m'; GRN=$'\e[32m'; YLW=$'\e[33m'; CLR=$'\e[0m'
else
    BOLD=""; DIM=""; RED=""; GRN=""; YLW=""; CLR=""
fi

say()  { printf '%s==>%s %s\n' "$BOLD" "$CLR" "$*"; }
ok()   { printf '  %s✓%s %s\n' "$GRN" "$CLR" "$*"; }
warn() { printf '  %s!%s %s\n' "$YLW" "$CLR" "$*"; }
err()  { printf '  %s✗%s %s\n' "$RED" "$CLR" "$*" >&2; }

# Need root unless --check
if [[ "$DRY_RUN" -eq 0 && "$(id -u)" -ne 0 ]]; then
    err "This script needs root for install. Re-run with sudo, or pass --check to dry-run."
    exit 1
fi

# Distro detection
if [[ ! -r /etc/os-release ]]; then
    err "Can't read /etc/os-release — this script supports Ubuntu/Debian only."
    exit 1
fi
. /etc/os-release
DISTRO_ID="${ID:-unknown}"
CODENAME="${VERSION_CODENAME:-${UBUNTU_CODENAME:-}}"
if [[ -z "$CODENAME" ]]; then
    err "Could not detect distro codename (VERSION_CODENAME empty)."
    exit 1
fi
case "$DISTRO_ID" in
    ubuntu|debian) ;;
    *)
        err "Unsupported distro: $DISTRO_ID. This script targets Ubuntu/Debian."
        exit 1
        ;;
esac

say "Detected: $DISTRO_ID $CODENAME"
say "Target PHP: $PHP_VER  |  Mode: $([[ $DRY_RUN -eq 1 ]] && echo 'DRY-RUN (--check)' || echo 'install')"

# ---------- helpers ----------

apt_run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '  %s[dry-run]%s apt %s\n' "$DIM" "$CLR" "$*"
    else
        DEBIAN_FRONTEND=noninteractive apt-get -y "$@"
    fi
}

pkg_installed() {
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"
}

ensure_pkg() {
    local pkg="$1"
    if pkg_installed "$pkg"; then
        ok "$pkg already installed"
    else
        if [[ "$DRY_RUN" -eq 1 ]]; then
            warn "$pkg MISSING (would install)"
        else
            warn "Installing $pkg…"
            apt_run install -y "$pkg" >/dev/null
            ok "$pkg installed"
        fi
    fi
}

# ---------- 1. Sury PPA ----------

setup_sury() {
    say "Step 1/5: Sury PHP repository"
    local keyring="/usr/share/keyrings/deb.sury.org-php.gpg"
    local list="/etc/apt/sources.list.d/sury-php.list"

    if [[ -f "$keyring" && -f "$list" ]]; then
        ok "Sury PHP repo already configured"
        return
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        warn "Sury PHP repo NOT configured (would add $list + $keyring)"
        return
    fi

    apt_run update >/dev/null
    ensure_pkg curl
    ensure_pkg ca-certificates
    ensure_pkg lsb-release
    ensure_pkg apt-transport-https
    ensure_pkg gnupg

    warn "Adding Sury archive keyring + repo for $CODENAME…"
    local tmpdeb="/tmp/debsuryorg-archive-keyring.deb"
    curl -sSLo "$tmpdeb" "https://packages.sury.org/debsuryorg-archive-keyring.deb"
    dpkg -i "$tmpdeb" >/dev/null
    rm -f "$tmpdeb"
    echo "deb [signed-by=$keyring] https://packages.sury.org/php/ $CODENAME main" > "$list"
    apt_run update >/dev/null
    ok "Sury PHP repo configured"
}

# ---------- 2. PHP + extensions ----------

setup_php() {
    say "Step 2/5: PHP $PHP_VER + required extensions"
    local pkgs=(
        "php${PHP_VER}"
        "php${PHP_VER}-cli"
        "php${PHP_VER}-fpm"
        "php${PHP_VER}-sqlite3"
        "php${PHP_VER}-gd"
        "php${PHP_VER}-zip"
        "php${PHP_VER}-mbstring"
        "php${PHP_VER}-xml"
        "php${PHP_VER}-curl"
        "php${PHP_VER}-intl"
    )
    for p in "${pkgs[@]}"; do
        ensure_pkg "$p"
    done

    # Verify the cli sees the extensions we just installed
    if command -v "php${PHP_VER}" >/dev/null 2>&1; then
        local missing=()
        for ext in pdo_sqlite gd zip mbstring sodium fileinfo json curl intl; do
            if ! "php${PHP_VER}" -m 2>/dev/null | grep -iqx "$ext"; then
                missing+=("$ext")
            fi
        done
        if [[ ${#missing[@]} -eq 0 ]]; then
            ok "All required PHP extensions loaded"
        else
            warn "PHP extensions NOT loaded by php${PHP_VER}: ${missing[*]}"
        fi
    fi
}

# ---------- 3. Composer (official) ----------

setup_composer() {
    say "Step 3/5: Composer (official installer)"

    if command -v composer >/dev/null 2>&1; then
        local ver
        ver=$(composer --version 2>/dev/null | head -n1 || true)
        # Composer installed via apt has php-cli as a dependency and ends up
        # using whatever PHP apt installed. We test it's a 2.x by string.
        if [[ "$ver" =~ "Composer version 2." ]]; then
            ok "Composer 2.x present: $ver"
            # Detect the apt-managed composer (broken on Sury layouts)
            if dpkg-query -W -f='${Status}' composer 2>/dev/null | grep -q "install ok installed"; then
                warn "apt-managed 'composer' package detected — this can pin Composer to the distro's PHP."
                warn "If 'composer install' fails with missing extensions, remove apt's composer and re-run this script."
            fi
            return
        else
            warn "Composer present but not 2.x: $ver — will reinstall"
        fi
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        warn "Composer MISSING (would install via getcomposer.org installer)"
        return
    fi

    # Run the installer with the SAME PHP we just provisioned so the shebang
    # points to a working binary, not /usr/bin/php (which may be a different
    # version or missing extensions).
    local php_bin="$(command -v "php${PHP_VER}" || command -v php)"
    if [[ -z "$php_bin" ]]; then
        err "No php binary on PATH after install — bailing."
        exit 1
    fi
    warn "Installing Composer 2.x with $php_bin…"
    "$php_bin" -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
    "$php_bin" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
    ok "Composer installed: $(composer --version 2>/dev/null | head -n1)"
}

# ---------- 4. Node 20+ ----------

setup_node() {
    say "Step 4/5: Node 20+ (NodeSource)"
    local need_install=1
    if command -v node >/dev/null 2>&1; then
        local node_ver_major
        node_ver_major=$(node --version 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || echo 0)
        if [[ "$node_ver_major" -ge 20 ]]; then
            ok "Node $(node --version) already installed (>= 20)"
            need_install=0
        else
            warn "Node $(node --version) present but < 20 — will upgrade"
        fi
    fi

    if [[ "$need_install" -eq 0 ]]; then
        return
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        warn "Node 20+ MISSING (would install via NodeSource setup_20.x)"
        return
    fi

    ensure_pkg curl
    warn "Running NodeSource setup_20.x…"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt_run install -y nodejs >/dev/null
    ok "Node installed: $(node --version)  npm: $(npm --version)"
}

# ---------- 5. sqlite3 CLI ----------

setup_sqlite_cli() {
    say "Step 5/5: sqlite3 CLI"
    ensure_pkg sqlite3
}

# ---------- run ----------

setup_sury
setup_php
setup_composer
setup_node
setup_sqlite_cli

if [[ "$DRY_RUN" -eq 1 ]]; then
    say "Dry-run complete. Re-run without --check to install."
else
    say "All set. Next:"
    cat <<EOF
  cd \$YOUR_TYLIO_DIR
  composer install --no-dev --optimize-autoloader
  cd admin-src && npm install && npm run build && cd ..
  sudo mkdir -p data data/sessions data/logs uploads favicons
  sudo chown -R www-data:www-data data uploads favicons
  sudo chmod -R 770 data uploads favicons
  cp .env.example .env  # then edit APP_URL and APP_KEY
  # Visit https://your-domain.example/install
EOF
fi
