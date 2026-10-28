<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const page = usePage();

const status = computed(() => page.props.status);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

// Independent visibility toggle per field: flipping one never touches the
// others' state, and v-model values survive the type switch.
const visibility = reactive({
    current: false,
    next: false,
    confirm: false,
});

function toggleVisibility(field) {
    visibility[field] = !visibility[field];
}

function submit() {
    form.put(route('password.update'), {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-2xl">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">
                    Account settings
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    Signed in as {{ $page.props.auth.user.name }} ({{ $page.props.auth.user.email }})
                </p>
            </header>

            <section
                aria-labelledby="password-heading"
                class="mt-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
            >
                <h2
                    id="password-heading"
                    class="text-base font-semibold text-gray-900"
                >
                    Change password
                </h2>
                <p class="mt-2 text-sm leading-relaxed text-gray-600">
                    Use at least 8 characters. Your current password is required to confirm the change.
                </p>

                <p
                    v-if="status === 'password-updated'"
                    role="status"
                    class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700"
                >
                    Password updated.
                </p>

                <form
                    class="mt-4 space-y-4"
                    @submit.prevent="submit"
                >
                    <div>
                        <label
                            for="current_password"
                            class="block text-sm font-medium text-gray-900"
                        >
                            Current password
                        </label>
                        <div class="relative mt-1.5">
                            <input
                                id="current_password"
                                v-model="form.current_password"
                                :type="visibility.current ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                class="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                            >
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-gray-400 transition hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                :aria-label="visibility.current ? 'Hide current password' : 'Show current password'"
                                :aria-pressed="visibility.current"
                                @click="toggleVisibility('current')"
                            >
                                <svg
                                    v-if="visibility.current"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                                    />
                                </svg>
                            </button>
                        </div>
                        <p
                            v-if="form.errors.current_password"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.current_password }}
                        </p>
                    </div>

                    <div>
                        <label
                            for="password"
                            class="block text-sm font-medium text-gray-900"
                        >
                            New password
                            <span class="font-normal text-gray-500">(min. 8 characters)</span>
                        </label>
                        <div class="relative mt-1.5">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="visibility.next ? 'text' : 'password'"
                                required
                                autocomplete="new-password"
                                class="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                            >
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-gray-400 transition hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                :aria-label="visibility.next ? 'Hide new password' : 'Show new password'"
                                :aria-pressed="visibility.next"
                                @click="toggleVisibility('next')"
                            >
                                <svg
                                    v-if="visibility.next"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                                    />
                                </svg>
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div>
                        <label
                            for="password_confirmation"
                            class="block text-sm font-medium text-gray-900"
                        >
                            Confirm new password
                        </label>
                        <div class="relative mt-1.5">
                            <input
                                id="password_confirmation"
                                v-model="form.password_confirmation"
                                :type="visibility.confirm ? 'text' : 'password'"
                                required
                                autocomplete="new-password"
                                class="block w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 pr-11 text-sm text-gray-900 shadow-xs transition hover:enabled:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                            >
                            <button
                                type="button"
                                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-gray-400 transition hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                :aria-label="visibility.confirm ? 'Hide password confirmation' : 'Show password confirmation'"
                                :aria-pressed="visibility.confirm"
                                @click="toggleVisibility('confirm')"
                            >
                                <svg
                                    v-if="visibility.confirm"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="h-5 w-5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"
                                    />
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"
                                    />
                                </svg>
                            </button>
                        </div>
                        <p
                            v-if="form.errors.password_confirmation"
                            class="mt-2 text-sm text-red-600"
                        >
                            {{ form.errors.password_confirmation }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing ? 'Updating…' : 'Update password' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
