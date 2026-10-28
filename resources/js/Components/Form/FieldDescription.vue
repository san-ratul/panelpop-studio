<script setup>
defineProps({
    id: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <p
        :id="id || undefined"
        class="field-hint"
    >
        <slot />
    </p>
</template>
