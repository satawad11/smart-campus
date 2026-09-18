<script setup>
import { RouterView } from 'vue-router'
</script>

<template>
  <!-- FND-004 placeholder: sidebar + content shell for authenticated pages.
       Real navigation, permission-driven menus, and header arrive later. -->
  <div class="flex min-h-screen bg-gray-50 text-gray-900">
    <aside class="w-56 shrink-0 border-r border-gray-200 bg-white p-4">
      <div class="mb-6 text-lg font-semibold">Smart Campus</div>
      <nav class="text-sm text-gray-500">Navigation placeholder</nav>
    </aside>
    <main class="flex-1 p-6">
      <RouterView />
    </main>
  </div>
</template>
