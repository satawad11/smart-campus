<script setup>
/**
 * Webcam capture with live face detection overlay (FACE-022).
 *
 * The browser only grabs frames. Detection and quality run on the AI service through
 * POST /faces/check-quality; this component draws the returned boxes over the video.
 * The camera starts only when the operator presses "เปิดกล้อง" (camera permission step).
 */
import { ref, computed, onBeforeUnmount } from 'vue'
import facesService from '@/services/faces'

const props = defineProps({
  // Poll check-quality while the camera is on.
  analyze: { type: Boolean, default: true },
  intervalMs: { type: Number, default: 700 },
  // Long side of the frame sent for analysis (smaller = faster).
  analyzeMaxSize: { type: Number, default: 640 },
  mirror: { type: Boolean, default: true },
  // Colour of the box when the face is acceptable.
  okLabel: { type: String, default: 'พร้อมถ่าย' },
})

const emit = defineEmits(['quality', 'started', 'stopped', 'error'])

const video = ref(null)
const state = ref('idle') // idle | starting | live | denied | unsupported | insecure | error
const errorText = ref('')
const devices = ref([])
const deviceId = ref('')
const lastCheck = ref(null)
const analysisError = ref('')

let stream = null
let timer = null
let inflight = false
let backoffUntil = 0

const supported = computed(
  () => typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia,
)

const box = computed(() => {
  const c = lastCheck.value
  if (!c || !c.image_width || !c.image_height) return null
  return { w: c.image_width, h: c.image_height, faces: c.faces || [], ok: c.accepted }
})

async function start() {
  errorText.value = ''
  if (typeof window !== 'undefined' && window.isSecureContext === false) {
    state.value = 'insecure'
    return
  }
  if (!supported.value) {
    state.value = 'unsupported'
    return
  }
  state.value = 'starting'
  try {
    stopStream()
    stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: deviceId.value
        ? { deviceId: { exact: deviceId.value }, width: { ideal: 1280 }, height: { ideal: 720 } }
        : { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
    })
    video.value.srcObject = stream
    await video.value.play().catch(() => {})
    state.value = 'live'
    emit('started')
    await loadDevices()
    scheduleAnalysis()
  } catch (err) {
    stopStream()
    if (err?.name === 'NotAllowedError' || err?.name === 'SecurityError') {
      state.value = 'denied'
    } else {
      state.value = 'error'
      errorText.value =
        err?.name === 'NotFoundError' ? 'ไม่พบกล้องในเครื่องนี้' : 'เปิดกล้องไม่สำเร็จ'
    }
    emit('error', err)
  }
}

async function loadDevices() {
  try {
    const all = await navigator.mediaDevices.enumerateDevices()
    devices.value = all.filter((d) => d.kind === 'videoinput')
    if (!deviceId.value) {
      const track = stream?.getVideoTracks?.()[0]
      deviceId.value = track?.getSettings?.().deviceId || ''
    }
  } catch {
    devices.value = []
  }
}

function stopStream() {
  if (stream) {
    stream.getTracks().forEach((t) => t.stop())
    stream = null
  }
}

function stop() {
  clearTimeout(timer)
  timer = null
  stopStream()
  if (video.value) video.value.srcObject = null
  lastCheck.value = null
  state.value = 'idle'
  emit('stopped')
}

function grabFrame(maxSize) {
  const v = video.value
  if (!v || !v.videoWidth) return Promise.resolve(null)
  const scale = maxSize ? Math.min(1, maxSize / Math.max(v.videoWidth, v.videoHeight)) : 1
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(v.videoWidth * scale)
  canvas.height = Math.round(v.videoHeight * scale)
  canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height)
  return new Promise((resolve) => canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.9))
}

function scheduleAnalysis() {
  clearTimeout(timer)
  if (state.value !== 'live' || !props.analyze) return
  timer = setTimeout(analyzeOnce, props.intervalMs)
}

async function analyzeOnce() {
  if (state.value !== 'live') return
  if (inflight || Date.now() < backoffUntil) return scheduleAnalysis()
  inflight = true
  try {
    const blob = await grabFrame(props.analyzeMaxSize)
    if (blob) {
      const res = await facesService.checkQuality(blob)
      lastCheck.value = res.data
      analysisError.value = ''
      emit('quality', res.data)
    }
  } catch (err) {
    // 429: slow down; other errors: keep the camera running and show why.
    backoffUntil = Date.now() + (err?.status === 429 ? 5000 : 2000)
    analysisError.value =
      err?.status === 503 || err?.code === 'AI_SERVICE_UNAVAILABLE'
        ? 'ระบบประมวลผลใบหน้า (AI) ไม่พร้อมใช้งาน'
        : 'ตรวจจับใบหน้าไม่สำเร็จ กำลังลองใหม่'
    lastCheck.value = null
    emit('quality', null)
  } finally {
    inflight = false
    scheduleAnalysis()
  }
}

