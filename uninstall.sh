#!/usr/bin/env bash
set -euo pipefail

# ─────────────────────────────────────────────
#  ticket-context-cli — uninstaller
# ─────────────────────────────────────────────

INSTALL_DIR="$HOME/.local/share/ticket-context-cli"
CONFIG_DIR="$HOME/.config/ticket-context"

# ── helpers ──────────────────────────────────

info()    { echo "  → $*"; }
success() { echo "  ✓ $*"; }

confirm() {
  local prompt="$1"
  read -r -p "  $prompt [y/N] " reply
  [[ "$reply" == [yY] ]]
}

# ── 1. Remove app directory ──────────────────

if [[ -d "$INSTALL_DIR" ]]; then
  info "Removing $INSTALL_DIR ..."
  rm -rf "$INSTALL_DIR"
  success "App directory removed"
else
  info "App directory not found at $INSTALL_DIR — skipping"
fi

# ── 2. Remove shell function ─────────────────

remove_from_rc() {
  local rc_file="$1"
  [[ -f "$rc_file" ]] || return 0

  if grep -q 'ticket-context-cli' "$rc_file"; then
    # Remove the comment line, unalias line, and the tix() function block
    perl -i -0pe 's/\n# ticket-context-cli[^\n]*\nunalias tix[^\n]*\ntix\(\) \{[^}]*\}//g' "$rc_file"
    success "Shell function removed from $rc_file"
  else
    info "No shell function found in $rc_file — skipping"
  fi
}

remove_from_rc "$HOME/.zshrc"
remove_from_rc "$HOME/.bashrc"

# ── 3. Optionally remove credentials ─────────

if [[ -d "$CONFIG_DIR" ]]; then
  echo ""
  if confirm "Also remove Jira credentials at $CONFIG_DIR?"; then
    rm -rf "$CONFIG_DIR"
    success "Credentials removed"
  else
    info "Credentials kept at $CONFIG_DIR"
  fi
fi

# ── Done ─────────────────────────────────────

echo ""
echo "  Uninstall complete. Reload your shell to remove the tix command:"
echo "    source ~/.zshrc   (or ~/.bashrc)"
echo ""
