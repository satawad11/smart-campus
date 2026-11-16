<script setup>
/**
 * Face enrollment (FACE-016, webcam capture FACE-022; docs/03-ui-ux-blueprint.md §10).
 *
 * notice/consent -> camera permission -> capture with live indicators -> enroll ->
 * verification. Upload remains as a fallback when no camera can be used.
 */
import { ref, computed, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'
import { studentsService } from '@/services/students'
import { apiErrorText, statusLabel } from '@/constants/labels'
import WebcamCapture from '@/components/face/WebcamCapture.vue'
import FaceQualityIndicators from '@/components/face/FaceQualityIndicators.vue'

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
const AUTO_CAPTURE_STREAK = 3

const router = useRouter()
const authStore = useAuthStore()

const canEnroll = computed(() => authStore.can('face.enroll'))
const canSearch = computed(() => authStore.can('student.view'))
const canScan = computed(() => authStore.can('face.test'))

// Person
const personQuery = ref('')
const searchResults = ref([])
const searching = ref(false)
const selectedPerson = ref(null)
let searchTimer = null

// Capture
const camera = ref(null)
const consent = ref(false)
const pose = ref('FRONT')
const autoCapture = ref(true)
const check = ref(null)
const streak = ref(0)
const imageBlob = ref(null)
const previewUrl = ref('')
const source = ref('CAMERA')

// Result
const loading = ref(false)
const errorMessage = ref('')
const successData = ref(null)
const duplicateReviewData = ref(null)
const verification = ref(null)
const verifyScan = ref(null)
const verifyScanning = ref(false)

const personUuid = computed(() => selectedPerson.value?.uuid || '')
const readyForCapture = computed(() => !!personUuid.value && consent.value && !successData.value)

function onQueryInput() {
  clearTimeout(searchTimer)
  const q = personQuery.value.trim()
  if (UUID_RE.test(q)) {
    selectPerson({ uuid: q, display_name: q })
    searchResults.value = []
    return
  }
  if (!canSearch.value || q.length < 2) {
    searchResults.value = []
    return
  }
  searchTimer = setTimeout(() => runSearch(q), 300)
}

async function runSearch(q) {
  searching.value = true
  try {
    const res = await studentsService.list({ keyword: q, per_page: 8 })
    searchResults.value = (res.data || []).filter((s) => s.person?.uuid)
  } catch {
    searchResults.value = []
  } finally {
    searching.value = false
  }
}

function selectPerson(p) {
  selectedPerson.value = p
  searchResults.value = []
  errorMessage.value = ''
}

function pickStudent(s) {
  const name =
    s.person.display_name || [s.person.first_name, s.person.last_name].filter(Boolean).join(' ')
  selectPerson({ uuid: s.person.uuid, display_name: name, student_code: s.student_code })
  personQuery.value = name
}

function setImage(blob, src) {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  imageBlob.value = blob
  source.value = src
  previewUrl.value = blob ? URL.createObjectURL(blob) : ''
  errorMessage.value = ''
}

function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return
  setImage(file, 'UPLOAD')
  successData.value = null
  duplicateReviewData.value = null
}

function onQuality(result) {
  check.value = result
  streak.value = result?.accepted ? streak.value + 1 : 0
  if (
    autoCapture.value &&
    readyForCapture.value &&
    !imageBlob.value &&
    !loading.value &&
    streak.value >= AUTO_CAPTURE_STREAK
  ) {
    captureNow()
  }
}

async function captureNow() {
  const blob = await camera.value?.capture()
  if (blob) setImage(blob, 'CAMERA')
}

function retake() {
  setImage(null, 'CAMERA')
  streak.value = 0
}

