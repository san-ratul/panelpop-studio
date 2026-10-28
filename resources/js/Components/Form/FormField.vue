<script setup>
import FieldError from './FieldError.vue';
import FieldDescription from './FieldDescription.vue';

defineProps({
    label: {
        type: String,
        default: '',
    },
    htmlFor: {
        type: String,
        default: '',
    },
    required: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    description: {
        type: String,
        default: '',
    },
    errorId: {
        type: String,
        default: '',
    },
    descriptionId: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <div>
        <div
            v-if="label || $slots.label || $slots.action"
            class="flex items-center justify-between gap-3"
        >
            <label
                :for="htmlFor || undefined"
                class="field-label"
            >
                <slot name="label">
                    {{ label }}
                    <span
                        v-if="required"
                        class="text-red-500"
                        aria-hidden="true"
                    >*</span>
                </slot>
            </label>
            <slot name="action" />
        </div>
        <div :class="label || $slots.label || $slots.action ? 'mt-1.5' : undefined">
            <slot />
        </div>
        <FieldError
            v-if="error"
            :id="errorId"
            :error="error"
        />
        <FieldDescription
            v-else-if="description || $slots.description"
            :id="descriptionId"
        >
            <slot name="description">{{ description }}</slot>
        </FieldDescription>
    </div>
</template>
