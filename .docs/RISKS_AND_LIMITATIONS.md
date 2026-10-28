# Risks and Limitations

> Known before implementation. Each risk has an owner-action, not just worry. Review at each phase completion.

| # | Risk / limitation | Impact | Mitigation | Status |
|---|---|---|---|---|
| R-1 | **Character consistency** across AI-generated images is best-effort only; no provider guarantees identical characters between scenes | Visual quality varies; user expectation management critical | Character reference images reused per scene; structured descriptions + fixed style anchors in prompts; UI copy must not promise perfect consistency (D-011) | Accepted limitation; **Phase 4 implementation note:** scene images on the OpenAI Image API are generated *from* the stored character reference images via `POST /v1/images/edits` (`image[]` parts) — the strongest documented consistency mechanism available; still best-effort (real-world consistency quality to be observed during the manual real-key test) |
| R-2 | **Audio licensing uncertainty** — AI TTS/music terms and library licences vary; wrong assumption = takedown/demonetization risk | Legal/commercial | Licence gate blocks unverified music/SFX (D-013); provider terms verified at selection (D-007); provenance stored per asset | Mitigation designed; verification pending Phase 3/5 |
| R-3 | **API cost** multiplies with scene count and regeneration | Budget overrun | Ledger + ceilings + fingerprint dedupe + reuse of ready assets ([COST_MANAGEMENT.md](COST_MANAGEMENT.md)) | Mitigation designed (Phase 2) |
| R-4 | **Rendering performance** — 30–90 s at 1080×1920@30fps takes minutes of CPU; intermediates consume disk | Slow iterations; disk exhaustion | Segment-per-scene pipeline; explicit timeouts; mandatory intermediate cleanup; pre-flight disk-space check ([VIDEO_RENDERING.md](VIDEO_RENDERING.md)) | Mitigation designed (Phase 6) |
| R-5 | **FFmpeg/FFprobe missing on dev machine** | Phase 6 blocked; pipeline experiments blocked | Install per [ENVIRONMENT_SETUP.md](ENVIRONMENT_SETUP.md); configurable binary paths (D-008) | **Open blocker** (install before Phase 6) |
| R-6 | **Provider limitations** — TTS text-length caps, image content-policy refusals, rate limits, timeouts | Failed scenes/jobs | Normalized error taxonomy with backoff; per-scene granularity; actionable errors; prompt sanitization for content policy | Mitigation designed (Phase 2) |
| R-7 | **Windows dev quirks** — no pcntl (no Horizon), old bundled Redis 5.0.14, MySQL not auto-started | Dev friction | Database queue locally (D-003); documented start-up steps; production runs Linux ([DEPLOYMENT.md](DEPLOYMENT.md)) | Accepted |
| R-8 | **Provider behavior drift** — APIs change; docs-in-memory rots | Broken adapters | Verify official docs before writing/adapting adapter code; record model IDs + verification dates in [reference/API_RESEARCH.md](reference/API_RESEARCH.md) | Ongoing discipline |
| R-9 | **Malformed provider output** breaks scene plan persistence | Pipeline stalls | Schema-validate all structured responses before persistence; malformed → retryable provider error | Mitigation designed (Phase 2/3) |
| R-10 | **SRT/voice timing drift** — spoken audio duration may exceed scene duration | Video/voice desync | Pre-flight compares audio duration vs scene duration; longer audio ⇒ extend scene duration or warn user (decision at Phase 5) | Mitigation designed (Phase 5) |
| R-11 | **Single-machine MVP** — render CPU-bound on dev/workstation | Throughput limits | Accepted for MVP; disk-abstraction keeps future worker scaling open ([DEPLOYMENT.md](DEPLOYMENT.md)) | Accepted |
| R-12 | **English-only scope** — prompts/TTS voices validated for English initially | Non-English projects fail quality | Language stored per project; no English hardcoded in schema/validation where avoidable | Accepted for MVP |

## Explicit product limitations (communicate, don't fix silently)

- No perfect character consistency (R-1).
- No guarantee AI audio/images are rights-cleared for all uses — licence gate exists precisely because of this (R-2).
- 30–90 s / 9:16 / English-only envelope in the MVP.
- Renders are only as good as the selected provider's images; the renderer composes, it does not draw.
