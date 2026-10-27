<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    authUser: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AppLayout :auth-user="authUser">
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h1 class="text-2xl font-semibold text-gray-900">Dashboard</h1>
            <p class="mt-2 text-sm text-gray-600">
                Welcome back, {{ authUser.name }}. Phase 0 foundation is in place — no domain features yet.
            </p>
        </div>
    </AppLayout>
</template>
