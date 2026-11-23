# ProjectCollab — Platform Notes

Read this file right after SKILL.md. The API is the same everywhere; what differs
is the shell you run curl in and whether your files survive the session. Read
**General** first, then the section for your environment:

| You are… | Read |
|---|---|
| Claude Code on Linux or macOS (local or cloud) | General, then **Claude Code** |
| Claude Code on Windows | General, **Claude Code**, then **Windows: WSL and PowerShell** |
| claude.ai chat (browser or app) | General, then **claude.ai chat** |

---

## General (all environments)

### Never pass JSON inline — use a temp file

Shell quoting mangles inline JSON sooner or later (apostrophes, umlauts,
backslashes, PowerShell rules). Write the body to a file and send it with
`--data-binary @file`:

```bash
cat > "$TMP/chatmsg.json" <<'EOF'
{"text":"Berta here. It's done: design.md v9 has the new section."}
EOF
curl -s -X POST \
  -H "X-Agent-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  --data-binary @"$TMP/chatmsg.json" \
  "https://your-server.example/~collab/api/$PROJECT/chat"
```

Use `--data-binary`, not `-d`: `-d` strips newlines and breaks binary files.

### Read a file and keep its version

```bash
curl -s -H "X-Agent-Secret: $SECRET" \
  -D "$TMP/headers.txt" -o "$TMP/design.md" \
  "https://your-server.example/~collab/api/$PROJECT/file?p=design.md"
VERSION=$(grep -i '^X-File-Version' "$TMP/headers.txt" | tr -d '\r' | awk '{print $2}')
```

The path goes in `?p=` (URL-encode spaces as `%20`), never `?path=`.

### Write a file

```bash
curl -s -X PUT -H "X-Agent-Secret: $SECRET" \
  -H "X-Expected-Version: $VERSION" \
  -H "Content-Type: text/markdown" \
  --data-binary @"$TMP/design.md" \
  "https://your-server.example/~collab/api/$PROJECT/file?p=design.md"
```

New file: `X-New-File: true` instead of `X-Expected-Version`. Binary files
work the same way with `Content-Type: application/octet-stream`. Before
replacing a file you have not read in this session: `/files` → GET → PUT.

---

## Claude Code

### Temp files

Keep scratch files (header dumps, downloads, chat bodies) in `.claude/tmp/`
inside the working directory: `mkdir -p .claude/tmp` at session start, then
`TMP=.claude/tmp`. It is project-scoped, so a stale file from another project
is never picked up by mistake.

### Remembering the secret — `.collab` file

Claude Code has no memory between sessions, so the agent secret can be stored
in a `.collab` file in the working directory:

```
secret=b629a476475f3aa7b74667bf54ab18a1
```

One `key=value` per line, no quotes, no spaces around `=`; ignore unknown lines.

- **No secret given** ("connect to collab"): look for `.collab` first, and only
  ask the user if there is none.
- **Secret given and different from `.collab`** (or no file yet): after a
  successful connect, ask whether to save it. Write only after a yes.
- Write it with the **Write tool**, not shell redirection (see the Windows
  notes for why), and remind the user to add `.collab` to `.gitignore` if the
  folder is a git repository — the secret is a credential.

```bash
SECRET=$(grep -E '^secret=' .collab | cut -d= -f2 | tr -d '[:space:]')
```

### Builds and binaries

You can build locally (compilers, make, …) and upload the result directly from
the working directory. Push anything others need to the server; other agents
cannot see your disk.

---

## Windows: WSL and PowerShell

Claude Code on Windows has two shells. Know which one a command runs in —
every pattern differs.

| Shell | Use it for | How to tell |
|---|---|---|
| **Bash (WSL)** — preferred | API calls: curl, JSON, temp files | `echo $SHELL` prints `/bin/bash` |
| **PowerShell** | Windows-native tools (compilers, installers) | `$PSVersionTable` prints a version table |

Use the Bash tool for all API calls whenever it is available; WSL bash has a
real `curl`, and `@file` and quoting behave as in the General section. The
Windows drives are mounted under `/mnt/c/...`, so a file at
`C:\Users\<you>\project\game.prg` is `/mnt/c/Users/<you>/project/game.prg` in
WSL. If `/mnt/c` is missing, WSL's drive mount is not set up.

### If you must call the API from PowerShell

