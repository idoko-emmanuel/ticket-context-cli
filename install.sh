#!/usr/bin/env bash
set -euo pipefail

# ─────────────────────────────────────────────
#  ticket-context-cli — one-time installer
# ─────────────────────────────────────────────

INSTALL_DIR="$HOME/ticket-context-cli"
REPO_URL="https://github.com/idoko-emmanuel/ticket-context-cli.git"

# ── helpers ──────────────────────────────────

info()    { echo "  → $*"; }
success() { echo "  ✓ $*"; }
fail()    { echo "  ✗ $*" >&2; exit 1; }

require_cmd() {
  command -v "$1" &>/dev/null || fail "$1 is required but not found. $2"
}

# ── 1. Check Git ──────────────────────────────

require_cmd git "Install it from https://git-scm.com"

# ── 2. Check PHP 8.2+ ────────────────────────

require_cmd php "Install it from https://php.net/downloads or via your package manager."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
PHP_MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [[ "$PHP_MAJOR" -lt 8 || ( "$PHP_MAJOR" -eq 8 && "$PHP_MINOR" -lt 2 ) ]]; then
  fail "PHP 8.2+ is required (found $PHP_VERSION). See https://php.net/downloads"
fi

success "PHP $PHP_VERSION"

# ── 3. Ensure Composer ───────────────────────

if command -v composer &>/dev/null; then
  success "Composer $(composer --version --no-ansi 2>/dev/null | awk '{print $3}')"
else
  info "Composer not found — installing to /usr/local/bin/composer ..."

  EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

  if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
    rm -f composer-setup.php
    fail "Composer installer checksum mismatch — aborting for security."
  fi

  php composer-setup.php --quiet
  rm -f composer-setup.php

  if [[ -w /usr/local/bin ]]; then
    mv composer.phar /usr/local/bin/composer
  else
    sudo mv composer.phar /usr/local/bin/composer
  fi

  success "Composer installed"
fi

# ── 4. Clone or update the repo ──────────────

if [[ -d "$INSTALL_DIR/.git" ]]; then
  info "Repo already exists at $INSTALL_DIR — pulling latest ..."
  git -C "$INSTALL_DIR" pull --ff-only
else
  info "Cloning into $INSTALL_DIR ..."
  git clone "$REPO_URL" "$INSTALL_DIR"
fi

success "Repo ready at $INSTALL_DIR"

# ── 5. Install PHP dependencies ──────────────

info "Installing dependencies ..."
composer install --no-dev --no-interaction --working-dir="$INSTALL_DIR"
success "Dependencies installed"

# ── 6. App key ───────────────────────────────

if [[ ! -f "$INSTALL_DIR/.env" ]]; then
  cp "$INSTALL_DIR/.env.example" "$INSTALL_DIR/.env"
fi

php "$INSTALL_DIR/artisan" key:generate --force
success "App key set"

# ── 7. Shell function ────────────────────────

SHELL_FUNCTION='
# ticket-context-cli — global tix command
unalias tix 2>/dev/null
tix() {
  local cmd="${1:-}"
  if [[ -z "$cmd" || "$cmd" == "--help" || "$cmd" == "-h" || "$cmd" == "help" ]]; then
    php ~/ticket-context-cli/artisan tix:help
  else
    php ~/ticket-context-cli/artisan "tix:${cmd}" "${@:2}"
  fi
}'

add_to_rc() {
  local rc_file="$1"
  if grep -q 'ticket-context-cli' "$rc_file" 2>/dev/null; then
    info "Shell function already present in $rc_file — skipping"
  else
    printf '\n%s\n' "$SHELL_FUNCTION" >> "$rc_file"
    success "Shell function added to $rc_file"
  fi
}

if [[ "${SHELL:-}" == *zsh* ]]; then
  add_to_rc "$HOME/.zshrc"
elif [[ "${SHELL:-}" == *bash* ]]; then
  add_to_rc "$HOME/.bashrc"
else
  # Write to both if we can't tell
  add_to_rc "$HOME/.zshrc"
  add_to_rc "$HOME/.bashrc"
fi

# ── Done ─────────────────────────────────────

echo ""
echo "  Installation complete."
echo ""
echo "  Reload your shell, then run:"
echo "    source ~/.zshrc   (or ~/.bashrc)"
echo "    tix configure     ← enter your Jira credentials"
echo "    tix health        ← verify the connection"
echo ""
