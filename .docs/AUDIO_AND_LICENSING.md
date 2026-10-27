# Audio and Licensing

> Audio is optional everywhere; licensing is mandatory where audio is used. AI-generated audio is NOT automatically copyright-free.

## Audio categories in the MVP

| Category | Source | Required? | Licence handling |
|---|---|---|---|
| Narration voiceover | TTS provider | Only in `narrator`/`hybrid` modes | Provenance recorded (`ai_tts`); see caveat below |
| Character dialogue | TTS provider | Only in `dialogue`/`hybrid` modes | Same as narration |
| Background music | User upload or optional AI music provider | Optional | **Full licence gate required** |
| Sound effects | User upload | Optional | **Full licence gate required** |

## Silent-mode guarantee

Projects in `silent` or `speech_bubbles` mode MUST render completely without any TTS or music API calls or credentials. Renderer produces a video-only MP4 (H.264, no audio track). This is a Phase 5 acceptance criterion.

## Licence model (per `audio_licences`, see [DATABASE_DESIGN.md](DATABASE_DESIGN.md))

For every music/SFX asset the system records:
- `source_type` (library / upload / ai_music), `source_name`, `source_url`
- `licence_type`, `licence_url`, `attribution` (required attribution string if any)
- `allowed_uses` JSON — at minimum: commercial use, monetized social media, modification
- `licence_verified` boolean + `verified_at` + `evidence` (quotes/links supporting the claim)

## Hard rules

1. **Render gate:** music/SFX assets with `licence_verified=false` are flagged in the UI and BLOCKED from render. They are never silently treated as cleared.
2. **Intended-use check:** "verified" means the documented terms permit the intended use — commercial social-media video with monetization. Personal-use-only licences do NOT qualify.
3. **No arbitrary downloads:** the MVP does not scrape/download music from random websites. Music enters via (a) user upload with licence attestation, or (b) an approved source whose terms were verified and recorded. Sources under evaluation are documented in [reference/API_RESEARCH.md](reference/API_RESEARCH.md).
4. **AI-music caveat:** if AI music generation is enabled, its terms of service regarding commercial use and copyright must be verified and recorded at selection time (part of decision D-007 scope). Until verified, AI music stays disabled.
5. **TTS caveat:** AI TTS output may carry provider-specific commercial-use terms (voice licensing, attribution, monetization limits). The chosen TTS provider's terms are verified at selection time and recorded in [reference/API_RESEARCH.md](reference/API_RESEARCH.md); narration/dialogue provenance is stored per asset so terms can be re-audited later.
6. **Evidence over assertion:** the UI distinguishes "verified" (evidence recorded) from "declared" (user claims it). Declared-but-unverified assets show a persistent warning; both states are visible in the storyboard and render pre-flight.

## Upload validation (music/SFX/user audio)

- Allowed mime/extensions: audio types only (final allowlist set at implementation; e.g., mp3/wav/m4a/ogg).
- Size cap (env-configurable placeholder, e.g., `MAX_AUDIO_UPLOAD_MB=20`).
- Server-side inspection (duration/codec sanity) before the asset is marked `ready`; failures quarantine the asset.
- Attribution enforcement: if `attribution` is non-empty, the export step appends it to the SRT/description bundle so the user can comply on publishing.

## Normalization & mixing

Handled by the renderer ([VIDEO_RENDERING.md](VIDEO_RENDERING.md)): offsets via `adelay`, `amix`, music ducking, `loudnorm` — audio assets themselves are never modified destructively; the mix is computed at render time.

## What NOT to build now

- Royalty-free library browsing/search integration (deferred; if added later, it must record per-track licence evidence automatically).
- Automated rights verification (evidence is recorded by the user/admin; the system enforces the gate, it does not judge law).