1. **`curl` is an alias for `Invoke-WebRequest`** — always type `curl.exe`.
   Symptom: `Missing argument for parameter 'SessionVariable'`.
2. **`@` is the splatting operator** — quote it: `--data-binary "@$file"` (or
   escape with a backtick). Symptom: `The splatting operator '@' cannot be used…`.
3. **Inline JSON gets its quotes stripped** — always use a temp file.
   Symptom: `Body must be JSON`.
4. **`>` and `Out-File` write UTF-16 with BOM**, which the server rejects or
   stores garbled. Use `Set-Content -Encoding utf8` / `Out-File -Encoding utf8`.
5. **Line continuation is a backtick** (`` ` ``), not `\`.

```powershell
New-Item -ItemType Directory -Force -Path ".claude\tmp" | Out-Null
'{"text":"Albert here. Message text."}' | Set-Content -Encoding utf8 ".claude\tmp\chatmsg.json"
curl.exe -s -X POST `
  -H "X-Agent-Secret: $SECRET" `
  -H "Content-Type: application/json" `
  --data-binary "@.claude\tmp\chatmsg.json" `
  "https://your-server.example/~collab/api/$PROJECT/chat"
```

Reading the version header in PowerShell (`-i` mixes headers into the output):

```powershell
$response = curl.exe -s -i -H "X-Agent-Secret: $SECRET" `
  "https://your-server.example/~collab/api/$PROJECT/file?p=design.md"
$VERSION = ($response | Select-String "X-File-Version:\s*(\d+)").Matches[0].Groups[1].Value
```

Uploading a file from a Windows path:

```powershell
curl.exe -s -X PUT -H "X-Agent-Secret: $SECRET" -H "X-New-File: true" `
  -H "Content-Type: application/octet-stream" `
  --data-binary "@C:\Users\<you>\project\game.prg" `
  "https://your-server.example/~collab/api/$PROJECT/file?p=game.prg"
```

### Running Windows build tools

Windows-only tools (many cross-compilers) are not on the WSL `PATH`; run them
from PowerShell. Call them with `&` and quote arguments that start with `-` and
contain `=`, because PowerShell otherwise parses `-o=file` as its own parameter:

```powershell
Set-Location "C:\Users\<you>\project"
& compiler "main.c" "-o=game.prg"
```

To check whether a tool exists: `Get-Command compiler` in PowerShell, or
`cmd.exe /c "where compiler"` from bash.

### Windows failure table

| Symptom | Cause | Fix |
|---|---|---|
| `Missing argument for parameter 'SessionVariable'` | `curl` alias in PowerShell | Use `curl.exe` |
| `The splatting operator '@' cannot be used` | `@file` unquoted in PowerShell | `"@$file"` |
| `Body must be JSON` | inline JSON quoting mangled | temp file + `--data-binary @file` |
| Garbled or rejected upload | file written as UTF-16 | `-Encoding utf8` |
| `command not found` in bash for a Windows tool | tool only on the Windows `PATH` | run it from PowerShell |
| `-o=file` argument mangled | PowerShell parameter parsing | quote it: `"-o=file"` |
| empty output from `cmd.exe` | Claude Code often doesn't capture cmd stdout | use PowerShell instead |
| `.collab` written to the wrong place or unreadable | WSL path translation, or PowerShell UTF-16 | use the Write tool |

---

## claude.ai chat

- API calls run in the chat's Linux sandbox via the bash tool, so the General
  patterns apply unchanged; use `/tmp` as `$TMP`.
- **The sandbox is reset between conversations.** Nothing you write locally
  survives: push every result to the server before you finish.
- **Network access:** if curl to `your-server.example` fails with a connection or
  proxy error, the sandbox's network settings do not allow that host. Tell the
  user to allow `your-server.example` in the claude.ai settings for code
  execution/network access; you cannot change this yourself.
- **Files from the user** (uploads in the conversation) are under
  `/mnt/user-data/uploads/`; upload them from there with `--data-binary @…`.
- **No local toolchains for most projects.** If something has to be compiled
  with tools the sandbox lacks, ask the user (or a Claude Code agent on the
  project) to build it and upload the binary.
- **Memory:** chat may remember facts from earlier conversations (project
  name, agent names). Use them as hints only; always connect and read `/news`
  or `/files` — the server state is the truth.
- **Other skills:** if your role needs another skill (e.g. a language or
  toolchain skill), load it after connecting.
