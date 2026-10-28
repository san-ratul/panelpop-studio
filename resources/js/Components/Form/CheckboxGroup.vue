<script setup>
import { computed, useId } from 'vue';
import FieldError from './FieldError.vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
    options: {
        type: Array,
        required: true,
    },
    required: {
        type: Boolean,
        default: false,
    },
    error: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        default: '',
    },
    modelValue: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:modelValue']);

const autoId = useId();

const groupId = computed(() => props.id || autoId);

const errorId = computed(() => (props.error ? `${groupId.value}-error` : undefined));

function toggle(value, event) {
    const next = [...props.modelValue];

    if (event.target.checked) {
        if (! next.includes(value)) {
            next.push(value);
        }
    } else {
        const index = next.indexOf(value);

        if (index !== -1) {
            next.splice(index, 1);
        }
    }

    emit('update:modelValue', next);
}
</script>

<template>
    <fieldset v-bind="$attrs">
        <legend class="field-label">
            {{ label }}
            <span
                v-if="required"
                class="text-red-500"
                aria-hidden="true"
            >*</span>
        </legend>
        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
            <label
                v-for="option in options"
                :key="String(option.value)"
                class="field-check-label"
            >
                <input
                    type="checkbox"
                    class="field-checkbox"
                    :value="option.value"
                    :checked="modelValue.includes(option.value)"
                    :aria-describedby="errorId || undefined"
                    @change="toggle(option.value, $event)"
                >
                <span>{{ option.label }}</span>
            </label>
        </div>
        <FieldError
            v-if="error"
            :id="errorId"
            :error="error"
        />
    </fieldset>
</template>
