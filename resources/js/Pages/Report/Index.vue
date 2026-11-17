<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    range: { type: Object, required: true },
    report: { type: Object, required: true },
});

const start = ref(props.range.start);
const end = ref(props.range.end);

function apply() {
    router.get(route('report.index'), { start_date: start.value, end_date: end.value }, { preserveState: true, preserveScroll: true });
}

const banner = computed(() => {
    const r = props.report;

    if (r.reconciliation === 'match') {
        return {
            classes: 'bg-green-50 text-green-900 ring-green-200',
            title: 'Matches Pixelforge',
            text: `Our recorded provider cost for this range, ${r.providerCost} over ${r.generations} generations, is exactly what Pixelforge's own usage report says.`,
        };
    }

    if (r.reconciliation === 'mismatch') {
        return {
            classes: 'bg-red-50 text-red-900 ring-red-200',
            title: 'Does not match Pixelforge',
            text: `We recorded ${r.providerCost} over ${r.generations} generations; Pixelforge reports ${r.provider.cost} over ${r.provider.generations}. The difference is ${r.provider.difference}. See the days marked below.`,
        };
    }

    return {
        classes: 'bg-amber-50 text-amber-900 ring-amber-200',
        title: 'Pixelforge usage report unavailable',
        text: 'We could not reach Pixelforge to compare, so these figures are not checked right now. Try again in a moment.',
    };
});

const badges = {
    match: { label: 'Matches Pixelforge', classes: 'bg-green-50 text-green-700 ring-green-200' },
    mismatch: { label: 'Differs from Pixelforge', classes: 'bg-red-50 text-red-700 ring-red-200' },
    unavailable: { label: 'Not checked', classes: 'bg-amber-50 text-amber-700 ring-amber-200' },
};

const summary = computed(() => [
    { label: 'Generations', value: props.report.generations },
    { label: 'Charged to users', value: props.report.charged },
    { label: 'Pixelforge cost', value: props.report.providerCost },
    { label: 'Profit', value: props.report.profit },
]);
</script>

<template>
    <Head title="Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">Report</h2>
                    <p class="mt-1 text-xs text-gray-500">
                        Succeeded generations, by the UTC day they completed. Failed ones cost nothing and are not counted.
                    </p>
                </div>

                <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="apply">
                    <label class="block">
                        <span class="block text-xs text-gray-500">From</span>
                        <input v-model="start" type="date" :max="range.today" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </label>
                    <label class="block">
                        <span class="block text-xs text-gray-500">To</span>
                        <input v-model="end" type="date" :max="range.today" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </label>
                    <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                        Show
                    </button>
                </form>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <ul v-if="range.errors.length" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200">
                <li v-for="error in range.errors" :key="error">{{ error }}</li>
            </ul>

            <p class="rounded-md px-4 py-3 text-sm ring-1" :class="banner.classes" role="status">
                <span class="font-semibold">{{ banner.title }}.</span> {{ banner.text }}
            </p>

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="item in summary" :key="item.label" class="rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                    <dt class="text-xs text-gray-500">{{ item.label }}</dt>
                    <dd class="mt-1 break-all text-lg font-semibold tabular-nums text-gray-900">{{ item.value }}</dd>
                </div>
            </dl>

            <div v-if="report.days.length === 0" class="rounded-lg bg-white px-6 py-12 text-center text-sm text-gray-500 shadow-sm">
                No finished generations in this range.
            </div>

            <section v-for="day in report.days" :key="day.date" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3">
                    <h3 class="font-semibold tabular-nums text-gray-900">{{ day.date }}</h3>
                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-600">
                        <span v-if="day.provider" class="tabular-nums">
                            Pixelforge: {{ day.provider.generations }} generation{{ day.provider.generations === 1 ? '' : 's' }}, {{ day.provider.cost }}
                            <template v-if="day.reconciliation === 'mismatch'">(difference {{ day.provider.difference }})</template>
                        </span>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 font-medium ring-1 ring-inset" :class="badges[day.reconciliation].classes">
                            {{ badges[day.reconciliation].label }}
                        </span>
                    </div>
                </header>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-gray-500">
                            <tr>
                                <th scope="col" class="px-4 py-2 font-medium">User</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Generations</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Charged</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Pixelforge cost</th>
                                <th scope="col" class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 tabular-nums">
                            <tr v-for="row in day.rows" :key="row.userId" :class="row.isYou ? 'bg-indigo-50/50' : ''">
                                <td class="whitespace-nowrap px-4 py-2 text-gray-900">
                                    {{ row.name }}
                                    <span v-if="row.isYou" class="ms-1 text-xs text-indigo-600">(you)</span>
                                </td>
                                <td class="px-4 py-2 text-right">{{ row.generations }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ row.charged }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ row.providerCost }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ row.profit }}</td>
                            </tr>
                            <tr v-if="day.rows.length === 0">
                                <td colspan="5" class="px-4 py-3 text-center text-gray-500">
                                    We have no record of generations this day, but Pixelforge billed for some.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-gray-200 bg-gray-50 font-medium tabular-nums">
                            <tr>
                                <td class="px-4 py-2 text-gray-700">Day total</td>
                                <td class="px-4 py-2 text-right">{{ day.generations }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ day.charged }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ day.providerCost }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ day.profit }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
