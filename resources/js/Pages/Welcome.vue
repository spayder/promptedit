<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />

    <main class="flex min-h-screen items-center justify-center bg-gray-100 px-4">
        <div class="w-full max-w-md text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-gray-900">Pixelforge Studio</h1>
            <p class="mt-3 text-gray-600">Describe an image, see the price before you start, and watch it appear.</p>
            <p class="mt-1 text-sm font-medium text-green-700">If a generation fails, you pay nothing.</p>

            <div class="mt-8 flex justify-center gap-3">
                <Link
                    :href="route('register')"
                    class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Create an account
                </Link>
                <Link
                    :href="route('login')"
                    class="rounded-md bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                >
                    Log in
                </Link>
            </div>
        </div>
    </main>
</template>
