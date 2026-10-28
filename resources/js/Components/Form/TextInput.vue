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
    modelValue: {
        type: [String, Number],
        default: '',
    },
    modelModifiers: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['update:modelValue']);

// Mirrors Vue's v-model.number: parse when possible, keep the raw string otherwise.
function toNumber(value) {
    const parsed = Number.parseFloat(value);

    return Number.isNaN(parsed) ? value : parsed;
}

function onInput(event) {
    const raw = event.target.value;

    emit('update:modelValue', props.modelModifiers.number ? toNumber(raw) : raw);
}

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
        <template v-if="$slots.action" #action>
            <slot name="action" />
        </template>
        <template v-if="$slots.description" #description>
            <slot name="description" />
        </template>

        <div :class="$slots.trailing ? 'relative' : undefined">
            <input
                :id="inputId"
                type="text"
                :value="modelValue"
                class="field-control"
                :class="$slots.trailing ? 'pr-11' : undefined"
                :required="required || undefined"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy || undefined"
                @input="onInput"
                v-bind="controlAttrs"
            >
            <div
                v-if="$slots.trailing"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center"
            >
                <slot name="trailing" />
            </div>
        </div>
    </FormField>
</template>
