<script setup>
// GATE-007: the Smart Gate block of a gate decision — which gate, what it saw, which plate.
// Display only; the gate never opens a barrier (ACR-VEH-01 D5).
import { computed } from 'vue'
import { GATE_MATCH_RESULT_LABELS } from '../../constants/labels'

const props = defineProps({
  gatePass: { type: Object, required: true },
  compact: { type: Boolean, default: false },
})

const matchLabel = computed(
  () => GATE_MATCH_RESULT_LABELS[props.gatePass.match_result] || props.gatePass.match_result,
)

const matchClass = computed(
  () =>
    ({
      MATCHED: 'bg-green-50 text-green-700',
      MISMATCH: 'bg-amber-50 text-amber-700',
    })[props.gatePass.match_result] || 'bg-gray-100 text-gray-700',
)

const plateText = computed(() => {
  const plate = props.gatePass.plate
  if (!plate) return ''
  const text = plate.plate_display || plate.plate_number || ''
  return plate.plate_province_name ? `${text} ${plate.plate_province_name}` : text
})
</script>

<template>
  <div
    data-test="gate-pass"
    class="flex flex-wrap items-center gap-1.5 text-xs"
    :class="compact ? '' : 'text-sm'"
  >
    <span class="text-gray-500">จุดเข้าออก</span>
    <span class="font-medium text-gray-800">{{ gatePass.gate?.name || '-' }}</span>
    <span data-test="gate-match" class="px-2 py-0.5 rounded-full font-medium" :class="matchClass">
      {{ matchLabel }}
    </span>
    <span v-if="plateText" data-test="gate-plate" class="text-gray-600"
      >ทะเบียน {{ plateText }}</span
    >
  </div>
</template>
