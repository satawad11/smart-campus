<script setup>
/**
 * Live face scan (FACE-022): webcam -> AI detection -> compare with enrolled faces.
 *
 * Shows who the AI identified (MATCH / REVIEW / UNKNOWN). Identification only: it
 * does not record an access event or open anything (Module 05 owns decisions).
 */
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'
import { apiErrorText } from '@/constants/labels'
import WebcamCapture from '@/components/face/WebcamCapture.vue'
import FaceQualityIndicators from '@/components/face/FaceQualityIndicators.vue'

const STABLE_STREAK = 2
const COOLDOWN_MS = 4000
const HISTORY_SIZE = 10

const CLASS_META = {
  MATCH: {
    label: 'พบในระบบ',
    cls: 'border-green-300 bg-green-50 text-green-900',
    badge: 'bg-green-600',
  },
  REVIEW: {
    label: 'ไม่แน่ใจ ต้องตรวจสอบ',
    cls: 'border-amber-300 bg-amber-50 text-amber-900',
    badge: 'bg-amber-500',
  },
  UNKNOWN: {
    label: 'ไม่พบในระบบ',
    cls: 'border-gray-300 bg-gray-50 text-gray-800',
    badge: 'bg-gray-500',
  },
}

const LIVENESS_META = {
  REAL: { label: 'ใบหน้าจริง', cls: 'text-green-700' },
  UNCERTAIN: { label: 'ไม่แน่ใจว่าเป็นใบหน้าจริง', cls: 'text-amber-700' },
  SPOOF: { label: 'สงสัยว่าเป็นภาพถ่าย/หน้าจอ', cls: 'text-red-700' },
  UNAVAILABLE: { label: 'ไม่ได้ตรวจ (โมเดลตรวจใบหน้าจริงไม่พร้อม)', cls: 'text-gray-500' },
}

const authStore = useAuthStore()
const canScan = computed(() => authStore.can('face.test'))

const camera = ref(null)
const check = ref(null)
const autoScan = ref(true)
const scanning = ref(false)
const result = ref(null)
const errorMessage = ref('')
const history = ref([])

let streak = 0
let cooldownUntil = 0

const meta = computed(() => (result.value ? CLASS_META[result.value.classification] : null))
const shownPerson = computed(
  () =>
    result.value?.matched?.person ||
    (result.value?.classification === 'REVIEW' ? result.value.candidates?.[0]?.person : null),
)
const shownSimilarity = computed(
  () => result.value?.matched?.similarity ?? result.value?.candidates?.[0]?.similarity ?? null,
)

function personName(p) {
  return p?.display_name || p?.person_code || 'ไม่ทราบชื่อ'
}

function pct(v) {
  return typeof v === 'number' ? `${(v * 100).toFixed(1)}%` : '–'
}

function onQuality(res) {
  check.value = res
  streak = res?.accepted ? streak + 1 : 0
  if (autoScan.value && streak >= STABLE_STREAK && !scanning.value && Date.now() >= cooldownUntil) {
    scanNow()
  }
}

async function scanNow() {
  if (scanning.value) return
  scanning.value = true
  errorMessage.value = ''
  try {
    const blob = await camera.value?.capture()
    if (!blob) {
      errorMessage.value = 'กรุณาเปิดกล้องก่อนสแกน'
      return
    }
    const res = await facesService.recognize(blob)
    result.value = res.data
    history.value = [
      {
        at: new Date(),
        classification: res.data.classification,
        name: res.data.matched ? personName(res.data.matched.person) : null,
        similarity: res.data.matched?.similarity ?? res.data.candidates?.[0]?.similarity ?? null,
      },
      ...history.value,
    ].slice(0, HISTORY_SIZE)
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'สแกนใบหน้าไม่สำเร็จ')
  } finally {
    scanning.value = false
    streak = 0
    cooldownUntil = Date.now() + COOLDOWN_MS
  }
}

