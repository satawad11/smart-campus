<script setup>
/**
 * Capture indicators (docs/03-ui-ux-blueprint.md §10): face detected, brightness,
 * sharpness, face size, pose — from one POST /faces/check-quality result (FACE-022).
 */
import { computed } from 'vue'

const props = defineProps({
  check: { type: Object, default: null },
})

const items = computed(() => {
  const c = props.check
  const reasons = new Set(c?.rejection_reasons || [])
  const q = c?.quality
  const pct = (v) => (typeof v === 'number' ? `${Math.round(v * 100)}%` : '–')
  const single = c && c.face_count === 1

  return [
    {
      key: 'face',
      label: 'ตรวจพบใบหน้า',
      ok: single,
      value: !c
        ? '–'
        : c.face_count === 0
          ? 'ไม่พบ'
          : c.face_count > 1
            ? `${c.face_count} คน`
            : 'พบ 1 คน',
    },
    {
      key: 'brightness',
      label: 'ความสว่าง',
      ok: single && !!q && !reasons.has('IMAGE_TOO_DARK') && !reasons.has('IMAGE_TOO_BRIGHT'),
      value: reasons.has('IMAGE_TOO_DARK')
        ? 'มืดเกินไป'
        : reasons.has('IMAGE_TOO_BRIGHT')
          ? 'สว่างเกินไป'
          : pct(q?.brightness_score),
    },
    {
      key: 'sharpness',
      label: 'ความคมชัด',
      ok: single && !!q && !reasons.has('IMAGE_BLUR'),
      value: reasons.has('IMAGE_BLUR') ? 'ภาพเบลอ' : pct(q?.blur_score),
    },
    {
      key: 'size',
      label: 'ขนาดใบหน้า',
      ok: single && !!q && !reasons.has('FACE_TOO_SMALL') && !reasons.has('LOW_RESOLUTION'),
      value: reasons.has('FACE_TOO_SMALL') ? 'เข้าใกล้กล้องอีกนิด' : pct(q?.face_size_score),
    },
    {
      key: 'pose',
      label: 'มุมใบหน้า',
      ok: single && !!q && !reasons.has('BAD_POSE'),
      value: reasons.has('BAD_POSE') ? 'หันหน้าตรงกล้อง' : pct(q?.pose_score),
    },
  ]
})
</script>

<template>
  <ul class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-5" data-test="quality-indicators">
    <li
      v-for="item in items"
      :key="item.key"
      class="rounded-lg border px-2 py-1.5"
      :class="
        !check
          ? 'border-gray-200 bg-gray-50 text-gray-500'
          : item.ok
            ? 'border-green-200 bg-green-50 text-green-800'
            : 'border-amber-200 bg-amber-50 text-amber-800'
      "
    >
      <div class="flex items-center gap-1 font-medium">
        <span aria-hidden="true">{{ !check ? '•' : item.ok ? '✓' : '!' }}</span>
        {{ item.label }}
      </div>
      <div class="mt-0.5 font-semibold">{{ item.value }}</div>
    </li>
  </ul>
</template>
