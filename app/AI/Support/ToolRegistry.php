<?php

namespace App\AI\Support;

/**
 * Registry over the config-defined generation-tool presets (config/tools.php).
 * A tool is a PRESET over the one shared pipeline — never a separate engine.
 */
final class ToolRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $tools = (array) config('tools.tools', []);

        ksort($tools);

        return $tools;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $slug => $tool) {
            $options[] = [
                'slug' => (string) $slug,
                'label' => (string) ($tool['label'] ?? $slug),
                'description' => (string) ($tool['description'] ?? ''),
                'category' => (string) ($tool['category'] ?? 'story'),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $slug): ?array
    {
        $tool = config("tools.tools.{$slug}");

        return is_array($tool) ? $tool : null;
    }
}
