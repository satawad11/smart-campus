<script setup>
import { RouterView } from 'vue-router'
</script>

<template>
  <!-- FND-004 placeholder: centered shell for unauthenticated pages. -->
  <div class="flex min-h-screen items-center justify-center bg-gray-100 p-4">
    <div class="w-full max-w-md rounded-lg bg-white p-8 shadow">
      <RouterView />
    </div>
  </div>
</template>
