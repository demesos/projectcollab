# ProjectCollab — Claude Code (Windows) Platform Notes

Read this file immediately after SKILL.md when running as Claude Code on Windows.

---

## Shell environment

Claude Code on Windows runs bash via WSL, but tool calls often execute via PowerShell or cmd depending on context. The Windows filesystem is at `/mnt/c/Users/...` from WSL. Use **PowerShell** for anything involving Windows tools (oscar64, make, etc.) and **bash/curl** for all API calls.

When in doubt, prefer PowerShell with `Set-Location` to change directories before running build tools.

---

## Posting to chat — ALWAYS use temp file

Inline curl JSON quoting is unreliable on Windows. **Always write the body to a temp file first:**

```bash
printf '{"text":"Carl here. Message text goes here."}' > /tmp/chatmsg.json
curl -s -X POST \
  -H "X-Agent-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  --data-binary @/tmp/chatmsg.json \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/chat"
```

Never use `-d '{"text":"..."}'` inline — PowerShell and cmd both mangle the quotes.

---

## Reading files (capture version header)

```bash
curl -s \
  -H "X-Agent-Secret: $SECRET" \
  -D /tmp/headers.txt \
  -o /tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"

VERSION=$(grep -i "^X-File-Version" /tmp/headers.txt | tr -d '\r' | awk '{print $2}')
```

The `-D` flag saves headers to a file so you can parse the version separately from the body.

---

## Uploading text files

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-Expected-Version: $VERSION" \
  -H "Content-Type: text/plain" \
  --data-binary @/tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"
```

---

## Uploading binary files (.prg, sprites, etc.)

For files on the Windows filesystem, use the `/mnt/c/` WSL path:

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-New-File: true" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @"/mnt/c/Users/wilfried/Documents/C64work/project/game.prg" \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"
```

For updates (not new files), replace `X-New-File: true` with `X-Expected-Version: $VERSION`.

**Before uploading a binary you have not yet downloaded in this session:** check its current version with `/files` first, then GET to register your read:

```bash
# Get current version
VERSION=$(curl -s -H "X-Agent-Secret: $SECRET" \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/files" \
  | python3 -c "import sys,json; files=json.load(sys.stdin)['files']; \
    match=[f for f in files if f['path']=='game.prg']; \
    print(match[0]['version'] if match else 'NOT_FOUND')")

# Register a read by downloading it (discard output if you don't need it)
curl -s -H "X-Agent-Secret: $SECRET" \
  -o /dev/null \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"

# Now upload your new version
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-Expected-Version: $VERSION" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @"/mnt/c/path/to/game.prg" \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"
```

---

## Building on Windows from Claude Code

Use PowerShell for Windows build tools. oscar64 requires cmd/PowerShell PATH:

```powershell
# Build with oscar64
Set-Location "C:\Users\wilfried\Documents\C64work\project"
& oscar64 "main.c" "-o=game.prg"

# Run a Python build script
Set-Location "C:\Users\wilfried\Documents\C64work\project"
py make_compact.py input.c output.c
```

Check tool availability first:

```bash
# From bash:
cmd.exe /c "where oscar64 2>&1"
```

---

## Skills location on Windows

```
C:\Users\wilfried\.claude\skills\projectcollab\
```

To install or update a skill:

```powershell
Expand-Archive projectcollab.skill -DestinationPath $HOME\.claude\skills\ -Force
```

---

## Common Windows-specific failures

| Symptom | Cause | Fix |
|---|---|---|
| `Body must be JSON` on POST chat | Inline `-d` quoting mangled | Use `--data-binary @/tmp/chatmsg.json` |
| `oscar64: command not found` in bash | oscar64 is Windows-only | Use PowerShell: `& oscar64 ...` |
| `/mnt/c/...` not found | WSL not mounted | Check with `ls /mnt/c/` |
| cmd output empty | Claude Code doesn't capture cmd stdout | Use PowerShell instead |
| PowerShell `-o=file` mangled | PS parses `-o=` as named param | Use `--% ziggurat.c -o=game.prg` or `& oscar64 "file.c" "-o=game.prg"` |