/** Full-resolution JPEG of the current frame. */
function capture() {
  return grabFrame(0)
}

async function switchDevice() {
  if (state.value === 'live') await start()
}

onBeforeUnmount(() => stop())

defineExpose({ start, stop, capture, state, lastCheck })
</script>

<template>
  <div class="space-y-2">
    <div
      class="relative w-full overflow-hidden rounded-xl border border-gray-200 bg-gray-900"
      style="aspect-ratio: 4 / 3"
    >
      <video
        ref="video"
        class="absolute inset-0 h-full w-full object-contain"
        :class="{ '-scale-x-100': mirror }"
        :style="{ visibility: state === 'live' ? 'visible' : 'hidden' }"
        autoplay
        muted
        playsinline
      ></video>

      <svg
        v-if="state === 'live' && box"
        class="pointer-events-none absolute inset-0 h-full w-full"
        :class="{ '-scale-x-100': mirror }"
        :viewBox="`0 0 ${box.w} ${box.h}`"
        preserveAspectRatio="xMidYMid meet"
        data-test="face-overlay"
      >
        <rect
          v-for="(f, i) in box.faces"
          :key="i"
          :x="f.x1"
          :y="f.y1"
          :width="f.x2 - f.x1"
          :height="f.y2 - f.y1"
          fill="none"
          :stroke="box.ok ? '#22c55e' : '#f59e0b'"
          :stroke-width="Math.max(2, box.w / 200)"
          rx="6"
        />
      </svg>

      <div
        v-if="state === 'live' && box"
        class="absolute left-3 top-3 rounded-full px-2.5 py-1 text-xs font-semibold"
        :class="box.ok ? 'bg-green-600 text-white' : 'bg-amber-500 text-white'"
      >
        {{
          box.faces.length === 0
            ? 'ไม่พบใบหน้า'
            : box.faces.length > 1
              ? `พบ ${box.faces.length} ใบหน้า`
              : box.ok
                ? okLabel
                : 'ปรับตำแหน่งใบหน้า'
        }}
      </div>

      <div
        v-if="state !== 'live'"
        class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-sm text-gray-200"
      >
        <template v-if="state === 'starting'">
          <div
            class="h-8 w-8 animate-spin rounded-full border-2 border-white/30 border-t-white"
          ></div>
          <p>กำลังเปิดกล้อง... หากเบราว์เซอร์ถาม กรุณากด "อนุญาต"</p>
        </template>
        <template v-else-if="state === 'denied'">
          <p class="font-semibold">ไม่ได้รับอนุญาตให้ใช้กล้อง</p>
          <p class="text-xs text-gray-300">
            กดไอคอนกล้องหรือแม่กุญแจที่แถบที่อยู่ของเบราว์เซอร์ เลือก "อนุญาต"
            แล้วกดเปิดกล้องอีกครั้ง
          </p>
        </template>
        <template v-else-if="state === 'insecure'">
          <p class="font-semibold">เบราว์เซอร์อนุญาตให้ใช้กล้องเฉพาะการเชื่อมต่อแบบปลอดภัย</p>
          <p class="text-xs text-gray-300">
            กรุณาเปิดระบบผ่าน https:// (หรือ http://localhost บนเครื่องเซิร์ฟเวอร์)
            หรืออัปโหลดภาพแทน
          </p>
        </template>
        <template v-else-if="state === 'unsupported'">
          <p class="font-semibold">เบราว์เซอร์นี้ไม่รองรับการใช้กล้อง</p>
          <p class="text-xs text-gray-300">
            กรุณาใช้ Chrome, Edge หรือ Firefox รุ่นล่าสุด หรืออัปโหลดภาพแทน
          </p>
        </template>
        <template v-else-if="state === 'error'">
          <p class="font-semibold">{{ errorText }}</p>
        </template>
        <template v-else>
          <p>กล้องยังปิดอยู่</p>
        </template>

        <button
          v-if="state !== 'starting'"
          type="button"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
          data-test="camera-start"
          @click="start"
        >
          เปิดกล้อง
        </button>
      </div>
    </div>

    <div v-if="state === 'live'" class="flex flex-wrap items-center gap-2 text-xs">
      <select
        v-if="devices.length > 1"
        v-model="deviceId"
        class="rounded-lg border border-gray-300 px-2 py-1"
        @change="switchDevice"
      >
        <option v-for="(d, i) in devices" :key="d.deviceId" :value="d.deviceId">
          {{ d.label || `กล้อง ${i + 1}` }}
        </option>
      </select>
      <button
        type="button"
        class="rounded-lg border border-gray-300 px-3 py-1 text-gray-700 hover:bg-gray-50"
        @click="stop"
      >
        ปิดกล้อง
      </button>
      <span v-if="analysisError" class="text-red-600">{{ analysisError }}</span>
    </div>
  </div>
</template>
