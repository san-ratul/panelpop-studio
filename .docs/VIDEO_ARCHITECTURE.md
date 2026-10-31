# Video Architecture (project-to-video)

Status: implemented (commits 5c63661, edcdbfe, d065ef2). Tests: VideoManagementTest,
VideoSettingsTest, VideoDuplicationTest, Phase6/AnimationSettingsTest.

## Model

A **Project** owns multiple independent **Videos** (`App\Models\Video`). Each video is a
complete pipeline unit with its own:

- Story (1:1, `stories.video_id` unique) and script text
- Scenes and storyboard data (`scenes.video_id`, unique `(video_id, number)` numbering)
- Dialogue lines (via scenes) with per-line speaker, emotion and voice instructions
- Narration + dialogue audio assets (`assets.video_id`, nullable — voice_audio/scene_image/
  video/thumbnail/subtitle are video-scoped; character_reference/music/sfx stay project-level)
- Render jobs and final output (generation_jobs.video_id; publishing replaces only that video's
  artifacts)
- Provider-call cost records (`provider_calls.video_id`)
- Generation history (video-scoped generation jobs)

Characters remain a **project-level reusable library**: every video of a project can assign the
same characters. Character reference images are project assets; their provider cost is
project-shared (reported separately in per-video cost views).

## Effective settings (inheritance)

| Setting | Resolution |
|---|---|
| Visual style | `video.visual_style_override ?? project.visual_style` |
| Language | `video.language_override ?? project.language` |
| Presentation (audio workflow) mode | `video.presentation_mode_override ?? project.presentation_mode` |
| Narrator voice | `video.settings['narration_voice_config'] ?? project.settings['narration_voice_config']` |
| Target duration | per-video column (new videos inherit the project value at creation) |
| Scene animation | `video.settings['animation']['enabled'] ?? project.settings['animation']['enabled'] ?? config('ffmpeg.animation.enabled')` |

A NULL/absent override means "inherit the project default". Changing a video override never
touches the project defaults or sibling videos. The video settings page shows inherited
defaults explicitly and offers "Reset all to project defaults" (stores NULL overrides).

Story generation, scene planning, image generation, TTS, metadata generation and rendering all
consume the **effective** values (`Video::effectiveVisualStyle/effectiveLanguage/
effectivePresentationMode/effectiveNarrationVoiceConfig/effectiveAnimationEnabled`).
Catalogs for the style/language dropdowns live in `config/catalog.php`; the current stored
value is always merged into the options so existing custom values stay selectable.

## Project status aggregate

`projects.status` is a **derived aggregate** (`App\Services\ProjectStatusService`), recomputed
on every video save/delete via model events: any video rendering → rendering; any failed and
none completed → failed; otherwise the most advanced video status wins. Single-video projects
behave exactly like the pre-videos pipeline (verified: backfilled projects kept their status).

## Routes

Video management: `videos.index/create/store/edit/update/destroy/duplicate/archive/restore`
under `projects/{project}/videos[/{video}]`. All pipeline routes are video-scoped under
`projects/{project}/videos/{video}/...` (story, storyboard, scenes, dialogue-lines, audio,
render) with `scopeBindings`; characters stay project-scoped. The audio-workspace asset licence
routes use `withoutScopedBindings()` because music/SFX assets are project-level.

GenerationJobGuard accepts an optional Video for video-scoped job types so two videos of one
project never queue-block each other; the WithoutOverlapping queue keys are per video.

## Duplicate / archive

`VideoDuplicator` copies story text, scenes, dialogue (with voice assignments) and per-video
overrides in one transaction; generated artifacts (audio, images, renders) are intentionally
NOT copied and must be regenerated. Archive sets `archived_at` (listing filter); restore
clears it. Delete cascades story/scenes/audio/render rows; asset files of deleted video
assets are removed from disk.

## Migration (2026_10_30_000001 + 2026_10_30_000002 + 000003)

Additive and resume-safe (each step checks column/index/FK existence; MySQL DDL is not
transactional). Backfill: every existing project received exactly one video seeded with the
story title and the project's pipeline status; stories/scenes/video_metadata re-parented by
project join (1:1 at migration time); video-scoped assets/jobs/calls attributed by the same
join. Character-reference/music rows stay project-level. The third migration adds the AI voice
assignment columns (`dialogue_lines.emotion/voice_instructions`,
`scenes.narration_voice_instructions`). Verified against the real dev database: 8 projects →
8 videos, zero orphan rows, project titles preserved.

## Known limitations

- Music/SFX remain a shared project library (documented product decision; per-video copies can
  be added later without schema change via `assets.video_id`).
- Character reference generation cost is project-shared, not attributed to a single video.
