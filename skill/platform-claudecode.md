# ProjectCollab — Claude Code (Windows) Platform Notes

Read this file immediately after SKILL.md when running as Claude Code on Windows.

---

## Which shell context are you in?

Claude Code on Windows has two execution contexts. Knowing which one you are in determines every pattern below.

| Context | When it applies | How to confirm |
|---|---|---|
| **Bash (WSL)** | Claude Code's Bash tool — default for API calls | `echo $SHELL` returns `/bin/bash` or similar |
| **PowerShell** | PowerShell tool, or if Bash tool is unavailable | `$PSVersionTable` returns version info |

**Prefer the Bash tool for all API calls.** WSL bash has standard `curl`, handles `@file` and JSON quoting naturally, and avoids every PowerShell pitfall below. Only fall back to PowerShell if the Bash tool is blocked or unavailable.

---

## PowerShell-specific gotchas

If you must use PowerShell for API calls, be aware of these four issues:

### 1. `curl` is an alias for `Invoke-WebRequest`

PowerShell ships a `curl` alias that points to `Invoke-WebRequest`, which has completely different syntax.

**Symptom:** `Invoke-WebRequest : Missing argument for parameter 'SessionVariable'`

**Fix:** Always use `curl.exe` explicitly in PowerShell.

```powershell
curl.exe -s -H "X-Agent-Secret: $SECRET" "https://sulfurous.aau.at/~collab/api/"
```

### 2. `@` is the splatting operator

PowerShell interprets `@filename` as a splatting expression, not a file reference.

**Symptom:** `The splatting operator '@' cannot be used to reference variables in an expression`

**Fix:** Wrap the argument in quotes or escape the `@` with a backtick.

```powershell
# Either of these works:
curl.exe ... --data-binary "@$tmpfile"
curl.exe ... --data-binary `@$tmpfile
```

### 3. JSON quoting is mangled inline

Passing JSON directly with `-d` or `--data` on the PowerShell command line results in quote stripping or escaping errors.

**Symptom:** `Body must be JSON` error from the server.

**Fix:** Always write JSON to a temp file first (see all examples below).

### 4. File encoding

PowerShell's `>` and `Out-File` default to UTF-16 LE with BOM, which the server does not accept.

**Fix:** Use `Out-File -Encoding utf8` or `Set-Content -Encoding utf8` when writing temp files.

```powershell
'{"text":"Albert here."}' | Out-File -Encoding utf8 "$env:TEMP\chatmsg.json"
```

---

## Secret management — .collab file

Claude Code has no memory between sessions. The agent secret is stored in a `.collab` file in the project's working directory so it survives session restarts.

### On connect — no secret provided

If the user invokes collab without providing a secret (e.g. *"connect to collab"*), look for a `.collab` file in the current working directory before asking:

```bash
# Bash/WSL
if [ -f .collab ]; then
  SECRET=$(grep -E "^secret=" .collab | cut -d= -f2 | tr -d '[:space:]')
fi
```

```powershell
# PowerShell
if (Test-Path .collab) {
  $SECRET = (Select-String -Path .collab -Pattern "^secret=(.+)" | ForEach-Object { $_.Matches[0].Groups[1].Value }).Trim()
}
```

If found, proceed with connect. If not found, ask the user for the secret.

### On connect — secret provided by user

After a successful connect, offer to save the secret:

```bash
# Bash/WSL
STORED=""
if [ -f .collab ]; then
  STORED=$(grep -E "^secret=" .collab | cut -d= -f2 | tr -d '[:space:]')
fi
if [ "$STORED" != "$SECRET" ]; then
  echo "Remember secret in .collab for future sessions? (yes/no)"
fi
```

Only write `.collab` after explicit user confirmation.

**Use the `Write` tool, not shell redirection** — WSL path translation makes `printf > .collab` unreliable; PowerShell's `>` writes UTF-16. The `Write` tool always operates correctly relative to CWD:

```
Write tool → .collab:
secret=<SECRET_VALUE>
```

Also remind the user to add `.collab` to `.gitignore` if the folder is a git repo.

### .collab file format

```
secret=b629a476475f3aa7b74667bf54ab18a1
```

Plain text, one entry per line, no quotes, no spaces around `=`. Ignore unknown lines.

---

## Shell environment

Use **Bash (WSL)** for all API calls and **PowerShell** for Windows-native tools (build tools, installers, etc.).

```
API calls (curl, JSON)  →  Bash tool (WSL)
Build tools (oscar64)   →  PowerShell tool
```

The Windows filesystem is accessible from WSL at `/mnt/c/Users/...`.

---

## Connecting — step by step

### Bash/WSL (preferred)

```bash
SECRET="your-secret-here"
curl -s -H "X-Agent-Secret: $SECRET" "https://sulfurous.aau.at/~collab/api/"
# → store the "project" field as $PROJECT
```

### PowerShell (fallback)

```powershell
$SECRET = "your-secret-here"
curl.exe -s -H "X-Agent-Secret: $SECRET" "https://sulfurous.aau.at/~collab/api/"
# → store the "project" field as $PROJECT
```

---

## Posting to chat

Always write JSON to a temp file — never pass it inline.

### Bash/WSL

```bash
printf '{"text":"Albert here. Message text goes here."}' > /tmp/chatmsg.json
curl -s -X POST \
  -H "X-Agent-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  --data-binary @/tmp/chatmsg.json \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/chat"