function timeText(d) {
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-6 p-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900">สแกนใบหน้า</h1>
      <p class="text-sm text-gray-500">
        เปิดกล้อง ระบบจะตรวจจับใบหน้าและเปรียบเทียบกับข้อมูลใบหน้าที่ลงทะเบียนไว้
        (ใช้ตรวจสอบตัวตนเท่านั้น ไม่บันทึกการเข้า-ออกและไม่สั่งเปิดประตู)
      </p>
    </div>

    <div v-if="!canScan" class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
      คุณไม่มีสิทธิ์ใช้การสแกนใบหน้า
    </div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-5">
      <div class="space-y-3 rounded-xl border border-gray-200 bg-surface p-4 lg:col-span-3">
        <WebcamCapture ref="camera" ok-label="กำลังสแกน" @quality="onQuality" />
        <FaceQualityIndicators :check="check" />
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            :disabled="scanning"
            data-test="scan-now"
            @click="scanNow"
          >
            {{ scanning ? 'กำลังเปรียบเทียบ...' : 'สแกนตอนนี้' }}
          </button>
          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="autoScan" type="checkbox" />
            สแกนอัตโนมัติเมื่อพบใบหน้าที่ชัดเจน
          </label>
        </div>
      </div>

      <div class="space-y-4 lg:col-span-2">
        <div
          v-if="errorMessage"
          class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
          {{ errorMessage }}
        </div>

        <div
          v-if="result && meta"
          class="space-y-3 rounded-xl border-2 p-5"
          :class="meta.cls"
          data-test="scan-result"
        >
          <span
            class="inline-block rounded-full px-3 py-1 text-xs font-bold text-white"
            :class="meta.badge"
          >
            {{ meta.label }}
          </span>

          <div v-if="shownPerson">
            <div class="text-xl font-bold">{{ personName(shownPerson) }}</div>
            <div class="text-sm opacity-80">
              <span v-if="shownPerson.student_code"
                >รหัสนักเรียน {{ shownPerson.student_code }}</span
              >
              <span v-else-if="shownPerson.person_code"
                >รหัสบุคคล {{ shownPerson.person_code }}</span
              >
            </div>
            <div v-if="result.classification === 'REVIEW'" class="mt-1 text-xs">
              ใกล้เคียงที่สุด — ความคล้ายยังไม่ถึงเกณฑ์ยืนยัน ต้องให้เจ้าหน้าที่ตรวจสอบ
            </div>
          </div>
          <div v-else class="text-sm">ไม่มีใบหน้าในระบบที่คล้ายพอ</div>

          <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="opacity-70">ความคล้าย</dt>
            <dd class="font-semibold">{{ pct(shownSimilarity) }}</dd>
            <dt class="opacity-70">เกณฑ์ยืนยัน</dt>
            <dd>{{ pct(result.thresholds?.match_threshold) }}</dd>
            <dt class="opacity-70">ตรวจใบหน้าจริง</dt>
            <dd :class="LIVENESS_META[result.liveness?.status]?.cls">
              {{ LIVENESS_META[result.liveness?.status]?.label || result.liveness?.status }}
            </dd>
            <dt class="opacity-70">โมเดล</dt>
            <dd>{{ result.model?.code }} v{{ result.model?.version }}</dd>
          </dl>

          <div
            v-if="result.liveness?.status === 'SPOOF'"
            class="rounded bg-red-100 p-2 text-xs text-red-800"
          >
            ระวัง: ภาพนี้อาจไม่ใช่ใบหน้าจริงของบุคคล
          </div>

          <details v-if="result.candidates?.length" class="text-xs">
            <summary class="cursor-pointer">
              ผู้ที่ใกล้เคียง ({{ result.candidates.length }})
            </summary>
            <ol class="mt-2 space-y-1">
              <li
                v-for="c in result.candidates"
                :key="c.face_profile_uuid"
                class="flex justify-between"
              >
                <span>{{ c.rank }}. {{ personName(c.person) }}</span>
                <span class="font-mono">{{ pct(c.similarity) }}</span>
              </li>
            </ol>
          </details>
        </div>

        <div
          v-else-if="!errorMessage"
          class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400"
        >
          ผลการสแกนจะแสดงที่นี่
        </div>

        <div v-if="history.length" class="rounded-xl border border-gray-200 bg-surface p-4">
          <h2 class="mb-2 text-sm font-semibold text-gray-800">การสแกนล่าสุด</h2>
          <ul class="space-y-1 text-xs">
            <li v-for="(h, i) in history" :key="i" class="flex justify-between gap-2">
              <span class="text-gray-500">{{ timeText(h.at) }}</span>
              <span class="flex-1 truncate">{{
                h.name || CLASS_META[h.classification]?.label
              }}</span>
              <span class="font-mono">{{ pct(h.similarity) }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
