# CLAUDE.md — Ecstatic Dance Viseu

Working agreement for Claude Code in this repo. Full rules live in the SSOT:
`~/Documents/My OS/20 Brands/Ecstatic Dance Viseu/Project Rules.md` (and `00 Overview`, `State of the Art`).

## What this is
Multi-page static site (Vite 6) + PHP 8.3 ticketing backend for **ecstaticdanceviseu.pt** — an ecstatic dance event series in Viseu, Portugal. Deployed via Coolify (Nixpacks) on Hetzner.

## Run
```bash
# The global ~/.npm cache is blocked in this sandbox — use a project-local cache:
npm install --cache .npm-cache
npm run dev          # Vite-only frontend (no PHP)
npm run dev:local    # full stack — REQUIRES php (brew install php), not installed locally
npm run build        # Vite build → dist/  (last verified green: 2026-06-05)
```

## Branches & deploy
- `main` = stable/deployable. `dev` = build here, commit small, `git push -u origin dev`.
- **Confirm with the user before**: merge dev → main, deploy, touching the live DB, or any Google Drive change.

## Event content model
- `/links` is driven by the **active admin event** (`server/api/get-events.php`: `is_active=1` AND `date>=today`). Edit events in `/admin`, don't hardcode.
- Current target: **ED Viseu #02 — Sat 27 Jun 2026**, Nua e Crua, 16:00–19:00 (doors 15:30), DJ Bernardo B-file, cap 60, min €25 (`server/setup/migration_2026_06_event_02.sql`).
- Note: as of 2026-06-05 the live `/links` shows "Sem evento activo" — Jun 27 not yet activated in prod.

## Conventions
- Portuguese primary; brand voice is direct/sober/warm/embodied — never guru or pseudo-therapeutic.
- Never invent event facts (date/venue/price/lineup) — pull from admin/SSOT or ask.
- Never commit `server/api/config.php`, DB files, or payment proofs (gitignored).
- Keep media/large assets in Google Drive, not the repo.
