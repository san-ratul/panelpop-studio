<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4">
        <div class="w-full max-w-md">
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-semibold text-gray-900">Log in</h1>
                <p class="mt-1 text-sm text-gray-600">Access your Cartoon Shorts Studio workspace</p>
            </div>
            <form
                class="space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                @submit.prevent="submit"
            >
                <div>
                    <label
                        for="email"
                        class="block text-sm font-medium text-gray-900"
                    >Email</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        autocomplete="username"
                        class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                    >
                    <p
                        v-if="form.errors.email"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>
                <div>
                    <label
                        for="password"
                        class="block text-sm font-medium text-gray-900"
                    >Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        class="mt-1.5 block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-xs transition placeholder:text-gray-400 hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                    >
                    <p
                        v-if="form.errors.password"
                        class="mt-2 text-sm text-red-600"
                    >
                        {{ form.errors.password }}
                    </p>
                </div>
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            name="remember"
                            class="h-4 w-4 accent-indigo-600"
                        >
                        Remember me
                    </label>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Log in
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
