# ticket-context-cli

A personal CLI tool that pulls your active Jira sprint tickets and generates rich Markdown context files optimised for pasting into Claude Code (or any AI coding assistant).

Run it from **any project repo** on your machine. It automatically scopes to the right Jira project and git branch.

---

## How it works

```text
~/.config/ticket-context/config.json              ← your Jira credentials (one-time setup)
{project-root}/.ticket-context.json               ← links this repo to a Jira project key
{project-root}/.claude/skills/ticket-context/     ← SKILL.md + generated context .md files
```

---

## Requirements

- PHP 8.2+ — [php.net/downloads](https://php.net/downloads) or via your package manager
- Composer — [getcomposer.org/download](https://getcomposer.org/download) (the install script handles this automatically)
- Git

---

## Installation

### One-line install

The install script checks for PHP, installs Composer if it's missing, clones the repo, sets everything up, and adds the `tix` shell function automatically.

```bash
curl -fsSL https://raw.githubusercontent.com/idoko-emmanuel/ticket-context-cli/main/install.sh | bash
```

Then reload your shell:

```bash
source ~/.zshrc   # or ~/.bashrc
```

### Manual install

If you prefer to run each step yourself:

#### 1. Install Composer (skip if already installed)

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php && rm composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
```

#### 2. Clone and install

```bash
git clone https://github.com/idoko-emmanuel/ticket-context-cli.git ~/.local/share/ticket-context-cli
cd ~/.local/share/ticket-context-cli
composer install --no-dev
cp .env.example .env
php artisan key:generate
```

#### 3. Add the global shell function

Add this to your `~/.zshrc` or `~/.bashrc`:

```bash
# ticket-context-cli — global tix command
unalias tix 2>/dev/null
tix() {
  local cmd="${1:-}"
  if [[ -z "$cmd" || "$cmd" == "--help" || "$cmd" == "-h" || "$cmd" == "help" ]]; then
    php ~/.local/share/ticket-context-cli/artisan tix:help
  else
    php ~/.local/share/ticket-context-cli/artisan "tix:${cmd}" "${@:2}"
  fi
}
```

Then reload:

```bash
source ~/.zshrc
```

You can now run `tix <command>` from any directory on your machine.

---

## Uninstallation

```bash
curl -fsSL https://raw.githubusercontent.com/idoko-emmanuel/ticket-context-cli/main/uninstall.sh | bash
```

This will:

- Remove `~/.local/share/ticket-context-cli`
- Remove the `tix` shell function from `~/.zshrc` / `~/.bashrc`
- Prompt whether to also delete your saved Jira credentials (`~/.config/ticket-context/`)

---

## Setup

### Configure your Jira credentials

```bash
tix configure
```

You will be prompted for:

| Prompt | Example | Where to find it |
| --- | --- | --- |
| Atlassian subdomain | `your-org` | Your Jira URL: `https://your-org.atlassian.net` |
| Jira account email | `you@company.com` | The email you log into Jira with |
| API token | `ATATTx...` | [id.atlassian.com/manage-profile/security/api-tokens](https://id.atlassian.com/manage-profile/security/api-tokens) |

Credentials are saved to `~/.config/ticket-context/config.json` with `chmod 600` — never inside any project repo.

### Verify the connection

```bash
tix health
```

Shows your credentials status, live connection test, and current project link.

---

## Per-project setup

Run once from the root of each project repo you want to track:

```bash
cd ~/projects/my-app
tix link LTN
```

This creates `.ticket-context.json` in the repo root and adds `.claude/skills/ticket-context/*-context.md` to `.gitignore`.

Your project key is the prefix of every ticket — e.g. `LTN` from `LTN-42`. You can also find it in your Jira board URL:

```text
https://your-org.atlassian.net/jira/software/c/projects/LTN/boards/34
                                                              ^^^
```

---

## Daily workflow

### 1. See your active sprint tickets

```bash
tix sprint
```

Shows a table of tickets assigned to you in the current sprint, filtered to the linked project. Use `--all` to see across all projects, `--json` for raw output.

### 2. Start a ticket — create a branch and save context

```bash
tix branch LTN-42
```

This will:

- Create git branch `feature/ltn-42-{kebab-slug-of-summary}`
- Link the ticket to that branch in `.ticket-context.json`
- Fetch the full ticket from Jira and save it to `.claude/skills/ticket-context/LTN-42-context.md`
- Create `.claude/skills/ticket-context/SKILL.md` so Claude loads the context automatically

#### Also transition the ticket when branching

```bash
tix branch LTN-42 --transition="In Progress"
```

#### Link multiple tickets to one branch

```bash
tix branch LTN-42 LTN-43 LTN-44
```

All tickets are linked to the branch. A combined context file is saved with each ticket as a separate section.

#### Skip branch creation (just save context)

```bash
tix branch LTN-42 --no-branch
```

### 3. Generate or refresh context

```bash
tix context
```

With no arguments, auto-detects tickets from the current git branch, saves the context to `.claude/skills/ticket-context/` and prints a confirmation. Explicit keys work the same way:

```bash
tix context LTN-42
tix context LTN-42 LTN-43
```

Optionally transition at the same time:

```bash
tix context --transition="In Review"
```

### 4. Move a ticket to a different status

```bash
tix move In Progress
```

Auto-detects tickets from the current branch and moves all of them. You can also pass explicit keys — no quotes needed for multi-word statuses:

```bash
tix move LTN-42 In Review
tix move LTN-42 LTN-43 Done
```

### 5. Check available statuses

```bash
tix statuses
```

Lists all statuses available in the linked project — useful when you're not sure of the exact name to pass to `--transition` or `tix move`.

---

## Claude Code integration

A Claude skill file is automatically written to `.claude/skills/ticket-context/SKILL.md` the first time any `tix` command is run in a linked project. Claude Code reads skills from `.claude/skills/` at session start, so ticket context is loaded automatically — no manual configuration needed.

### Manual use

You can also reference a context file explicitly at any time:

```text
@.claude/skills/ticket-context/LTN-42-context.md implement the feature described in this ticket
```

---

## Command reference

| Command | Description |
| --- | --- |
| `tix help` | Show all commands and their options |
| `tix configure` | Set Jira credentials interactively |
| `tix health` | Check config status and test connection |
| `tix link <PROJECT-KEY>` | Link current directory to a Jira project |
| `tix sprint` | List active sprint tickets (scoped to linked project) |
| `tix sprint --all` | List active sprint tickets across all projects |
| `tix sprint --json` | Output raw JSON |
| `tix branch <KEY> [KEY...]` | Create branch, link ticket(s), save context file(s) |
| `tix branch <KEY> --no-branch` | Skip branch creation, only save context |
| `tix branch <KEY> --transition=STATUS` | Also transition ticket(s) to a status |
| `tix context` | Fetch and save context for current branch's linked tickets |
| `tix context <KEY> [KEY...]` | Fetch and save context for specific ticket(s) |
| `tix context --transition=STATUS` | Also transition ticket(s) to a status |
| `tix move [KEY...] <STATUS>` | Move ticket(s) to a status (auto-detects from branch) |
| `tix statuses [PROJECT]` | List available statuses for the project |

---

## File reference

| File | Purpose | Committed? |
| --- | --- | --- |
| `~/.config/ticket-context/config.json` | Global Jira credentials | Never |
| `.ticket-context.json` | Project key + branch→ticket mappings | No |
| `.claude/skills/ticket-context/SKILL.md` | Claude skill definition (auto-generated) | No |
| `.claude/skills/ticket-context/*-context.md` | Generated context Markdown files | No (gitignored) |

---

## Contributing

1. Create a feature branch from `main`:

    ```bash
    git checkout main
    git pull
    git checkout -b feat/your-feature-name
    ```

2. Make your changes and commit them.

3. Push your branch and open a pull request against `main`:

    ```bash
    git push -u origin feat/your-feature-name
    ```

4. Ensure the CI checks (lint, unit tests, feature tests) pass before requesting a review.

---

## License

MIT
