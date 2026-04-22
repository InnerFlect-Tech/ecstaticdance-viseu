# Learnings

## 2026-04-22 — Coolify + Nixpacks runtime command

When deploying a Vite static site with Coolify using **Nixpacks**, ensure there is an explicit **runtime start command** (for example via `nixpacks.toml` `[start].cmd` and/or an `npm run start` script). Otherwise Coolify may attempt to run an empty `bash -c` command and crash-loop with:

`/bin/bash: -c: option requires an argument`