async function handleEnroll() {
  if (!personUuid.value) {
    errorMessage.value = 'กรุณาเลือกบุคคลที่จะลงทะเบียน'
    return
  }
  if (!consent.value) {
    errorMessage.value = 'กรุณายืนยันว่าได้รับความยินยอมในการเก็บข้อมูลใบหน้าแล้ว'
    return
  }
  if (!imageBlob.value) {
    errorMessage.value = 'กรุณาเลือกหรือถ่ายภาพใบหน้า'
    return
  }

  loading.value = true
  errorMessage.value = ''
  successData.value = null
  duplicateReviewData.value = null
  verification.value = null
  verifyScan.value = null

  const formData = new FormData()
  if (source.value === 'UPLOAD') formData.append('image', imageBlob.value)
  else formData.append('image', imageBlob.value, 'capture.jpg')
  formData.append('pose', pose.value)
  formData.append('source', source.value)

  try {
    const res = await facesService.enrollFace(formData, personUuid.value)
    successData.value = res.data

    try {
      const dupRes = await facesService.checkDuplicateReview(personUuid.value, true)
      if (dupRes?.data?.duplicate_found) duplicateReviewData.value = dupRes.data
    } catch {
      // Duplicate review check failure is non-fatal to completed enrollment
    }
    try {
      const ver = await facesService.verifyEnrollment(new FormData(), personUuid.value, true)
      verification.value = ver.data
    } catch {
      verification.value = { verified: false }
    }
  } catch (err) {
    errorMessage.value = apiErrorText(
      err,
      'ลงทะเบียนใบหน้าไม่สำเร็จ กรุณาตรวจสอบคุณภาพภาพแล้วลองใหม่',
    )
  } finally {
    loading.value = false
  }
}

/** Verification capture: scan a fresh frame and confirm it matches the enrolled person. */
async function runVerifyScan() {
  verifyScanning.value = true
  verifyScan.value = null
  try {
    const blob = await camera.value?.capture()
    if (!blob) {
      verifyScan.value = { error: 'กรุณาเปิดกล้องก่อนสแกนยืนยัน' }
      return
    }
    const res = await facesService.recognize(blob)
    const d = res.data
    verifyScan.value = {
      classification: d.classification,
      samePerson: d.matched?.person?.uuid === personUuid.value,
      similarity: d.matched?.similarity ?? d.candidates?.[0]?.similarity ?? null,
    }
  } catch (err) {
    verifyScan.value = { error: apiErrorText(err, 'สแกนยืนยันไม่สำเร็จ') }
  } finally {
    verifyScanning.value = false
  }
}

function resetForm() {
  setImage(null, 'CAMERA')
  selectedPerson.value = null
  personQuery.value = ''
  consent.value = false
  successData.value = null
  duplicateReviewData.value = null
  verification.value = null
  verifyScan.value = null
  errorMessage.value = ''
  streak.value = 0
}

