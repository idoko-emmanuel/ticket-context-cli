# demo.tape — record with: vhs demo.tape
# Install VHS:  brew install vhs  (requires ffmpeg + ttyd on macOS)
#
# HOW THIS WORKS
# VHS executes every command for real in a live terminal, captures frames,
# and assembles them into demo.gif. Nothing is mocked — tix must be installed,
# your shell must have the `tix` function loaded, and the repo you cd into
# must exist. Run from this repo's root so demo.gif lands here.
#
# BEFORE RECORDING
# 1. cd into a real project repo that is already linked (tix link LTN done)
#    and has at least one sprint ticket (e.g. LTN-42) assigned to you.
# 2. Replace MY_PROJECT_PATH below with that repo's absolute path.
# 3. tix configure should already be done; the configure section below
#    re-runs it to show the flow — use real values, Hide hides the token.

Output demo.gif

Set Shell "zsh"
Set FontSize 14
Set FontFamily "JetBrains Mono"
Set Width 1000
Set Height 580
Set Theme "Dracula"
Set Padding 20
Set WindowBar Colorful
Set BorderRadius 10
Set TypingSpeed 50ms
Set PlaybackSpeed 1.25

# ── move into a real linked project repo ──────────────────────
Type "cd ~/projects/MY_PROJECT_PATH"
Enter
Sleep 500ms

# ── tix configure ─────────────────────────────────────────────
# VHS executes this for real; prompts appear and Type fills them in.
Type "tix configure"
Enter
Sleep 1s

Type "your-org"
Enter
Sleep 600ms

Type "you@company.com"
Enter
Sleep 600ms

# Hide drops frames while the real token is typed — it never appears in the GIF.
Hide
Type "YOUR_REAL_TOKEN_HERE"
Enter
Show

# Wait for the success confirmation line before continuing.
Wait /Credentials saved/
Sleep 1s

# ── tix link ──────────────────────────────────────────────────
Type "tix link LTN"
Enter
Wait /Linked/
Sleep 1s

# ── tix sprint ────────────────────────────────────────────────
Type "tix sprint"
Enter
# Wait for the table to finish rendering (last border line).
Wait /─────/
Sleep 2s

# ── tix branch with auto-transition ──────────────────────────
Type "tix branch LTN-42 --transition=\"In Progress\""
Enter
# Wait until branch creation and context save are confirmed.
Wait /context saved/
Sleep 1.5s

# ── show what was created ─────────────────────────────────────
Type "ls .claude/skills/ticket-context/"
Enter
Sleep 1s

Type "head -20 .claude/skills/ticket-context/LTN-42-context.md"
Enter
Sleep 2.5s
