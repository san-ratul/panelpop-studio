/**
 * Minimal frontend route helper for named Laravel routes, backed by a
 * name → URI manifest shared through Inertia (see the `routes` prop in
 * app/Http/Middleware/HandleInertiaRequests.php, which is built from the
 * router so URLs stay in sync with the backend definitions).
 *
 * Supports the signatures used across the codebase:
 *   route('login')
 *   route('projects.update', 7)
 *   route('scenes.update', [7, 3])
 */
export function createRouteResolver(manifest) {
    return function route(name, ...args) {
        const template = manifest !== null && typeof manifest === 'object' ? manifest[name] : undefined;

        if (typeof template !== 'string') {
            throw new Error(`Route "${name}" is not available on this page. Check the shared route manifest in app/Http/Middleware/HandleInertiaRequests.php.`);
        }

        let params = args;

        if (args.length === 1) {
            if (Array.isArray(args[0])) {
                params = args[0];
            } else if (args[0] !== null && typeof args[0] === 'object') {
                params = Object.values(args[0]);
            } else {
                params = [args[0]];
            }
        }

        let index = 0;

        const url = ('/' + template).replace(
            /\{[a-zA-Z_][a-zA-Z0-9_]*\}/g,
            () => {
                const value = params[index++];
                return value === undefined || value === null ? '' : encodeURIComponent(value);
            },
        );

        return url.replaceAll('//', '/');
    };
}