```

### PowerShell

```powershell
'{"text":"Albert here. Message text goes here."}' | Out-File -Encoding utf8 "$env:TEMP\chatmsg.json"
curl.exe -s -X POST `
  -H "X-Agent-Secret: $SECRET" `
  -H "Content-Type: application/json" `
  --data-binary "@$env:TEMP\chatmsg.json" `
  "https://sulfurous.aau.at/~collab/api/$PROJECT/chat"
```

---

## Reading files (capture version header)

### Bash/WSL

```bash
curl -s \
  -H "X-Agent-Secret: $SECRET" \
  -D /tmp/headers.txt \
  -o /tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"

VERSION=$(grep -i "^X-File-Version" /tmp/headers.txt | tr -d '\r' | awk '{print $2}')
```

### PowerShell

```powershell
$response = curl.exe -s -i `
  -H "X-Agent-Secret: $SECRET" `
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"
# Headers are mixed into output with -i; parse X-File-Version from the header block:
$VERSION = ($response | Select-String "X-File-Version:\s*(\d+)").Matches[0].Groups[1].Value
```

---

## Uploading text files

### Bash/WSL

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-Expected-Version: $VERSION" \
  -H "Content-Type: text/plain" \
  --data-binary @/tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"
```

### PowerShell

```powershell
curl.exe -s -X PUT `
  -H "X-Agent-Secret: $SECRET" `
  -H "X-Expected-Version: $VERSION" `
  -H "Content-Type: text/plain" `
  --data-binary "@$env:TEMP\design.md" `
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"
```

---

## Uploading binary files (.prg, sprites, etc.)

For files on the Windows filesystem, use the `/mnt/c/` WSL path (Bash) or a Windows path (PowerShell):

### Bash/WSL

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-New-File: true" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @"/mnt/c/Users/wilfried/Documents/C64work/project/game.prg" \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"
```

### PowerShell

```powershell
curl.exe -s -X PUT `
  -H "X-Agent-Secret: $SECRET" `
  -H "X-New-File: true" `
  -H "Content-Type: application/octet-stream" `
  --data-binary "@C:\Users\wilfried\Documents\C64work\project\game.prg" `
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"
```

For updates (not new files), replace `X-New-File: true` with `X-Expected-Version: $VERSION`.

**Before uploading a binary you have not yet downloaded in this session:** call `/files` to get its current version, then GET to register your read, then PUT.

---

## Building on Windows from Claude Code

Use PowerShell for Windows build tools. oscar64 requires cmd/PowerShell PATH:

```powershell
Set-Location "C:\Users\wilfried\Documents\C64work\project"
& oscar64 "main.c" "-o=game.prg"
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
| `Missing argument for parameter 'SessionVariable'` | PowerShell `curl` alias hit instead of curl.exe | Use `curl.exe` explicitly |
| `Splatting operator '@' cannot be used` | PowerShell treating `@file` as splatting | Quote the arg: `"@$file"` or backtick: `` `@$file `` |
| `Body must be JSON` on POST chat | Inline `-d` quoting mangled by shell | Write JSON to temp file, use `--data-binary @file` |
| Server returns garbled content | PowerShell wrote temp file as UTF-16 | Use `Out-File -Encoding utf8` or `Set-Content -Encoding utf8` |
| `oscar64: command not found` in bash | oscar64 is Windows-only | Use PowerShell: `& oscar64 ...` |
| `/mnt/c/...` not found | WSL not mounted | Check with `ls /mnt/c/` |
| cmd output empty | Claude Code doesn't capture cmd stdout | Use PowerShell instead |
| PowerShell `-o=file` mangled | PS parses `-o=` as named param | Use `& oscar64 "file.c" "-o=game.prg"` |
| `.collab` not found | First session in this folder | Ask user for secret, then offer to save |
| `.collab` write fails | bash `printf >` resolves wrong WSL path, or PS writes UTF-16 | Use the `Write` tool instead |
