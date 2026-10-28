<script setup>
import { computed, useId, useAttrs } from 'vue';
import FieldError from './FieldError.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        default: '',
    },
    value: {
        type: [String, Number, Boolean],
        default: undefined,
    },
    modelValue: {
        type: [Boolean, Array],
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

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

const isChecked = computed(() => {
    if (Array.isArray(props.modelValue)) {
        return props.modelValue.includes(props.value);
    }

    return Boolean(props.modelValue);
});

function onChange(event) {
    if (Array.isArray(props.modelValue)) {
        const next = [...props.modelValue];

        if (event.target.checked) {
            if (! next.includes(props.value)) {
                next.push(props.value);
            }
        } else {
            const index = next.indexOf(props.value);

            if (index !== -1) {
                next.splice(index, 1);
            }
        }

        emit('update:modelValue', next);

        return;
    }

    emit('update:modelValue', event.target.checked);
}
</script>

<template>
    <div v-bind="rootAttrs">
        <label
            :for="inputId"
            class="field-check-label"
        >
            <input
                :id="inputId"
                type="checkbox"
                class="field-checkbox"
                :value="value"
                :checked="isChecked"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="errorId || undefined"
                v-bind="controlAttrs"
                @change="onChange"
            >
            <span class="min-w-0">
                <slot>{{ label }}</slot>
            </span>
        </label>
        <FieldError
            v-if="error"
            :id="errorId"
            :error="error"
        />
    </div>
</template>
