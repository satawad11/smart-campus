<script setup>
/**
 * Near-live camera view built on the existing snapshot API
 * (POST /cameras/{uuid}/snapshot, permission camera.live).
 *
 * There is no video-streaming server in the locked stack, so this refreshes
 * one frame at a time: the next request is sent only after the previous one
 * finishes (never overlapping), refreshing pauses while the browser tab is
 * hidden, and viewing stops on its own after `maxDurationMs` so an unattended
 * screen does not keep pulling frames (each frame is audited by the backend).
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import camerasService from '@/services/cameras'
import { apiErrorText } from '@/constants/labels'

const props = defineProps({
  cameraUuid: { type: String, required: true },
  cameraName: { type: String, default: '' },
  intervalMs: { type: Number, default: 2000 },
  maxDurationMs: { type: Number, default: 5 * 60 * 1000 },
  autoStart: { type: Boolean, default: true },
})

const SNAPSHOT_ERRORS = {
  CAMERA_AUTH_FAILED: 'ชื่อผู้ใช้หรือรหัสผ่านกล้องไม่ถูกต้อง',
  CAMERA_STREAM_NOT_FOUND: 'ไม่พบสตรีมตาม RTSP URL ของกล้องนี้',
  CAMERA_SNAPSHOT_TIMEOUT: 'กล้องไม่ตอบสนองภายในเวลาที่กำหนด',
  CAMERA_UNREACHABLE: 'เชื่อมต่อกล้องไม่ได้ ตรวจสอบไอพีและเครือข่าย',
  CAMERA_STREAM_URL_INVALID: 'RTSP URL ของกล้องไม่ถูกต้อง',
  CAMERA_SNAPSHOT_FAILED: 'อ่านภาพจากกล้องไม่สำเร็จ',
}
const MAX_CONSECUTIVE_FAILURES = 3

const frame = ref(null)
const running = ref(false)
const loading = ref(false)
const error = ref('')
const stoppedReason = ref('')
const lastUpdated = ref(null)

let timer = null
let startedAt = 0
let failures = 0
let generation = 0

function clearTimer() {
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
}

function schedule(delay) {
  clearTimer()
  timer = setTimeout(tick, delay)
}

async function tick() {
  timer = null
  if (!running.value) return
  if (Date.now() - startedAt >= props.maxDurationMs) {
    stop('หยุดดูภาพสดอัตโนมัติเพื่อประหยัดทรัพยากร กด "ดูภาพสด" เพื่อดูต่อ')
    return
  }
  if (typeof document !== 'undefined' && document.hidden) {
    schedule(props.intervalMs)
    return
  }

  const myGeneration = generation
  loading.value = true
  try {
    const res = await camerasService.captureSnapshot(props.cameraUuid)
    if (myGeneration !== generation) return
    frame.value = `data:${res.data.content_type};base64,${res.data.image_base64}`
    lastUpdated.value = new Date()
    error.value = ''
    failures = 0
  } catch (err) {
    if (myGeneration !== generation) return
    failures += 1
    error.value = SNAPSHOT_ERRORS[err?.code] || apiErrorText(err, 'อ่านภาพจากกล้องไม่สำเร็จ')
    if (err?.status === 403 || err?.status === 404 || failures >= MAX_CONSECUTIVE_FAILURES) {
      stop('')
      return
    }
  } finally {
    if (myGeneration === generation) loading.value = false
  }
  if (running.value && myGeneration === generation) {
    schedule(failures > 0 ? props.intervalMs * 2 : props.intervalMs)
  }
}

function start() {
  generation += 1
  running.value = true
  stoppedReason.value = ''
  error.value = ''
  failures = 0
  startedAt = Date.now()
  schedule(0)
}

function stop(reason = '') {
  generation += 1
  running.value = false
  loading.value = false
  stoppedReason.value = reason
  clearTimer()
}

watch(
  () => props.cameraUuid,
  () => {
    stop('')
    frame.value = null
    lastUpdated.value = null
    if (props.autoStart && props.cameraUuid) start()
  },
)

onMounted(() => {
  if (props.autoStart && props.cameraUuid) start()
})

onBeforeUnmount(() => stop(''))

defineExpose({ start, stop, running })
</script>

<template>
  <div class="camera-live-view space-y-2">
    <div
      class="relative aspect-video bg-zinc-950 ring-1 ring-black/5 rounded-lg overflow-hidden flex items-center justify-center"
    >
      <img
        v-if="frame"
        :src="frame"
        :alt="`ภาพสดจากกล้อง ${cameraName}`"
        class="w-full h-full object-contain"
        data-testid="live-frame"
      />
      <p v-else-if="running" class="text-zinc-400 text-xs">กำลังเชื่อมต่อกล้อง...</p>
      <p v-else class="text-zinc-500 text-xs px-4 text-center">
        {{ cameraName ? `${cameraName} — ` : '' }}กด "ดูภาพสด" เพื่อแสดงภาพจากกล้อง
      </p>

      <span
        v-if="running && frame"
        class="absolute top-2 left-2 inline-flex items-center gap-1 px-2 py-0.5 rounded bg-red-600 text-white text-[10px] font-semibold"
      >
        <span class="w-1.5 h-1.5 rounded-full bg-white" aria-hidden="true"></span>
        สด
      </span>
    </div>

    <div class="flex items-center justify-between gap-2 text-xs">
      <span class="text-gray-500">
        <template v-if="lastUpdated">
          อัปเดตล่าสุด {{ lastUpdated.toLocaleTimeString('th-TH') }}
        </template>
      </span>
      <button
        v-if="running"
        type="button"
        @click="stop('')"
        class="px-3 py-1 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200"
      >
        หยุดภาพสด
      </button>
      <button
        v-else
        type="button"
        @click="start"
        class="px-3 py-1 rounded-lg bg-blue-600 text-white hover:bg-blue-700"
      >
        ดูภาพสด
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-if="stoppedReason" class="text-xs text-gray-500">{{ stoppedReason }}</p>
  </div>
</template>
