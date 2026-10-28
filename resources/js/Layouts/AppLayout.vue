<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { createRouteResolver } from '@/route.js';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

// Some pages pass an authUser prop, but the backend only shares auth.user —
// fall back to the shared prop so the header always reflects the real state.
const currentUser = computed(() => props.authUser ?? page.props.auth?.user ?? null);

const navLinks = [
    { label: 'Dashboard', href: route('dashboard'), component: 'Dashboard' },
    { label: 'AI providers', href: route('provider-settings.index'), component: 'Settings/Providers' },
    { label: 'Account', href: route('password.edit'), component: 'Settings/Password' },
];

const initials = computed(() => {
    const name = currentUser.value?.name ?? '';

    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0].toUpperCase())
            .join('')
        || '?'
    );
});

function isActive(link) {
    return page.component === link.component;
}

function linkClass(link) {
    return [
        'shrink-0 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
        isActive(link)
            ? 'bg-indigo-50 text-indigo-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
    ];
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between gap-4">
                    <div class="flex items-center gap-4 sm:gap-6">
                        <Link
                            :href="route('dashboard')"
                            class="flex items-center gap-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        >
                            <span
                                aria-hidden="true"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white shadow-sm"
                            >
                                P
                            </span>
                            <span class="text-base font-semibold tracking-tight text-gray-900">
                                {{ $page.props.appName }}
                            </span>
                        </Link>

                        <nav
                            class="hidden items-center gap-1 sm:flex"
                            aria-label="Main navigation"
                        >
                            <Link
                                v-for="link in navLinks"
                                :key="link.href"
                                :href="link.href"
                                :class="linkClass(link)"
                                :aria-current="isActive(link) ? 'page' : undefined"
                            >
                                {{ link.label }}
                            </Link>
                        </nav>
                    </div>

                    <div
                        v-if="currentUser"
                        class="flex items-center gap-3"
                    >
                        <span class="hidden items-center gap-2 sm:flex">
                            <span
                                aria-hidden="true"
                                class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700"
                            >
                                {{ initials }}
                            </span>
                            <span class="max-w-[10rem] truncate text-sm font-medium text-gray-700">
                                {{ currentUser.name }}
                            </span>
                        </span>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-xs transition hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                            @click="$inertia.post(route('logout'))"
                        >
                            Log out
                        </button>
                    </div>
                    <Link
                        v-else
                        :href="route('login')"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                    >
                        Log in
                    </Link>
                </div>
            </div>

            <nav
                class="border-t border-gray-100 px-4 py-2 sm:hidden"
                aria-label="Main navigation"
            >
                <div class="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto">
                    <Link
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="linkClass(link)"
                        :aria-current="isActive(link) ? 'page' : undefined"
                    >
                        {{ link.label }}
                    </Link>
                </div>
            </nav>
        </header>
        <div
            v-if="page.props.flashWarning"
            class="border-b border-amber-200 bg-amber-50 text-sm text-amber-800"
            role="alert"
        >
            <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
                {{ page.props.flashWarning }}
            </div>
        </div>
        <div
            v-if="page.props.flashSuccess"
            class="border-b border-emerald-200 bg-emerald-50 text-sm text-emerald-800"
            role="status"
        >
            <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
                {{ page.props.flashSuccess }}
            </div>
        </div>
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>
