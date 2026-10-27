<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    authUser: {
        type: Object,
        default: null,
    },
});
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <nav class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-6">
                    <span class="text-lg font-semibold text-gray-900">{{ $page.props.appName }}</span>
                    <Link
                        v-if="authUser"
                        :href="route('dashboard')"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-if="authUser"
                        :href="route('provider-settings.index')"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        AI providers
                    </Link>
                </div>
                <div class="flex items-center gap-4">
                    <template v-if="authUser">
                        <span class="text-sm text-gray-600">{{ authUser.name }}</span>
                        <button
                            type="button"
                            class="text-sm text-gray-600 hover:text-gray-900"
                            @click="$inertia.post(route('logout'))"
                        >
                            Log out
                        </button>
                    </template>
                    <Link
                        v-else
                        :href="route('login')"
                        class="text-sm text-gray-600 hover:text-gray-900"
                    >
                        Log in
                    </Link>
                </div>
            </div>
        </nav>
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>
