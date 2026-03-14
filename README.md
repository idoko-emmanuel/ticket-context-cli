# ticket-context-cli

A personal CLI tool that pulls your active Jira sprint tickets and generates rich Markdown context files optimised for pasting into Claude Code (or any AI coding assistant).

Run it from **any project repo** on your machine. It automatically scopes to the right Jira project and git branch.

---

## How it works

```text
~/.config/ticket-context/config.json   ← your Jira credentials (one-time setup)
{project-root}/.ticket-context.json    ← links this repo to a Jira project key
{project-root}/.ticket-context/        ← generated context .md files (gitignored)
```

---

## Requirements

- PHP 8.2+
- Composer
- Git

---

## Installation

### 1. Clone and install

```bash
git clone https://github.com/idoko-emmanuel/ticket-context-cli.git ~/ticket-context-cli
cd ~/ticket-context-cli
composer install --no-dev
cp .env.example .env
php artisan key:generate
```

### 2. Add the global shell function

Add this to your `~/.zshrc` or `~/.bashrc`:

```bash
# ticket-context-cli — global spool command
unalias spool 2>/dev/null
spool() { php ~/ticket-context-cli/artisan "spool:${1}" "${@:2}"; }
```

Then reload:

```bash
source ~/.zshrc
```

You can now run `spool <command>` from any directory on your machine.

---

## Setup

### Configure your Jira credentials

```bash
spool configure
```

You will be prompted for:

| Prompt | Example | Where to find it |
| --- | --- | --- |
| Atlassian subdomain | `lantansquad` | Your Jira URL: `https://lantansquad.atlassian.net` |
| Jira account email | `you@company.com` | The email you log into Jira with |
| API token | `ATATTx...` | [id.atlassian.com/manage-profile/security/api-tokens](https://id.atlassian.com/manage-profile/security/api-tokens) |

Credentials are saved to `~/.config/ticket-context/config.json` with `chmod 600` — never inside any project repo.

### Verify the connection

```bash
spool health
```

Shows your credentials status, live connection test, and current project link.

---

## Per-project setup

Run once from the root of each project repo you want to track:

```bash
cd ~/projects/my-app
spool link LTN
```

This creates `.ticket-context.json` in the repo root and adds `.ticket-context/` to `.gitignore`.

Your project key is the prefix of every ticket — e.g. `LTN` from `LTN-42`. You can also find it in your Jira board URL:

```text
https://lantansquad.atlassian.net/jira/software/c/projects/LTN/boards/34
                                                              ^^^
```

---

## Daily workflow

### 1. See your active sprint tickets

```bash
spool sprint
```

Shows a table of tickets assigned to you in the current sprint, filtered to the linked project. Use `--all` to see across all projects, `--json` for raw output.

### 2. Start a ticket — create a branch and save context

```bash
spool branch LTN-42
```

This will:

- Create git branch `feature/ltn-42-{kebab-slug-of-summary}`
- Link the ticket to that branch in `.ticket-context.json`
- Fetch the full ticket from Jira and save it to `.ticket-context/LTN-42-context.md`

#### Link multiple tickets to one branch

```bash
spool branch LTN-42 LTN-43 LTN-44
```

All tickets are linked to the branch. A combined context file is saved with each ticket as a separate section.

#### Skip branch creation (just save context)

```bash
spool branch LTN-42 --no-branch
```

### 3. Generate or refresh context

```bash
spool context
```

With no arguments, auto-detects tickets from the current git branch and outputs Markdown to stdout. Use `--file` to also save/refresh the `.md` file.

Explicit keys still work:

```bash
spool context LTN-42
spool context LTN-42 LTN-43 --file
```

---

## Claude Code integration

Reference the context file directly in your Claude session:

```text
@.ticket-context/LTN-42-context.md implement the feature described in this ticket
```

To make it automatic for every project, add to your `~/.claude/CLAUDE.md`:

```markdown
## Ticket Context
At the start of a session, check if any `*-context.md` files exist in
`.ticket-context/`. If found, read them — they describe the current ticket(s)
being worked on for this branch.
```

---

## Command reference

| Command | Description |
| --- | --- |
| `spool configure` | Set Jira credentials interactively |
| `spool health` | Check config status and test connection |
| `spool link <PROJECT-KEY>` | Link current directory to a Jira project |
| `spool sprint` | List active sprint tickets (scoped to linked project) |
| `spool sprint --all` | List active sprint tickets across all projects |
| `spool sprint --json` | Output raw JSON |
| `spool branch <KEY> [KEY...]` | Create branch, link ticket(s), save context file(s) |
| `spool branch <KEY> --no-branch` | Skip branch creation, only save context |
| `spool context` | Output context for current branch's linked tickets |
| `spool context <KEY> [KEY...]` | Output context for specific ticket(s) |
| `spool context <KEY> --file` | Output and save to `.ticket-context/` |

---

## File reference

| File | Purpose | Committed? |
| --- | --- | --- |
| `~/.config/ticket-context/config.json` | Global Jira credentials | Never |
| `.ticket-context.json` | Project key + branch→ticket mappings | Yes (optional) |
| `.ticket-context/` | Generated context Markdown files | No (gitignored) |

---

## License

MIT
