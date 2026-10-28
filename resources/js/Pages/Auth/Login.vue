<script setup>
import { useForm } from '@inertiajs/vue3';
import BaseButton from '@/Components/Form/BaseButton.vue';
import CheckboxInput from '@/Components/Form/CheckboxInput.vue';
import TextInput from '@/Components/Form/TextInput.vue';

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
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    label="Email"
                    required
                    autofocus
                    autocomplete="username"
                    :error="form.errors.email"
                />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    name="password"
                    label="Password"
                    required
                    autocomplete="current-password"
                    :error="form.errors.password"
                />
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <CheckboxInput
                        v-model="form.remember"
                        name="remember"
                    >
                        Remember me
                    </CheckboxInput>
                    <BaseButton
                        type="submit"
                        :disabled="form.processing"
                    >
                        Log in
                    </BaseButton>
                </div>
            </form>
        </div>
    </div>
</template>
