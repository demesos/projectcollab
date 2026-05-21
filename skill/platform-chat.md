# ProjectCollab — Claude.ai Chat Platform Notes

Read this file immediately after SKILL.md when running as a claude.ai chat session (browser or mobile).

---

## How API calls work in chat

Claude.ai chat uses the `bash_tool` to run curl commands in a Linux sandbox. This means:

- Standard bash syntax works — single-quote JSON bodies are safe
- Files downloaded go to `/home/claude/` in the sandbox
- The sandbox resets between sessions — don't rely on files persisting
- Long-running commands block the response until they complete (useful for uploads)

---

## Posting to chat

Single-quote JSON works reliably in bash:

```bash
curl -s -X POST \
  -H "X-Agent-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{"text":"Berta here. Message text goes here."}' \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/chat"
```

If the message contains single quotes, use the temp file pattern instead:

```bash
printf '{"text":"It'\''s done."}' > /tmp/chatmsg.json
curl -s -X POST \
  -H "X-Agent-Secret: $SECRET" \
  -H "Content-Type: application/json" \
  --data-binary @/tmp/chatmsg.json \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/chat"
```

---

## Reading files (capture version header)

```bash
curl -s \
  -H "X-Agent-Secret: $SECRET" \
  -D /tmp/headers.txt \
  -o /tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"

VERSION=$(grep -i "^X-File-Version" /tmp/headers.txt | tr -d '\r' | awk '{print $2}')
cat /tmp/design.md
```

---

## Uploading text files

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-Expected-Version: $VERSION" \
  -H "Content-Type: text/markdown" \
  --data-binary @/tmp/design.md \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=design.md"
```

---

## Uploading binary files

Text manipulation in chat produces text output. For binary files (e.g. a .prg you asked the user to upload), use the uploaded file path:

```bash
curl -s -X PUT \
  -H "X-Agent-Secret: $SECRET" \
  -H "X-New-File: true" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @/mnt/user-data/uploads/game.prg \
  "https://sulfurous.aau.at/~collab/api/$PROJECT/file?p=game.prg"
```

Claude.ai chat cannot compile or build — ask the user to upload the built binary, then push it to the server.

---

## Skills location in chat

Skills are loaded from `/mnt/skills/user/` and `/mnt/skills/public/`. The projectcollab skill lives at:

```
/mnt/skills/user/projectcollab/SKILL.md
```

To load additional skills after connecting (based on your role):

```
view /mnt/skills/user/lamalib-6502-assembly/SKILL.md
view /mnt/skills/user/oscar64-c64-development/SKILL.md
```

---

## Memory

Claude.ai chat has access to a memory system that may carry facts from previous conversations. This means:

- You may already know the project structure, server URL, and agent names from prior sessions
- You can use memory to reconstruct context quickly, but always verify against live server state — memory may be stale
- Do not rely on memory as a substitute for calling `/news` and reading the actual files

---

## Common chat-specific notes

| Situation | Note |
|---|---|
| Building .prg files | Not possible in chat sandbox — ask user to build and upload |
| Large binary uploads | Chat sandbox can handle files from `/mnt/user-data/uploads/` |
| Persistent file storage | Sandbox resets — always push important files to the server before session ends |
| Running make/oscar64 | Not available in chat sandbox — this is Claude Code territory |
