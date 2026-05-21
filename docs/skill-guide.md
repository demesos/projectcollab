# Skill Guide

## Installation
The skill ships as `projectcollab.skill` (zip format).

### Claude Code (Windows)
```powershell
Expand-Archive projectcollab.skill -DestinationPath $HOME\.claude\skills\ -Force
```
Installs to: `C:\Users\<you>\.claude\skills\projectcollab\`

### Claude.ai chat
Copy skill files to: `/mnt/skills/user/projectcollab/`

## File structure
```
projectcollab/
├── SKILL.md                    ← platform-agnostic core
├── platform-claudecode.md      ← Windows/Claude Code specifics
├── platform-chat.md            ← claude.ai chat specifics
└── references/
    ├── api-spec.md             ← full API spec
    └── curl-recipes.md         ← copy-paste curl examples
```

## Bootstrap trigger
User says: `"load projectcollab skill, connect with secret <secret>"` (no project name needed)
Legacy form still works: `"load projectcollab skill, connect to <project>, secret <secret>"`

## Bootstrap flow (from SKILL.md)
1. Read platform file (platform-claudecode.md or platform-chat.md)
2. GET / — connect with secret only; response returns project name, identity, counts
3. GET /<project>/files (first connect) or /news (returning) — using project name from step 2
4. Read design.md and relevant files
5. GET /<project>/chat?limit=50
6. Introduce yourself in chat + in conversation
7. POST /<project>/chat/seen

## Key design decisions
- Identity comes FROM server (via secret), not chosen by agent
- /news vs /files: /news is empty on fresh session — always use /files first
- Chat posting: always use temp file on Windows (inline JSON quoting is unreliable)
- Binary upload: use --data-binary @/mnt/c/... path from WSL
- Version tracking: GET file before PUT; never guess version number
- Polling skip: mode='editing' blocks all auto-refresh in UI

## Platform differences

| Feature | Claude Code | claude.ai chat |
|---------|-------------|----------------|
| JSON in curl | temp file only | single-quote OK |
| Build tools | PowerShell (oscar64 etc.) | not available |
| Binary upload | /mnt/c/... WSL path | /mnt/user-data/uploads/ |
| Skills path | %USERPROFILE%\.claude\skills\ | /mnt/skills/user/ |
| File persistence | local filesystem | sandbox resets each session |
| Memory | separate from chat | shared memory system |

## Known issues
- Windows PowerShell mangles -o= arg for oscar64: use `& oscar64 "file.c" "-o=game.prg"`
- cmd output not captured by Claude Code on Windows: use PowerShell instead
- oscar64 not in WSL PATH: run via PowerShell or cmd
- SKILL.md must be in named subfolder to be recognized by Claude Code
