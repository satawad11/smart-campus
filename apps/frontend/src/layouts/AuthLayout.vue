<script setup>
import { RouterView } from 'vue-router'
import ThemeToggle from '@/components/common/ThemeToggle.vue'
</script>

<template>
  <!-- Centered shell for unauthenticated pages. -->
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-canvas p-4">
    <div
      class="pointer-events-none absolute inset-0 bg-[radial-gradient(60rem_40rem_at_50%_-10%,var(--color-blue-100),transparent)]"
      aria-hidden="true"
    ></div>
    <div class="absolute top-4 right-4">
      <ThemeToggle />
    </div>
    <div
      class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-surface p-8 shadow-xl shadow-gray-900/5"
    >
      <RouterView />
    </div>
  </div>
</template>
