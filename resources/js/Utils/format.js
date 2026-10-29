/**
 * Shared admin-report formatters. Zero cost is a value; "unknown" is
 * rendered distinctly as an em dash so the two are never confused.
 */

export function money(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    const fixed = Number(value).toFixed(6).replace(/0+$/, '').replace(/\.$/, '.0');

    return `$${fixed}`;
}

export function label(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value).replaceAll('_', ' ');
}

export function durationMs(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }

    return `${(Number(ms) / 1000).toFixed(1)} s`;
}

export function bytes(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    const size = Number(value);

    if (size >= 1073741824) {
        return `${(size / 1073741824).toFixed(2)} GB`;
    }

    if (size >= 1048576) {
        return `${(size / 1048576).toFixed(1)} MB`;
    }

    if (size >= 1024) {
        return `${(size / 1024).toFixed(1)} KB`;
    }

    return `${size} B`;
}

export function pct(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toFixed(1)}%`;
}

export function dateShort(iso) {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? String(iso)
        : date.toLocaleString(undefined, {
              year: 'numeric',
              month: 'short',
              day: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}
