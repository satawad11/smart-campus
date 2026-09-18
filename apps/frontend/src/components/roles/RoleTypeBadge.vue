<script setup>
defineProps({
  systemRole: {
    type: Boolean,
    default: false,
  },
  size: {
    type: String,
    default: 'md', // 'sm' | 'md'
  },
})
</script>

<template>
  <span
    class="inline-flex items-center gap-x-1.5 rounded-md font-medium ring-1 ring-inset"
    :class="[
      systemRole
        ? 'bg-indigo-50 text-indigo-700 ring-indigo-700/10'
        : 'bg-slate-50 text-slate-700 ring-slate-600/10',
      size === 'sm' ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-xs',
    ]"
    :aria-label="`ประเภทบทบาท: ${systemRole ? 'บทบาทระบบ' : 'บทบาทกำหนดเอง'}`"
  >
    <svg
      v-if="systemRole"
      class="h-3 w-3 shrink-0 text-indigo-600"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
      />
    </svg>
    <svg
      v-else
      class="h-3 w-3 shrink-0 text-slate-500"
      fill="none"
      viewBox="0 0 24 24"
      stroke="currentColor"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
      />
    </svg>
    <span>{{ systemRole ? 'บทบาทระบบ' : 'บทบาทกำหนดเอง' }}</span>
  </span>
</template>
