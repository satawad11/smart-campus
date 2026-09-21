<script setup>
import { computed } from 'vue'

const props = defineProps({
  level: {
    type: String,
    required: true,
  },
})

const levelConfig = {
  VOC_CERT: {
    label: 'ปวช.',
    class: 'bg-indigo-50 text-indigo-700 border-indigo-200',
  },
  HIGH_VOC_CERT: {
    label: 'ปวส.',
    class: 'bg-cyan-50 text-cyan-700 border-cyan-200',
  },
  SHORT_COURSE: {
    label: 'ระยะสั้น',
    class: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  },
  OTHER: {
    label: 'อื่นๆ',
    class: 'bg-gray-50 text-gray-700 border-gray-200',
  },
}

const current = computed(() => {
  return (
    levelConfig[props.level] || {
      label: props.level || '-',
      class: 'bg-gray-50 text-gray-700 border-gray-200',
    }
  )
})
</script>

<template>
  <span
    class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium border"
    :class="current.class"
  >
    {{ current.label }}
  </span>
</template>
