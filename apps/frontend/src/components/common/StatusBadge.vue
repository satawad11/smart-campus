<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    required: true,
  },
  size: {
    type: String,
    default: 'md', // 'sm' | 'md' | 'lg'
  },
})

const statusConfig = {
  ACTIVE: {
    label: 'ใช้งานปกติ',
    bgClass: 'bg-green-50 text-green-700 ring-green-600/20',
    dotClass: 'bg-green-500',
  },
  LOCKED: {
    label: 'บัญชีถูกล็อก',
    bgClass: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    dotClass: 'bg-amber-500',
  },
  DISABLED: {
    label: 'ปิดใช้งาน',
    bgClass: 'bg-red-50 text-red-700 ring-red-600/20',
    dotClass: 'bg-red-500',
  },
  EXPIRED: {
    label: 'หมดอายุ',
    bgClass: 'bg-gray-100 text-gray-700 ring-gray-600/20',
    dotClass: 'bg-gray-400',
  },
}

const currentConfig = computed(() => {
  return (
    statusConfig[props.status] || {
      label: props.status || 'ไม่ระบุ',
      bgClass: 'bg-gray-50 text-gray-600 ring-gray-500/10',
      dotClass: 'bg-gray-400',
    }
  )
})
</script>

<template>
  <span
    class="inline-flex items-center gap-x-1.5 rounded-md font-medium ring-1 ring-inset"
    :class="[currentConfig.bgClass, size === 'sm' ? 'px-1.5 py-0.5 text-xs' : 'px-2 py-1 text-xs']"
    :aria-label="`สถานะ: ${currentConfig.label}`"
  >
    <svg
      class="h-1.5 w-1.5 shrink-0"
      :class="currentConfig.dotClass"
      viewBox="0 0 6 6"
      aria-hidden="true"
    >
      <circle cx="3" cy="3" r="3" fill="currentColor" />
    </svg>
    <span>{{ currentConfig.label }}</span>
  </span>
</template>