onBeforeUnmount(() => {
  clearTimeout(searchTimer)
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <div class="face-enrollment-container mx-auto max-w-6xl space-y-6 p-6">
    <div class="flex items-center justify-between border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ลงทะเบียนใบหน้า</h1>
        <p class="text-sm text-gray-500">
          เลือกบุคคล เปิดกล้อง แล้วให้ระบบตรวจจับใบหน้าและถ่ายภาพเมื่อคุณภาพผ่านเกณฑ์
        </p>
      </div>
      <button
        @click="router.push({ name: 'faces.index' })"
        class="rounded-lg border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
      >
        ดูข้อมูลใบหน้าทั้งหมด
      </button>
    </div>

    <div v-if="!canEnroll" class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-700">
      คุณไม่มีสิทธิ์ลงทะเบียนใบหน้า
    </div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-5">
      <!-- Person + consent -->
      <div class="space-y-4 rounded-xl border border-gray-200 bg-surface p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold text-gray-800">1. บุคคลและภาพตัวอย่าง</h2>

        <div class="relative">
          <label class="mb-1 block text-sm font-medium text-gray-700">
            ค้นหาบุคคล <span class="text-red-500">*</span>
          </label>
          <input
            v-model="personQuery"
            type="text"
            :placeholder="
              canSearch ? 'ชื่อ หรือรหัสนักเรียน (หรือวางรหัสบุคคล UUID)' : 'รหัสบุคคล (UUID)'
            "
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500"
            data-test="person-search"
            @input="onQueryInput"
          />
          <p v-if="searching" class="mt-1 text-xs text-gray-500">กำลังค้นหา...</p>
          <ul
            v-if="searchResults.length"
            class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-gray-200 bg-surface shadow-lg"
          >
            <li v-for="s in searchResults" :key="s.uuid">
              <button
                type="button"
                class="w-full px-3 py-2 text-left text-sm hover:bg-blue-50"
                @click="pickStudent(s)"
              >
                <span class="font-medium">
                  {{ s.person.display_name || `${s.person.first_name} ${s.person.last_name}` }}
                </span>
                <span class="ml-2 text-xs text-gray-500">{{ s.student_code }}</span>
              </button>
            </li>
          </ul>
        </div>

        <div
          v-if="selectedPerson"
          class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"
          data-test="selected-person"
        >
          <div class="font-semibold">{{ selectedPerson.display_name }}</div>
          <div v-if="selectedPerson.student_code" class="text-xs">
            รหัสนักเรียน {{ selectedPerson.student_code }}
          </div>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">ท่าทางใบหน้า</label>
          <select v-model="pose" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="FRONT">หน้าตรง</option>
            <option value="LEFT_SLIGHT">หันซ้ายเล็กน้อย</option>
            <option value="RIGHT_SLIGHT">หันขวาเล็กน้อย</option>
          </select>
        </div>

        <label
          class="flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700"
        >
          <input v-model="consent" type="checkbox" class="mt-0.5" data-test="consent" />
          <span>
            ได้แจ้งวัตถุประสงค์และได้รับความยินยอมจากเจ้าของใบหน้า (หรือผู้ปกครอง)
            ในการเก็บข้อมูลชีวมิติเพื่อใช้ยืนยันตัวตนในสถานศึกษาแล้ว
          </span>
        </label>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">หรืออัปโหลดภาพใบหน้า</label>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="onFileSelected"
            class="w-full text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100"
          />
        </div>
      </div>

      <!-- Camera / preview / result -->
      <div class="space-y-4 rounded-xl border border-gray-200 bg-surface p-6 lg:col-span-3">
        <h2 class="text-lg font-semibold text-gray-800">2. ถ่ายภาพและตรวจคุณภาพ</h2>

        <div v-show="!previewUrl || successData">
          <WebcamCapture ref="camera" ok-label="คุณภาพผ่าน" @quality="onQuality" />
        </div>

        <template v-if="!successData">
          <FaceQualityIndicators v-if="!previewUrl" :check="check" />

          <div v-if="!previewUrl" class="flex flex-wrap items-center gap-3">
            <button
              type="button"
              class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="!check?.accepted"
              data-test="capture"
              @click="captureNow"
            >
              ถ่ายภาพ
            </button>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input v-model="autoCapture" type="checkbox" />
              ถ่ายอัตโนมัติเมื่อคุณภาพผ่าน
            </label>
            <span v-if="!readyForCapture" class="text-xs text-gray-500">
              เลือกบุคคลและยืนยันความยินยอมก่อน ระบบจึงจะถ่ายอัตโนมัติ
            </span>
          </div>

          <div v-if="previewUrl" class="space-y-3">
            <img
              :src="previewUrl"
              alt="ภาพใบหน้าที่จะลงทะเบียน"
              class="max-h-80 w-full rounded-lg border object-contain"
            />
            <div class="flex gap-3">
              <button
                @click="handleEnroll"
                :disabled="loading || !imageBlob || !personUuid || !consent"
                class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                data-test="enroll"
              >
                <span v-if="loading">กำลังประมวลผลภาพ...</span>
                <span v-else>ลงทะเบียนใบหน้า</span>
              </button>
              <button
                type="button"
                class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50"
                :disabled="loading"
                @click="retake"
              >
                ถ่ายใหม่
              </button>
            </div>
          </div>
        </template>

        <div
          v-if="errorMessage"
          class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
        >
          {{ errorMessage }}
        </div>

        <div v-if="successData" class="space-y-4">
          <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">
            <p class="font-bold">✓ ลงทะเบียนใบหน้าสำเร็จ</p>
            <p class="text-xs text-green-700">
              {{ selectedPerson?.display_name }} — สร้างและเปิดใช้งานข้อมูลใบหน้าเรียบร้อยแล้ว
            </p>
          </div>

          <div class="space-y-2 rounded-lg bg-gray-50 p-4 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500">สถานะ:</span>
              <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-bold text-green-800">
                {{ statusLabel(successData.status) }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">โมเดลที่ใช้:</span>
              <span class="font-medium">
                {{ successData.model?.code }} v{{ successData.model?.version }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">คะแนนคุณภาพ:</span>
              <span class="font-bold text-blue-600">
                {{ (successData.quality_score * 100).toFixed(1) }}%
              </span>
            </div>
            <div v-if="verification" class="flex justify-between">
              <span class="text-gray-500">ตรวจสอบข้อมูลที่บันทึก:</span>
              <span
                :class="verification.verified ? 'text-green-700' : 'text-red-700'"
                class="font-medium"
              >
                {{ verification.verified ? 'ผ่าน' : 'ไม่ผ่าน' }}
              </span>
            </div>
          </div>

          <div
            v-if="duplicateReviewData"
            class="rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-yellow-800"
          >
            <p class="text-sm font-bold">⚠️ พบใบหน้าที่อาจซ้ำกับบุคคลอื่น</p>
            <p class="mt-1 text-xs">
              พบบุคคลที่ใบหน้าคล้ายกัน
              {{ (duplicateReviewData.top_candidate?.similarity * 100).toFixed(1) }}%
              ระบบสร้างรายการ ให้ผู้ดูแลตรวจสอบแล้ว
            </p>
          </div>

          <div v-if="canScan" class="space-y-2 rounded-lg border border-gray-200 p-4">
            <p class="text-sm font-semibold text-gray-800">3. สแกนยืนยัน</p>
            <p class="text-xs text-gray-500">
              ให้เจ้าของใบหน้าอยู่หน้ากล้อง แล้วกดสแกน ระบบจะเทียบกับข้อมูลที่เพิ่งลงทะเบียน
            </p>
            <button
              type="button"
              class="rounded-lg bg-gray-800 px-4 py-2 text-sm font-medium text-white hover:bg-gray-900 disabled:opacity-50"
              :disabled="verifyScanning"
              @click="runVerifyScan"
            >
              {{ verifyScanning ? 'กำลังสแกน...' : 'สแกนยืนยัน' }}
            </button>
            <div v-if="verifyScan?.error" class="text-sm text-red-700">{{ verifyScan.error }}</div>
            <div
              v-else-if="verifyScan"
              class="text-sm"
              :class="verifyScan.samePerson ? 'text-green-700' : 'text-amber-700'"
            >
              {{
                verifyScan.samePerson
                  ? '✓ ระบบจดจำได้ว่าเป็นบุคคลนี้'
                  : verifyScan.classification === 'MATCH'
                    ? 'ระบบจับคู่กับบุคคลอื่น กรุณาตรวจสอบ'
                    : 'ระบบยังไม่มั่นใจ ลองสแกนอีกครั้งในแสงที่เหมาะสม'
              }}
              <span v-if="verifyScan.similarity !== null" class="text-xs text-gray-500">
                (ความคล้าย {{ (verifyScan.similarity * 100).toFixed(1) }}%)
              </span>
            </div>
          </div>

          <button
            @click="resetForm"
            class="w-full rounded-lg bg-gray-100 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200"
          >
            ลงทะเบียนบุคคลถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
