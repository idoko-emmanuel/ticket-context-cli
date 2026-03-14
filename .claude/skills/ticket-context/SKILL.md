---
name: ticket-context
description: "Loads Jira ticket context from .ticket-context/ at session start to inform feature work."
license: MIT
metadata:
  author: ticket-context-cli
---

# Ticket Context

At the start of a session, check if any `*-context.md` files exist in
`.claude/skills/ticket-context/`. If found, read them — they describe the current ticket(s)
being worked on for this branch.
