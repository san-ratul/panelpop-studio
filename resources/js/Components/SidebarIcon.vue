<script setup>
const props = defineProps({
    name: {
        type: String,
        required: true,
    },
});

// Minimal, consistent 20×20 outline icons (one path set, no icon library).
const paths = {
    dashboard: 'M3 4.5A1.5 1.5 0 0 1 4.5 3h4A1.5 1.5 0 0 1 10 4.5v3A1.5 1.5 0 0 1 8.5 9h-4A1.5 1.5 0 0 1 3 7.5v-3ZM12 4.5A1.5 1.5 0 0 1 13.5 3h3A1.5 1.5 0 0 1 18 4.5v3A1.5 1.5 0 0 1 16.5 9h-3A1.5 1.5 0 0 1 12 7.5v-3ZM3 12.5A1.5 1.5 0 0 1 4.5 11h4A1.5 1.5 0 0 1 10 12.5v3A1.5 1.5 0 0 1 8.5 17h-4A1.5 1.5 0 0 1 3 15.5v-3ZM12 12.5A1.5 1.5 0 0 1 13.5 11h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3a1.5 1.5 0 0 1-1.5-1.5v-3Z',
    story: 'M4 4.5A2.5 2.5 0 0 1 6.5 2H8v16H6.5A2.5 2.5 0 0 0 4 20.5v-16ZM10 2h7.5A2.5 2.5 0 0 1 20 4.5v13a2.5 2.5 0 0 1-2.5 2.5H10V2Z',
    storyboard: 'M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm6.5 2.8v6.4l5-3.2-5-3.2ZM18 7h1a2 2 0 0 1 2 2v6.5a2 2 0 0 1-2 2h-1V7Z',
    characters: 'M10 9a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-7 9a7 7 0 0 1 14 0v1H3v-1Zm14.5-8.5a2.75 2.75 0 1 0 0-5.5 2.75 2.75 0 0 0 0 5.5Zm.5 2.1a5.6 5.6 0 0 1 3 4.9v.5h-3.5v-.5c0-1.8-.6-3.5-1.7-4.9h2.2Z',
    audio: 'M17 3v10.55A4 4 0 1 0 19 17V7h2V3h-4ZM8 4h7v2h-5v9.5a3.5 3.5 0 1 1-2-3.16V4Z',
    render: 'M6 3.5 19 10 6 16.5v-13ZM4 19h16v2H4v-2Z',
    providers: 'M7 3h6l1 3h4a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-2v6a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-6H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h1Zm2 2v2h2V5H9Z',
    account: 'M10 2a4 4 0 0 1 4 4v3h1a2 2 0 0 1 2 2v9H3v-9a2 2 0 0 1 2-2h1V6a4 4 0 0 1 4-4Zm0 2a2 2 0 0 0-2 2v3h4V6a2 2 0 0 0-2-2Z',
    overview: 'M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm3 8 2.5-3 2 2.4L13 9l2 4H6Zm11-3h2a2 2 0 0 1 2 2v6.5a2 2 0 0 1-2 2h-2V10Z',
    costs: 'M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm3 8.5 2.5-4 2 2.5 2.5-4.5 2 3.5V14H6v-.5ZM18 8h1a2 2 0 0 1 2 2v6.5a2 2 0 0 1-2 2h-1V8Z',
    reports: 'M4 4h12a1 1 0 0 1 1 1v2h1a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-2H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm3 5v2h6V9H7Zm0 4v2h6v-2H7Z',
    simulation: 'M5 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm1 3v3h8V5H6Zm0 6h2v2H6v-2Zm3.5 0h2v2h-2v-2ZM12 11h2v2h-2v-2ZM6 14.5h2v2H6v-2Zm3.5 0h2v2h-2v-2Zm3.5 0h2v2h-2v-2Z',
};
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 20 20"
        fill="currentColor"
        aria-hidden="true"
        class="h-5 w-5 shrink-0"
    >
        <path :d="paths[props.name] ?? ''" />
    </svg>
</template>
