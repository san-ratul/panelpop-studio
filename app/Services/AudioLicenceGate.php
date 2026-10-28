<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\AudioLicence;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Render pre-flight licence gate (Phase 5; enforced by the Phase 6 renderer).
 *
 * Rules from .docs/AUDIO_AND_LICENSING.md:
 * - Every music/SFX asset must carry a licence record. No record → blocked.
 * - licence_verified=false → blocked ("declared" or "unverified" both fail —
 *   evidence over assertion). Never silently treated as cleared.
 * - licence_verified=true but the recorded allowed_uses do not cover the
 *   intended MVP use (commercial monetized social media + modification)
 *   → blocked: "verified" must mean the documented terms permit the intended
 *   use (hard rule 2), so the gate checks the recorded uses too.
 *
 * TTS/voice assets are provenance-tracked (ai_tts in assets.meta) but are NOT
 * licence-gated: the provider's terms are verified and recorded at selection
 * time (D-007 / reference/API_RESEARCH.md).
 */
final class AudioLicenceGate
{
    /** The intended MVP use, per AUDIO_AND_LICENSING.md ("at minimum"). */
    public const REQUIRED_USES = ['commercial_use', 'monetized_social_media', 'modification'];

    /**
     * All music/SFX assets of the project that would block a render, with an
     * actionable reason each. Empty list → pre-flight passes.
     *
     * @return Collection<int, array{asset: Asset, reason: string}>
     */
    public function blockedAssets(Project $project): Collection
    {
        $assets = Asset::query()
            ->where('project_id', $project->id)
            ->whereIn('type', [AssetType::Music->value, AssetType::Sfx->value])
            ->orderBy('id')
            ->get();

        return $assets
            ->map(fn (Asset $asset): array => [
                'asset' => $asset,
                'reason' => $this->reasonFor($asset),
            ])
            ->filter(fn (array $entry): bool => $entry['reason'] !== null)
            ->values();
    }

    /**
     * Gate verdict for a single asset: null = passes, string = actionable
     * block reason.
     */
    public function reasonFor(Asset $asset): ?string
    {
        // TTS/voice assets are provenance-tracked but not licence-gated
        // (see class docblock); only music/SFX enter the gate.
        if (! in_array($asset->type, [AssetType::Music, AssetType::Sfx], true)) {
            return null;
        }

        $licence = AudioLicence::query()->where('asset_id', $asset->id)->first();

        if ($licence === null) {
            return sprintf(
                'The %s asset "%s" has no licence record. Add its licence details before rendering.',
                $asset->type->value,
                (string) ($asset->original_filename ?? $asset->path),
            );
        }

        if (! $licence->licence_verified) {
            return sprintf(
                'The %s asset "%s" is not licence-verified. Record the licence evidence and confirm verification before rendering.',
                $asset->type->value,
                (string) ($asset->original_filename ?? $asset->path),
            );
        }

        $missingUses = array_values(array_diff(
            self::REQUIRED_USES,
            is_array($licence->allowed_uses) ? $licence->allowed_uses : [],
        ));

        if ($missingUses !== []) {
            return sprintf(
                'The licence for "%s" does not record the required uses: %s. A licence that does not permit commercial, monetized social-media use cannot pass render pre-flight.',
                (string) ($asset->original_filename ?? $asset->path),
                implode(', ', $missingUses),
            );
        }

        return null;
    }

    /**
     * @return list<string> actionable messages; empty when render pre-flight passes
     */
    public function blockMessages(Project $project): array
    {
        return $this->blockedAssets($project)
            ->pluck('reason')
            ->map(static fn ($reason): string => (string) $reason)
            ->all();
    }
}
