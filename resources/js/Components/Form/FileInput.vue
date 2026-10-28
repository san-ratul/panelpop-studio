<script setup>
import { computed, useId, useAttrs } from 'vue';
import FormField from './FormField.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: {
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
    id: {
        type: String,
        default: '',
    },
});

const autoId = useId();

// class/style style the field wrapper; every other attribute belongs to the control.
const attrs = useAttrs();

const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }));

const controlAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs;

    return rest;
});

const inputId = computed(() => props.id || autoId);

const errorId = computed(() => (props.error ? `${inputId.value}-error` : undefined));

const descriptionId = computed(() => (props.description && !props.error ? `${inputId.value}-description` : undefined));

const describedBy = computed(() => errorId.value ?? descriptionId.value);
</script>

<template>
    <FormField
        v-bind="rootAttrs"
        :label="label"
        :html-for="inputId"
        :required="required"
        :error="error"
        :description="description"
        :error-id="errorId"
        :description-id="descriptionId"
    >
        <template v-if="$slots.label" #label>
            <slot name="label" />
        </template>
        <template v-if="$slots.description" #description>
            <slot name="description" />
        </template>

        <input
            :id="inputId"
            type="file"
            class="field-control--file"
            :required="required || undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy || undefined"
            v-bind="controlAttrs"
        >
    </FormField>
</template>
