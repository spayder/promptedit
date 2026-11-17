<script setup>
import { computed } from 'vue';

const props = defineProps({
    // pending (ours, not yet sent) is shown to people as "Queued".
    status: { type: String, required: true },
});

const look = computed(
    () =>
        ({
            pending: { label: 'Queued', classes: 'bg-gray-100 text-gray-700 ring-gray-300' },
            queued: { label: 'Queued', classes: 'bg-gray-100 text-gray-700 ring-gray-300' },
            processing: { label: 'Processing', classes: 'bg-blue-50 text-blue-700 ring-blue-200' },
            succeeded: { label: 'Succeeded', classes: 'bg-green-50 text-green-700 ring-green-200' },
            failed: { label: 'Failed', classes: 'bg-red-50 text-red-700 ring-red-200' },
        })[props.status] ?? { label: props.status, classes: 'bg-gray-100 text-gray-700 ring-gray-300' },
);

const inProgress = computed(() => ['pending', 'queued', 'processing'].includes(props.status));
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="look.classes"
    >
        <span
            v-if="inProgress"
            class="h-1.5 w-1.5 animate-pulse rounded-full bg-current"
            aria-hidden="true"
        />
        {{ look.label }}
    </span>
</template>
