<?php

namespace App\AI\Support;

/**
 * Server-side audio container inspection (SECURITY.md: uploads and provider
 * artifacts are sanity-checked before an asset is marked ready; AUDIO_AND_
 * LICENSING.md: server-side inspection before an upload is trusted).
 *
 * This sniffs the documented audio container families byte-by-byte — it never
 * trusts a client-provided filename or MIME header on its own:
 * - RIFF/WAVE (wav) including exact duration from fmt/data chunk headers
 * - MP3 (ID3v2 tag or raw MPEG audio frame sync)
 * - FLAC ("fLaC" marker)
 * - Ogg (page "OggS" marker — opus/vorbis inside)
 * - AAC ADTS (frame sync 0xFFF*)
 */
final class AudioMagic
{
    /**
     * Whether the bytes look like one of the supported audio containers.
     *
     * @param  string  $format  Optional expected format (wav|mp3|flac|ogg|opus|aac|m4a).
     */
    public static function matches(string $bytes, ?string $format = null): bool
    {
        if ($bytes === '') {
            return false;
        }

        return match ($format) {
            'wav' => self::isWav($bytes),
            'mp3' => self::isMp3($bytes),
            'flac' => self::isFlac($bytes),
            'ogg', 'opus' => self::isOgg($bytes),
            'aac' => self::isAdtsAac($bytes),
            'm4a' => self::isMp4Container($bytes),
            null => self::isWav($bytes)
                || self::isMp3($bytes)
                || self::isFlac($bytes)
                || self::isOgg($bytes)
                || self::isAdtsAac($bytes)
                || self::isMp4Container($bytes),
            default => false,
        };
    }

    /**
     * Exact duration in milliseconds from a RIFF/WAVE header (fmt byte rate +
     * data chunk size), or null when the bytes are not a parsable WAV.
     */
    public static function wavDurationMs(string $bytes): ?int
    {
        if (! self::isWav($bytes) || strlen($bytes) < 44) {
            return null;
        }

        $offset = 12;
        $byteRate = null;
        $dataSize = null;

        while ($offset + 8 <= strlen($bytes)) {
            $chunkId = substr($bytes, $offset, 4);
            $chunkSize = self::u32le($bytes, $offset + 4);
            $body = $offset + 8;

            if ($chunkId === 'fmt ' && $chunkSize >= 16 && $body + 16 <= strlen($bytes)) {
                $byteRate = self::u32le($bytes, $body + 8);
            }

            if ($chunkId === 'data') {
                $dataSize = min($chunkSize, strlen($bytes) - $body);
            }

            if ($byteRate !== null && $dataSize !== null) {
                break;
            }

            // Chunks are word-aligned; a corrupt size would loop forever.
            $advance = 8 + $chunkSize + ($chunkSize % 2);

            if ($advance <= 0) {
                break;
            }

            $offset += $advance;
        }

        if ($byteRate === null || $dataSize === null || $byteRate <= 0 || $dataSize <= 0) {
            return null;
        }

        return (int) round(($dataSize / $byteRate) * 1000);
    }

    public static function isWav(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WAVE';
    }

    public static function isMp3(string $bytes): bool
    {
        if (strlen($bytes) < 4) {
            return false;
        }

        if (substr($bytes, 0, 3) === 'ID3') {
            return true;
        }

        // Raw MPEG audio frame sync: 11 set bits + non-error layer bits.
        return $bytes[0] === "\xFF" && (ord($bytes[1]) & 0xE0) === 0xE0;
    }

    public static function isFlac(string $bytes): bool
    {
        return substr($bytes, 0, 4) === 'fLaC';
    }

    public static function isOgg(string $bytes): bool
    {
        return substr($bytes, 0, 4) === 'OggS';
    }

    /** ADTS AAC frame sync (first 12 bits all set). */
    public static function isAdtsAac(string $bytes): bool
    {
        return strlen($bytes) >= 2
            && $bytes[0] === "\xFF"
            && (ord($bytes[1]) & 0xF6) === 0xF0;
    }

    /** MP4/M4A container (ftyp box). */
    public static function isMp4Container(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 4, 4) === 'ftyp';
    }

    private static function u32le(string $bytes, int $offset): int
    {
        if ($offset + 4 > strlen($bytes)) {
            return 0;
        }

        return unpack('V', substr($bytes, $offset, 4))[1] ?? 0;
    }
}
