# Development Rules (MANDATORY for every AI coding agent)

> These rules override convenience. A rule violation is a defect even when the output "works".

## Process rules

1. **Never assume existing project structure.** Inspect the repository before creating or modifying anything; trust disk over docs when they conflict, then fix the docs.
2. **Never generate random or speculative code.** Every file written must serve the current phase's acceptance criteria.
3. **Work in small, verifiable phases.** One phase (or a coherent sub-step) at a time; verify before moving on.
4. **Inspect current file contents before modifying them.** No blind overwrites; preserve unrelated working functionality.
5. **Review the existing implementation before proposing changes** to it.
6. **Change only files necessary** for the current task.
7. **Do not generate future-phase code** early.
8. Keep each response focused on the current task.

## Technology rules

9. Use **Laravel 13** conventions; verify package compatibility against installed versions before adopting.
10. Use **PHP 8.3+** features appropriately (typed code, attributes).
11. **JavaScript only — never TypeScript** in any frontend file (`.vue`, `.js`, config). No `lang="ts"`, no `.ts` files, no JSDoc-typed TS builds.
12. Vue SFC block order: follow the shipped codebase convention `<script setup>` → `<template>` → optional `<style>` (D-023; all Phase 0–2 SFCs use this order). Use Composition API `<script setup>` in plain JS.
13. Tailwind CSS 4 via the project's Vite pipeline; shadcn-vue components only where genuinely useful.

## Honesty rules

14. **Do not claim success without running relevant verification** (tests, builds, artisan commands). Report what actually ran and what actually happened.
15. **Never fabricate successful API responses, assets, or artifacts.** A missing credential or failed call is reported as a failure with an actionable message.
16. **Never expose API keys or sensitive information** in source, logs, fixtures, docs, or reports. Placeholders only.

## Quality rules

17. **Run relevant tests after meaningful changes** (`php artisan test`, `npm run build` at minimum for touched areas). Fix or explicitly report failures — never leave silent breakage.
18. **Report changed files, commands executed, test results (passed/failed), and outstanding work** at the end of each task.
19. **Update documentation whenever implementation changes agreed architecture or behavior** — at minimum [PROGRESS.md](PROGRESS.md); plus phase doc, [DECISIONS.md](DECISIONS.md), [CHANGELOG.md](CHANGELOG.md) as applicable. Never mark work complete merely because it is documented; completion requires recorded verification evidence.

## Scope locks (MVP)

20. **Do not implement automated social publishing** (YouTube/Facebook/TikTok APIs) in the MVP.
21. **Do not implement SaaS subscription billing**, payments, or public registration in the MVP.

## Security rules

22. Validate all input with Form Requests; authorize every object access via policies.
23. All generated assets on the private disk; downloads only via authorized endpoints.
24. Process invocations use argument arrays; untrusted data never enters shell strings.
25. Queue payloads never contain secrets; provider credentials never reach the browser or logs.

## Documentation hygiene

26. Keep documents single-source: link instead of duplicating ([INDEX.md](INDEX.md) policy). Placeholders for secrets; evidence with dates for external facts ([reference/API_RESEARCH.md](reference/API_RESEARCH.md)).
