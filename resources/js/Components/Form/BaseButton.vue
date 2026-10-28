<script setup>
import { buttonVariants } from './buttonVariants.js';

defineProps({
    variant: {
        type: String,
        default: 'primary',
    },
    size: {
        type: String,
        default: 'md',
    },
});
</script>

<template>
    <button
        type="button"
        :class="buttonVariants({ variant, size })"
    >
        <slot />
    </button>
</template>
