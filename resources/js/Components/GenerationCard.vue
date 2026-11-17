<script setup>
import StatusBadge from '@/Components/StatusBadge.vue';
import { computed } from 'vue';

const props = defineProps({
    generation: { type: Object, required: true },
    // Current time, so a picture can be swapped for the "expired" note while the page is open.
    now: { type: Number, required: true },
});

const IN_PROGRESS = ['pending', 'queued', 'processing'];

const g = computed(() => props.generation);
const inProgress = computed(() => IN_PROGRESS.includes(g.value.status));
const succeeded = computed(() => g.value.status === 'succeeded');
const failed = computed(() => g.value.status === 'failed');

const expired = computed(
    () => succeeded.value && (g.value.imageExpired || (g.value.imageExpiresAt && Date.parse(g.value.imageExpiresAt) <= props.now)),
);
const showImage = computed(() => succeeded.value && !expired.value && g.value.imageUrl);

const when = computed(() => {
    const seconds = Math.round((Date.parse(g.value.createdAt) - props.now) / 1000);
    const units = [['day', 86400], ['hour', 3600], ['minute', 60]];
    const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit);
    }
    return 'just now';
});

const detail = computed(() => {
    const parts = [g.value.size, g.value.quality];
    if (g.value.referenceImageCount > 0) {
        parts.push(`${g.value.referenceImageCount} reference image${g.value.referenceImageCount === 1 ? '' : 's'}`);
    }
    return parts.join(' · ');
});
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <!-- Every card has the same square picture area, whatever shape the image is, so the grid stays even. -->
        <div class="relative aspect-square bg-gray-100">
            <a v-if="showImage" :href="g.imageUrl" target="_blank" rel="noopener" class="block h-full w-full">
                <img :src="g.imageUrl" :alt="g.prompt" loading="lazy" class="h-full w-full object-contain" />
            </a>

            <div
                v-else-if="inProgress"
                class="flex h-full w-full animate-pulse flex-col items-center justify-center gap-1 bg-gradient-to-br from-gray-100 to-gray-200 text-sm text-gray-500"
            >
                <span>{{ g.status === 'processing' ? 'Creating your image…' : 'Waiting in line…' }}</span>
            </div>

            <div v-else-if="expired" class="flex h-full w-full flex-col items-center justify-center gap-1 px-4 text-center text-gray-500">
                <span class="text-sm font-medium text-gray-700">Image expired</span>
                <span class="text-xs">Images are kept for 24 hours.</span>
            </div>

            <div v-else-if="failed" class="flex h-full w-full flex-col items-center justify-center gap-1 px-4 text-center text-red-700">
                <span class="text-sm font-medium">This one did not work out</span>
                <span v-if="g.error" class="text-xs text-red-600">{{ g.error }}</span>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-2 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="g.status" />
                <time :datetime="g.createdAt" :title="new Date(g.createdAt).toLocaleString()" class="text-xs text-gray-500">{{ when }}</time>
            </div>

            <!-- Room for two lines, so a short prompt does not make the card shorter. -->
            <p class="line-clamp-2 min-h-[2.5rem] text-sm leading-5 text-gray-900" :title="g.prompt">{{ g.prompt }}</p>
            <p class="truncate text-xs text-gray-500" :title="detail">{{ detail }}</p>

            <!-- Two lines as well: what it costs, and what that was expected to be. -->
            <div class="mt-auto min-h-[2.75rem] pt-1 text-sm tabular-nums">
                <p v-if="inProgress">
                    <span class="text-gray-500">Estimated</span>
                    <span class="ms-1 font-medium text-gray-900">{{ g.estimate.min }} – {{ g.estimate.max }}</span>
                </p>
                <p v-else-if="g.charge?.free" class="font-medium text-green-700">Not charged</p>
                <template v-else-if="g.charge">
                    <p>
                        <span class="text-gray-500">Charged</span>
                        <span class="ms-1 font-medium text-gray-900" :title="`$${g.charge.exact}`">{{ g.charge.display }}</span>
                    </p>
                    <p class="text-xs text-gray-400">Estimated {{ g.estimate.min }} – {{ g.estimate.max }}</p>
                </template>
            </div>
        </div>
    </article>
</template>
