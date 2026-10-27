# Requirements

> Derived from the agreed project brief. Changes here are architectural decisions → log in [DECISIONS.md](DECISIONS.md).

## Functional requirements

### FR-1 Projects
- FR-1.1: Authenticated user can create video projects with: title, presentation mode, target duration (30–90 s), visual style, language (English initially).
- FR-1.2: Projects list and dashboard overview with per-project status.
- FR-1.3: All project-scoped operations enforce ownership authorization.
- FR-1.4: Per-project provider overrides are supported (optional; system defaults otherwise).

### FR-2 Story
- FR-2.1: User can enter a story manually (title, logline, full text).
- FR-2.2: User can request AI story generation from a brief via the text-generation capability.
- FR-2.3: AI can transform a story into a structured scene plan: ordered scenes with duration, background, characters present, actions/expressions, image prompt, narration, dialogue lines.
- FR-2.4: Story, scenes, characters, narration, dialogue, prompts and timings are all user-editable.
- FR-2.5: Total scene duration must validate against the project target duration (tolerance defined at implementation; recorded in DECISIONS.md).
- FR-2.6: Character manager: create/edit characters with descriptions, voice config, and reference image.

### FR-3 Images
- FR-3.1: Generate a character reference image per character (reusable across scenes).
- FR-3.2: Generate a scene image per scene, using the character references + structured prompts, in vertical-friendly composition.
- FR-3.3: Per-scene regenerate without regenerating other scenes.
- FR-3.4: Image-generation status visible per scene; failures actionable and retryable.

### FR-4 Audio
- FR-4.1: Generate narration audio (narrator mode, hybrid) via TTS.
- FR-4.2: Generate per-line character dialogue audio (dialogue, hybrid modes).
- FR-4.3: Silent and speech-bubble modes require no TTS/music credentials.
- FR-4.4: Optional background music and sound effects may be attached; each carries licence metadata (source, licence type, attribution, allowed uses, evidence, verified flag).
- FR-4.5: Assets with unverified licences are flagged and BLOCKED from render — never silently treated as cleared.
- FR-4.6: Voice configuration per character (voice selection/style) where the TTS provider supports it.

### FR-5 Rendering & output
- FR-5.1: Render runs asynchronously in a queued job; progress is visible in the UI.
- FR-5.2: Pipeline per [VIDEO_RENDERING.md](VIDEO_RENDERING.md): vertical composition, pan/zoom motion, transitions, speech bubbles, burned-in captions (optional), audio mixing with normalization, H.264/AAC MP4, 1080×1920, 30–90 s.
- FR-5.3: FFprobe validates the final artifact before the job is marked complete.
- FR-5.4: Thumbnail generated; SRT exported when timing data supports it.
- FR-5.5: Finished MP4, thumbnail, SRT downloadable only through authorized endpoints.
- FR-5.6: Failed render/scene jobs are retryable without regenerating intact assets.

### FR-6 Provider administration
- FR-6.1: Provider configuration page per capability: provider, model, credentials, defaults.
- FR-6.2: Missing/invalid credentials produce a clear configuration error — never a fabricated success.
- FR-6.3: Generation history with status, provider, model, cost estimate/actual, duration, and error details.

### FR-7 UI (Vue 3 + Inertia + Tailwind 4, JavaScript only)
Dashboard overview, project list, create-project workflow, story editor, character manager, scene storyboard, image-generation status, scene-level regenerate, voice/dialogue configuration, render progress, final preview, downloads, provider configuration, generation history/errors. Reusable components, consistent form validation, responsive, function over decoration.

## Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-1 | Stack: PHP 8.3+, Laravel 13, MySQL, Vue 3, Inertia, Tailwind CSS 4, Vite, **JavaScript only (no TypeScript)** |
| NFR-2 | All long operations (story, planning, images, voice, render, thumbnail) run via queued jobs with status tracking, retry limits, backoff |
| NFR-3 | Queue: database driver locally; Redis documented/recommended for production |
| NFR-4 | Secrets: env vars or encrypted DB storage; never in code, logs, responses, or reports; never fully logged |
| NFR-5 | Assets stored outside public webroot; access via authorized routes |
| NFR-6 | Safe process invocation (argument arrays, no shell string concatenation of user input) |
| NFR-7 | Automated tests must not require paid API calls; external APIs mocked/faked |
| NFR-8 | Idempotent operations where practical; retries must not double-charge providers |
| NFR-9 | No internal exception traces or secrets exposed to the UI |
| NFR-10 | Data model normalized for core entities; JSON only for genuinely flexible metadata |
| NFR-11 | Ownership/credential design prepared for future multi-tenant SaaS (no billing built now) |

## Explicit exclusions (MVP)

- Automated publishing to YouTube/Facebook/TikTok or any platform.
- Subscription billing, payments, public SaaS registration.
- AI-generated video clips as the primary visual source.
- Multi-user credential management UI (schema prepared only).

## Constraint notes for agents

- Laravel 13 conventions; verify package compatibility against installed versions before use.
- Use supported model attributes such as `#[Fillable([...])]` where the installed framework supports them (validate in Phase 0/1 — see [DECISIONS.md](DECISIONS.md) D-010).
- English content initially; do not hardcode English into validation/schema where avoidable.
