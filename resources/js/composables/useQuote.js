import axios from 'axios';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * The price shown while the form is being filled in. It asks the server (which
 * prices exactly as submitting will), a moment after the user stops changing
 * things. Answers that arrive out of order are ignored.
 */
export function useQuote(getInput, delay = 350) {
    const quote = ref(null); // { min: '$0.0140', max: '$0.0219' }
    const loading = ref(true);
    const failed = ref(false);

    let timer = null;
    let latest = 0;

    async function refresh() {
        const ticket = ++latest;
        loading.value = true;

        try {
            const { data } = await axios.post(route('generations.quote'), getInput());

            if (ticket !== latest) return;
            quote.value = data;
            failed.value = false;
        } catch (error) {
            if (ticket !== latest) return;
            // Keep the last good price off the screen: it no longer matches the form.
            quote.value = null;
            failed.value = true;
        } finally {
            if (ticket === latest) loading.value = false;
        }
    }

    function schedule() {
        loading.value = true;
        clearTimeout(timer);
        timer = setTimeout(refresh, delay);
    }

    watch(getInput, schedule, { deep: true, immediate: true });
    onBeforeUnmount(() => clearTimeout(timer));

    return { quote, loading, failed, retry: refresh };
}
