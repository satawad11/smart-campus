<script setup>
import { useThemeStore } from '@/stores/theme'

defineProps({
  /** 'segmented' shows light / dark / system; 'icon' is a compact light↔dark toggle. */
  variant: { type: String, default: 'segmented' },
})

const theme = useThemeStore()

const OPTIONS = [
  { value: 'light', label: 'โหมดสว่าง' },
  { value: 'dark', label: 'โหมดมืด' },
  { value: 'system', label: 'ตามระบบ' },
]
</script>

<template>
  <button
    v-if="variant === 'icon'"
    type="button"
    data-testid="theme-toggle"
    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-surface text-gray-600 shadow-sm transition hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
    :title="theme.isDark ? 'เปลี่ยนเป็นโหมดสว่าง' : 'เปลี่ยนเป็นโหมดมืด'"
    :aria-label="theme.isDark ? 'เปลี่ยนเป็นโหมดสว่าง' : 'เปลี่ยนเป็นโหมดมืด'"
    @click="theme.toggle()"
  >
    <svg v-if="theme.isDark" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"
      />
    </svg>
    <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"
      />
    </svg>
  </button>

  <div
    v-else
    role="radiogroup"
    aria-label="รูปแบบการแสดงผล"
    data-testid="theme-switcher"
    class="inline-flex items-center gap-0.5 rounded-lg border border-gray-200 bg-gray-100 p-0.5"
  >
    <button
      v-for="opt in OPTIONS"
      :key="opt.value"
      type="button"
      role="radio"
      :aria-checked="theme.mode === opt.value"
      :title="opt.label"
      :aria-label="opt.label"
      :data-mode="opt.value"
      class="inline-flex h-7 w-8 items-center justify-center rounded-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
      :class="
        theme.mode === opt.value
          ? 'bg-surface text-gray-900 shadow-sm ring-1 ring-gray-200'
          : 'text-gray-500 hover:text-gray-800'
      "
      @click="theme.setMode(opt.value)"
    >
      <svg
        v-if="opt.value === 'light'"
        class="h-4 w-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"
        />
      </svg>
      <svg
        v-else-if="opt.value === 'dark'"
        class="h-4 w-4"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"
        />
      </svg>
      <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
        />
      </svg>
    </button>
  </div>
</template>
