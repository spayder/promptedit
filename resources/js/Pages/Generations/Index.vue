<script setup>
import GenerationCard from '@/Components/GenerationCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    generations: { type: Object, required: true },
});

const rows = computed(() => props.generations.data);
const unfinished = computed(() => rows.value.some((g) => ['pending', 'queued', 'processing'].includes(g.status)));

// Ask the server for fresh rows while anything on this page is still being made,
// and stop as soon as everything has finished.
const { start, stop } = usePoll(3000, { only: ['generations'] }, { autoStart: false, keepAlive: false });
watch(unfinished, (busy) => (busy ? start() : stop()), { immediate: true });

// A clock for the "x minutes ago" labels and for retiring pictures whose link has expired.
const now = ref(Date.now());
const tick = setInterval(() => (now.value = Date.now()), 15000);
onBeforeUnmount(() => clearInterval(tick));
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">History</h2>
                <span v-if="unfinished" class="flex items-center gap-2 text-xs text-gray-500">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-blue-500" aria-hidden="true" />
                    Updating automatically
                </span>
            </div>
        </template>

        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="rows.length === 0" class="rounded-lg bg-white px-6 py-16 text-center shadow-sm">
                <p class="text-sm font-medium text-gray-900">Nothing here yet</p>
                <p class="mt-1 text-sm text-gray-500">Your generated images will show up here.</p>
                <Link
                    :href="route('generations.create')"
                    class="mt-4 inline-flex rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                >
                    Generate your first image
                </Link>
            </div>

            <ul v-else class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <li v-for="generation in rows" :key="generation.id" class="flex">
                    <GenerationCard :generation="generation" :now="now" class="w-full" />
                </li>
            </ul>

            <nav v-if="generations.last_page > 1" class="mt-8 flex items-center justify-between text-sm" aria-label="Pages">
                <Link
                    v-if="generations.prev_page_url"
                    :href="generations.prev_page_url"
                    class="rounded-md px-3 py-2 text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                    preserve-scroll
                >
                    ← Newer
                </Link>
                <span v-else />
                <span class="text-gray-500">Page {{ generations.current_page }} of {{ generations.last_page }}</span>
                <Link
                    v-if="generations.next_page_url"
                    :href="generations.next_page_url"
                    class="rounded-md px-3 py-2 text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50"
                    preserve-scroll
                >
                    Older →
                </Link>
                <span v-else />
            </nav>
        </div>
    </AuthenticatedLayout>
</template>
