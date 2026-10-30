<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { createRouteResolver } from '@/route.js';
import FlashBanner from '@/Components/FlashBanner.vue';
import { buttonVariants } from '@/Components/Form/buttonVariants.js';
import SidebarIcon from '@/Components/SidebarIcon.vue';

const props = defineProps({
    authUser: {
        type: Object,
        default: null,
    },
    // Project context (id + title) supplied by project-scoped pages so the
    // sidebar can expose the workspace sections of the routes that exist for
    // this project (story editor, storyboard, characters, audio, render).
    project: {
        type: Object,
        default: null,
    },
});

const page = usePage();

const route = createRouteResolver(page.props.routes);

// Some pages pass an authUser prop, but the backend only shares auth.user —
// fall back to the shared prop so the header always reflects the real state.
const currentUser = computed(() => props.authUser ?? page.props.auth?.user ?? null);

const isAdmin = computed(() => Boolean(currentUser.value?.is_admin));

const productLinks = computed(() => {
    const links = [{ label: 'Dashboard', href: route('dashboard'), icon: 'dashboard', components: ['Dashboard'] }];

    if (props.project) {
        links.push(
            { label: 'Story', href: route('story.edit', props.project.id), icon: 'story', components: ['Story/Edit'] },
            { label: 'Storyboard', href: route('storyboard.show', props.project.id), icon: 'storyboard', components: ['Storyboard/Show'] },
            { label: 'Characters', href: route('characters.index', props.project.id), icon: 'characters', components: ['Characters/Index', 'Characters/Edit'] },
            { label: 'Audio', href: route('audio.show', props.project.id), icon: 'audio', components: ['Audio/Show'] },
            { label: 'Render', href: route('render.show', props.project.id), icon: 'render', components: ['Render/Show'] },
        );
    }

    return links;
});

const settingsLinks = computed(() => [
    { label: 'AI providers', href: route('provider-settings.index'), icon: 'providers', components: ['Settings/Providers'] },
    { label: 'Account', href: route('password.edit'), icon: 'account', components: ['Settings/Password'] },
]);

// Admin-only entries; route access is gated server-side (admin gate), the
// links are merely discoverability for authorized administrators.
const adminLinks = computed(() => (isAdmin.value ? [
    { label: 'Overview', href: route('admin.dashboard'), icon: 'overview', components: ['Admin/Dashboard'] },
    { label: 'Cost analytics', href: route('admin.costs.index'), icon: 'costs', components: ['Admin/Costs'] },
    { label: 'Detailed cost reports', href: route('admin.costs.calls'), icon: 'reports', components: ['Admin/CostCalls'] },
    { label: 'Pricing simulation', href: route('admin.pricing.simulation'), icon: 'simulation', components: ['Admin/PricingSimulation'] },
] : []));

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

const sidebarOpen = ref(false);

// Any navigation (including back/forward) closes the mobile drawer.
watch(() => page.component, () => {
    sidebarOpen.value = false;
});

function isActive(link) {
    return link.components.includes(page.component);
}

function linkClass(link) {
    return [
        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
        isActive(link)
            ? 'bg-indigo-50 text-indigo-700'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900',
    ];
}
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <!-- Mobile top bar -->
        <header class="border-b border-gray-200 bg-white lg:hidden">
            <div class="flex h-16 items-center justify-between gap-4 px-4">
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        :aria-expanded="sidebarOpen ? 'true' : 'false'"
                        aria-controls="app-sidebar"
                        aria-label="Toggle navigation"
                        @click="sidebarOpen = !sidebarOpen"
                    >
                        <svg
                            v-if="!sidebarOpen"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                            aria-hidden="true"
                            class="h-6 w-6"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"
                            />
                        </svg>
                        <svg
                            v-else
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                            aria-hidden="true"
                            class="h-6 w-6"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18 18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                    <span class="text-base font-semibold tracking-tight text-gray-900">
                        {{ $page.props.appName }}
                    </span>
                </div>
                <button
                    v-if="currentUser"
                    type="button"
                    :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                    @click="$inertia.post(route('logout'))"
                >
                    Log out
                </button>
                <Link
                    v-else
                    :href="route('login')"
                    :class="buttonVariants({ variant: 'primary', size: 'sm' })"
                >
                    Log in
                </Link>
            </div>
        </header>

        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-gray-900/40 lg:hidden"
            aria-hidden="true"
            @click="sidebarOpen = false"
        />

        <!-- Sidebar (persistent on desktop, drawer on mobile) -->
        <aside
            id="app-sidebar"
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-gray-200 bg-white transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-16 shrink-0 items-center gap-2.5 border-b border-gray-200 px-5">
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
            </div>

            <nav
                class="flex-1 space-y-6 overflow-y-auto px-3 py-4"
                aria-label="Main navigation"
            >
                <div>
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Workspace
                    </p>
                    <Link
                        v-for="link in productLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="linkClass(link)"
                        :aria-current="isActive(link) ? 'page' : undefined"
                    >
                        <SidebarIcon :name="link.icon" />
                        {{ link.label }}
                    </Link>
                </div>

                <div>
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Settings
                    </p>
                    <Link
                        v-for="link in settingsLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="linkClass(link)"
                        :aria-current="isActive(link) ? 'page' : undefined"
                    >
                        <SidebarIcon :name="link.icon" />
                        {{ link.label }}
                    </Link>
                </div>

                <div v-if="isAdmin">
                    <p class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                        Administration
                    </p>
                    <Link
                        v-for="link in adminLinks"
                        :key="link.href"
                        :href="link.href"
                        :class="linkClass(link)"
                        :aria-current="isActive(link) ? 'page' : undefined"
                    >
                        <SidebarIcon :name="link.icon" />
                        {{ link.label }}
                    </Link>
                </div>
            </nav>

            <div
                v-if="currentUser"
                class="flex shrink-0 items-center justify-between gap-3 border-t border-gray-200 px-4 py-3"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <span
                        aria-hidden="true"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700"
                    >
                        {{ initials }}
                    </span>
                    <span class="min-w-0">
                        <span class="block max-w-[9rem] truncate text-sm font-medium text-gray-700">
                            {{ currentUser.name }}
                        </span>
                        <span
                            v-if="isAdmin"
                            class="block text-xs font-medium text-indigo-600"
                        >
                            Administrator
                        </span>
                    </span>
                </span>
                <button
                    type="button"
                    :class="buttonVariants({ variant: 'secondary', size: 'sm' })"
                    @click="$inertia.post(route('logout'))"
                >
                    Log out
                </button>
            </div>
        </aside>

        <FlashBanner
            :message="page.props.flashWarning ?? ''"
            variant="warning"
        />
        <FlashBanner
            :message="page.props.flashSuccess ?? ''"
            variant="success"
        />
        <main class="lg:pl-64">
            <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <slot />
            </div>
        </main>
    </div>
</template>
