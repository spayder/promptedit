<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { useQuote } from '@/composables/useQuote';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    submissionId: { type: String, required: true },
    limits: { type: Object, required: true },
    sizes: { type: Array, required: true },
    qualities: { type: Array, required: true },
});

const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: props.qualities[0],
    reference_images: [],
    submission_id: props.submissionId,
});

const sizeLabels = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };
const qualityHints = {
    low: 'Fastest, cheapest',
    medium: 'Balanced',
    high: 'Most detail, costs more',
};

// Only what changes the price goes to the quote.
const { quote, loading, failed, retry } = useQuote(() => ({
    prompt: form.prompt,
    size: form.size,
    quality: form.quality,
    reference_images: form.reference_images,
}));

const promptLeft = computed(() => props.limits.maxPromptLength - form.prompt.length);
const canAddImage = computed(() => form.reference_images.length < props.limits.maxReferenceImages);
const canSubmit = computed(
    () => form.prompt.trim() !== '' && quote.value !== null && !loading.value && !form.processing,
);

function aspect(size) {
    const [w, h] = size.split('x').map(Number);
    return { width: w >= h ? '2rem' : `${(2 * w) / h}rem`, height: h >= w ? '2rem' : `${(2 * h) / w}rem` };
}

function submit() {
    if (canSubmit.value) form.post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Generate an image</h2>
        </template>

        <form class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8" @submit.prevent="submit">
            <div class="grid gap-8 lg:grid-cols-3">
                <div class="space-y-8 lg:col-span-2">
                    <!-- Prompt -->
                    <section class="rounded-lg bg-white p-6 shadow-sm">
                        <label for="prompt" class="block text-sm font-medium text-gray-900">Prompt</label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            :maxlength="limits.maxPromptLength"
                            placeholder="A lighthouse at dusk, oil painting, warm light"
                            class="mt-2 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <div class="mt-1 flex justify-between text-xs">
                            <InputError :message="form.errors.prompt" />
                            <span
                                class="ms-auto tabular-nums"
                                :class="promptLeft < 100 ? 'text-amber-600' : 'text-gray-500'"
                            >
                                {{ promptLeft }} characters left
                            </span>
                        </div>
                    </section>

                    <!-- Size and quality -->
                    <section class="grid gap-8 rounded-lg bg-white p-6 shadow-sm sm:grid-cols-2">
                        <fieldset>
                            <legend class="text-sm font-medium text-gray-900">Size</legend>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <label
                                    v-for="size in sizes"
                                    :key="size"
                                    class="flex cursor-pointer flex-col items-center gap-2 rounded-md border p-3 text-center text-xs has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-500"
                                    :class="form.size === size ? 'border-indigo-600' : 'border-gray-200 hover:border-gray-300'"
                                >
                                    <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                                    <span class="rounded-sm border-2 border-gray-400" :style="aspect(size)" aria-hidden="true" />
                                    <span class="font-medium text-gray-900">{{ sizeLabels[size] }}</span>
                                    <span class="text-gray-500">{{ size }}</span>
                                </label>
                            </div>
                            <InputError class="mt-1" :message="form.errors.size" />
                        </fieldset>

                        <fieldset>
                            <legend class="text-sm font-medium text-gray-900">Quality</legend>
                            <div class="mt-2 space-y-2">
                                <label
                                    v-for="quality in qualities"
                                    :key="quality"
                                    class="flex cursor-pointer items-center justify-between rounded-md border px-3 py-2 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-500"
                                    :class="form.quality === quality ? 'border-indigo-600 bg-indigo-50' : 'border-gray-200 hover:border-gray-300'"
                                >
                                    <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                                    <span class="font-medium capitalize text-gray-900">{{ quality }}</span>
                                    <span class="text-xs text-gray-500">{{ qualityHints[quality] }}</span>
                                </label>
                            </div>
                            <InputError class="mt-1" :message="form.errors.quality" />
                        </fieldset>
                    </section>

                    <!-- Reference images -->
                    <section class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-sm font-medium text-gray-900">Reference images <span class="font-normal text-gray-500">(optional)</span></h3>
                            <span class="text-xs text-gray-500">{{ form.reference_images.length }} of {{ limits.maxReferenceImages }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Links to images the result should take after. Each one adds to the price.</p>

                        <ul class="mt-3 space-y-2">
                            <li v-for="(url, index) in form.reference_images" :key="index">
                                <div class="flex gap-2">
                                    <input
                                        v-model="form.reference_images[index]"
                                        type="url"
                                        inputmode="url"
                                        :aria-label="`Reference image ${index + 1} address`"
                                        placeholder="https://example.com/photo.jpg"
                                        class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <button
                                        type="button"
                                        class="shrink-0 rounded-md px-3 text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                                        :aria-label="`Remove reference image ${index + 1}`"
                                        @click="form.reference_images.splice(index, 1)"
                                    >
                                        Remove
                                    </button>
                                </div>
                                <InputError class="mt-1" :message="form.errors[`reference_images.${index}`]" />
                            </li>
                        </ul>
                        <InputError class="mt-1" :message="form.errors.reference_images" />

                        <button
                            v-if="canAddImage"
                            type="button"
                            class="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            @click="form.reference_images.push('')"
                        >
                            + Add a reference image
                        </button>
                    </section>
                </div>

                <!-- Price and submit -->
                <aside class="lg:col-span-1">
                    <div class="sticky top-6 rounded-lg bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-medium text-gray-900">Estimated cost</h3>

                        <p class="mt-2 min-h-[2.5rem] text-3xl font-semibold tabular-nums text-gray-900" aria-live="polite">
                            <template v-if="quote">
                                <span :class="loading ? 'opacity-40' : ''">{{ quote.min }} – {{ quote.max }}</span>
                            </template>
                            <span v-else-if="failed" class="text-base font-normal text-red-700">
                                Price unavailable.
                                <button type="button" class="underline" @click="retry">Try again</button>
                            </span>
                            <span v-else class="inline-block h-8 w-48 animate-pulse rounded bg-gray-200" aria-label="Working out the price" />
                        </p>

                        <p class="mt-3 text-xs leading-5 text-gray-600">
                            Part of the cost depends on how much the model thinks about your image, so the final price is only known when it
                            finishes. You are charged that actual amount, always within this range.
                        </p>
                        <p class="mt-2 text-xs font-medium text-green-700">If a generation fails, you pay nothing.</p>

                        <PrimaryButton class="mt-5 w-full justify-center" :disabled="!canSubmit" :class="{ 'opacity-50': !canSubmit }">
                            {{ form.processing ? 'Sending…' : 'Generate' }}
                        </PrimaryButton>
                        <InputError class="mt-2" :message="form.errors.submission_id" />
                    </div>
                </aside>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
