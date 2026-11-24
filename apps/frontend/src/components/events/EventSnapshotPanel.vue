<script setup>
/**
 * Event face crop (EVT-025, ACR-EVT-24). The image is loaded only when the
 * operator asks for it: every load is audited by the backend, and the image
 * is never cached or kept after the panel closes.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import { apiErrorText } from '@/constants/labels'

const props = defineProps({
  eventUuid: { type: String, required: true },
  available: { type: Boolean, default: false },
  autoLoad: { type: Boolean, default: false },
})

const authStore = useAuthStore()
const canViewImage = computed(
  () => authStore.can('event.view') && (authStore.can('camera.live') || authStore.can('face.view')),
)

const imageSrc = ref('')
const loading = ref(false)
const errorMessage = ref('')

async function load() {
  if (!canViewImage.value || !props.available || loading.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await eventsService.getEventSnapshot(props.eventUuid)
    const data = res.data || {}
    imageSrc.value = data.image_base64
      ? `data:${data.content_type || 'image/jpeg'};base64,${data.image_base64}`
      : ''
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'เปิดภาพใบหน้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function hide() {
  imageSrc.value = ''
}

watch(
  () => props.eventUuid,
  () => {
    hide()
    errorMessage.value = ''
  },
)

onMounted(() => {
  if (props.autoLoad) load()
})
onBeforeUnmount(hide)
</script>

<template>
  <div data-test="event-snapshot" class="space-y-2">
    <p v-if="!available" data-test="event-snapshot-none" class="text-sm text-gray-500">
      ไม่มีภาพใบหน้าสำหรับเหตุการณ์นี้
    </p>
    <p v-else-if="!canViewImage" data-test="event-snapshot-forbidden" class="text-sm text-gray-500">
      คุณไม่มีสิทธิ์ดูภาพใบหน้า (ต้องมีสิทธิ์ดูกล้องสดหรือดูข้อมูลใบหน้า)
    </p>
    <template v-else>
      <img
        v-if="imageSrc"
        data-test="event-snapshot-image"
        :src="imageSrc"
        alt="ภาพใบหน้าจากเหตุการณ์"
        class="max-h-64 rounded-lg border border-gray-200"
      />
      <div class="flex flex-wrap items-center gap-3">
        <button
          v-if="!imageSrc"
          type="button"
          data-test="event-snapshot-load"
          class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          :disabled="loading"
          @click="load"
        >
          {{ loading ? 'กำลังเปิดภาพ...' : 'ดูภาพ' }}
        </button>
        <button
          v-else
          type="button"
          data-test="event-snapshot-hide"
          class="text-sm text-gray-600 hover:text-gray-900"
          @click="hide"
        >
          ซ่อนภาพ
        </button>
        <span class="text-xs text-gray-400">การเปิดดูภาพทุกครั้งถูกบันทึกในบันทึกการตรวจสอบ</span>
      </div>
      <p v-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>
    </template>
  </div>
</template>
