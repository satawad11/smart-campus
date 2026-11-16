<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { useFeaturesStore } from '@/stores/features'
import { apiErrorText, onOff } from '@/constants/labels'

const router = useRouter()
const featuresStore = useFeaturesStore()
featuresStore.load()

const step = ref(1)
const steps = ['ข้อมูลทั่วไป', 'การเชื่อมต่อ', 'ตั้งค่าปัญญาประดิษฐ์', 'ตรวจทาน']

const form = reactive({
  site_uuid: '',
  zone_uuid: '',
  ai_node_uuid: '',
  camera_code: '',
  name: '',
  camera_type: '',
  ip_address: '',
  stream_protocol: 'rtsp',
  rtsp_url: '',
  username: '',
  password: '',
  onvif_url: '',
  face_detection_enabled: true,
  face_recognition_enabled: true,
  liveness_enabled: false,
  snapshot_enabled: true,
  plate_recognition_enabled: false,
})

const aiNodes = ref([])
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

// Site/zone/code are filled in automatically from the server.
const sites = ref([])
const optionsLoaded = ref(false)
const canProvisionDefault = ref(false)
const suggestedCode = ref('')

const selectedSite = computed(() => sites.value.find((s) => s.uuid === form.site_uuid) || null)
const zones = computed(() => selectedSite.value?.zones || [])
const selectedZone = computed(() => zones.value.find((z) => z.uuid === form.zone_uuid) || null)
const selectedNode = computed(() => aiNodes.value.find((n) => n.uuid === form.ai_node_uuid) || null)

watch(
  () => form.site_uuid,
  () => {
    if (!zones.value.some((z) => z.uuid === form.zone_uuid)) {
      form.zone_uuid = zones.value[0]?.uuid || ''
    }
  },
)

async function fetchFormOptions() {
  try {
    const res = await camerasService.getFormOptions()
    const data = res.data || {}
    sites.value = data.sites || []
    canProvisionDefault.value = !!data.can_provision_default_location
    suggestedCode.value = data.suggested_camera_code || ''
    if (!form.camera_code) form.camera_code = suggestedCode.value
    if (!form.site_uuid && sites.value.length) form.site_uuid = sites.value[0].uuid
    if (!form.zone_uuid) form.zone_uuid = zones.value[0]?.uuid || ''
  } catch {
    // Non-fatal: the server still fills site/zone/code when they are blank.
    canProvisionDefault.value = true
  } finally {
    optionsLoaded.value = true
  }
}

// Live preview of the stream before saving.
const preview = ref(null)
const previewLoading = ref(false)
const previewError = ref('')
const previewKey = ref('')

const PREVIEW_ERRORS = {
  CAMERA_AUTH_FAILED: 'ชื่อผู้ใช้หรือรหัสผ่านกล้องไม่ถูกต้อง',
  CAMERA_STREAM_NOT_FOUND: 'ไม่พบสตรีมตาม URL นี้ ตรวจสอบพาธของ RTSP URL',
  CAMERA_SNAPSHOT_TIMEOUT: 'กล้องไม่ตอบสนองภายในเวลาที่กำหนด',
  CAMERA_UNREACHABLE: 'เชื่อมต่อกล้องไม่ได้ ตรวจสอบไอพีและเครือข่าย',
  CAMERA_STREAM_URL_INVALID: 'URL ต้องขึ้นต้นด้วย rtsp:// rtsps:// http:// หรือ https://',
  CAMERA_PREVIEW_UNAVAILABLE: 'เซิร์ฟเวอร์ยังไม่รองรับการดูภาพตัวอย่าง',
  CAMERA_SNAPSHOT_FAILED: 'อ่านภาพจากกล้องไม่สำเร็จ',
}

const connectionKey = computed(() => JSON.stringify([form.rtsp_url, form.username, form.password]))
watch(connectionKey, (key) => {
  if (previewKey.value && previewKey.value !== key) {
    preview.value = null
    previewError.value = ''
    previewKey.value = ''
  }
})

function suggestRtspUrl() {
  if (!form.rtsp_url && form.ip_address) form.rtsp_url = `rtsp://${form.ip_address}:554/`
}

async function loadPreview() {
  if (!form.rtsp_url) {
    previewError.value = 'กรุณาระบุ RTSP URL ก่อนดูภาพ'
    return
  }
  previewLoading.value = true
  previewError.value = ''
  preview.value = null
  const key = connectionKey.value
  try {
    const res = await camerasService.previewStream({
      rtsp_url: form.rtsp_url,
      username: form.username || null,
      password: form.password || null,
    })
    preview.value = `data:${res.data.content_type};base64,${res.data.image_base64}`
  } catch (err) {
    previewError.value = PREVIEW_ERRORS[err?.code] || apiErrorText(err, 'อ่านภาพจากกล้องไม่สำเร็จ')
  } finally {
    previewKey.value = key
    previewLoading.value = false
  }
}

const stepError = ref('')

async function fetchAiNodes() {
  try {
    const res = await aiNodesService.listNodes({ per_page: 100 })
    aiNodes.value = res.data || []
  } catch {
    // Non-fatal: AI node assignment remains optional/manual.
  }
}

function nextStep() {
  stepError.value = ''
  if (step.value === 1 && !form.name.trim()) {
    stepError.value = 'กรุณาระบุชื่อกล้อง'
    return
  }
  if (step.value < steps.length) step.value++
  if (step.value === steps.length && form.rtsp_url && previewKey.value !== connectionKey.value) {
    loadPreview()
  }
}

function prevStep() {
  if (step.value > 1) step.value--
}

async function submit() {
  loading.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  const payload = {
    site_uuid: form.site_uuid || null,
    zone_uuid: form.zone_uuid || null,
    ai_node_uuid: form.ai_node_uuid || null,
    camera_code: form.camera_code.trim() || null,
    name: form.name,
    camera_type: form.camera_type || null,
    ip_address: form.ip_address || null,
    stream_protocol: form.stream_protocol || null,
    rtsp_url: form.rtsp_url || null,
    username: form.username || null,
    password: form.password || null,
    onvif_url: form.onvif_url || null,
    face_detection_enabled: form.face_detection_enabled,
    face_recognition_enabled: form.face_recognition_enabled,
    liveness_enabled: form.liveness_enabled,
    snapshot_enabled: form.snapshot_enabled,
    plate_recognition_enabled: featuresStore.isOn('alpr') && form.plate_recognition_enabled,
  }

  try {
    const res = await camerasService.createCamera(payload)
    router.push({ name: 'cameras.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'เพิ่มกล้องไม่สำเร็จ')
    fieldErrors.value = err.fields || {}
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchFormOptions()
  fetchAiNodes()
})
</script>

<template>
  <div class="camera-create-view max-w-3xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900">เพิ่มกล้อง</h1>
      <p class="text-sm text-gray-500">
        ข้อมูลทั่วไป &rarr; การเชื่อมต่อ &rarr; ตั้งค่าปัญญาประดิษฐ์ &rarr; ตรวจทาน
      </p>
    </div>

    <div class="flex items-center gap-2 text-sm">
      <span
        v-for="(label, index) in steps"
        :key="label"
        class="px-3 py-1 rounded-full font-medium"
        :class="step === index + 1 ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-500'"
      >
        {{ index + 1 }}. {{ label }}
      </span>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4">
      <!-- Step 1: General -->
      <div v-if="step === 1" class="space-y-4">
        <div
          v-if="optionsLoaded && !sites.length"
          data-testid="auto-location-note"
          class="p-3 rounded-lg text-sm bg-blue-50 border border-blue-200 text-blue-800"
        >
          <template v-if="canProvisionDefault">
            ยังไม่มีพื้นที่ในระบบ ระบบจะสร้าง "พื้นที่หลัก" และ "โซนทั่วไป"
            ให้อัตโนมัติเมื่อบันทึกกล้อง
          </template>
          <template v-else>บัญชีนี้ยังไม่มีพื้นที่ที่ได้รับสิทธิ์ กรุณาติดต่อผู้ดูแลระบบ</template>
        </div>
        <div v-if="sites.length" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">พื้นที่</label>
            <select
              v-model="form.site_uuid"
              data-testid="site-select"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-for="site in sites" :key="site.uuid" :value="site.uuid">
                {{ site.name }} ({{ site.code }})
              </option>
            </select>
            <p v-if="fieldErrors.site_uuid" class="text-xs text-red-600 mt-1">
              {{ fieldErrors.site_uuid[0] }}
            </p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">โซน</label>
            <select
              v-model="form.zone_uuid"
              data-testid="zone-select"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-if="!zones.length" value="">สร้างโซนทั่วไปให้อัตโนมัติ</option>
              <option v-for="zone in zones" :key="zone.uuid" :value="zone.uuid">
                {{ zone.name }} ({{ zone.code }})
              </option>
            </select>
            <p v-if="fieldErrors.zone_uuid" class="text-xs text-red-600 mt-1">
              {{ fieldErrors.zone_uuid[0] }}
            </p>
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รหัสกล้อง</label>
          <input
            v-model="form.camera_code"
            type="text"
            data-testid="camera-code"
            :placeholder="suggestedCode || 'สร้างอัตโนมัติ'"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <p class="text-xs text-gray-500 mt-1">
            ระบบสร้างรหัสให้อัตโนมัติ แก้ไขได้ถ้าต้องการ (เว้นว่างเพื่อให้ระบบกำหนด)
          </p>
          <p v-if="fieldErrors.camera_code" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.camera_code[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อกล้อง *</label>
          <input
            v-model="form.name"
            type="text"
            placeholder="เช่น ประตูหน้า"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <p v-if="fieldErrors.name" class="text-xs text-red-600 mt-1">{{ fieldErrors.name[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ประเภทกล้อง</label>
          <input
            v-model="form.camera_type"
            type="text"
            list="camera-type-options"
            placeholder="เช่น fixed, ptz"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <datalist id="camera-type-options">
            <option value="fixed" />
            <option value="dome" />
            <option value="bullet" />
            <option value="ptz" />
          </datalist>
        </div>
      </div>

      <!-- Step 2: Connection -->
      <div v-if="step === 2" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ที่อยู่ไอพี</label>
          <input
            v-model="form.ip_address"
            type="text"
            placeholder="192.168.1.10"
            @blur="suggestRtspUrl"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <p v-if="fieldErrors.ip_address" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.ip_address[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">โปรโตคอลสตรีม</label>
          <input
            v-model="form.stream_protocol"
            type="text"
            placeholder="rtsp"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">RTSP URL</label>
          <input
            v-model="form.rtsp_url"
            type="text"
            placeholder="rtsp://camera-host:554/stream1"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ใช้กล้อง</label>
            <input
              v-model="form.username"
              type="text"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่านกล้อง</label>
            <input
              v-model="form.password"
              type="password"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ONVIF URL</label>
          <input
            v-model="form.onvif_url"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>
        <div class="pt-2 space-y-3">
          <button
            type="button"
            data-testid="preview-button"
            :disabled="previewLoading"
            class="px-4 py-2 text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 disabled:opacity-50"
            @click="loadPreview"
          >
            <span v-if="previewLoading">กำลังดึงภาพจากกล้อง...</span>
            <span v-else>{{ preview ? 'ดึงภาพใหม่' : 'ดูภาพจากกล้อง' }}</span>
          </button>
          <p v-if="previewError" data-testid="preview-error" class="text-sm text-red-600">
            {{ previewError }}
          </p>
          <img
            v-if="preview"
            :src="preview"
            alt="ภาพตัวอย่างจากกล้อง"
            data-testid="preview-image"
            class="w-full rounded-lg border border-gray-200 bg-black"
          />
        </div>
      </div>

      <!-- Step 3: AI Settings -->
      <div v-if="step === 3" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">หน่วยประมวลผลที่ดูแล</label>
          <select v-model="form.ai_node_uuid" class="w-full px-3 py-2 border rounded-lg text-sm">
            <option value="">ยังไม่กำหนด</option>
            <option v-for="node in aiNodes" :key="node.uuid" :value="node.uuid">
              {{ node.name }} ({{ node.node_code }})
            </option>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.face_detection_enabled" type="checkbox" />
          เปิดการตรวจจับใบหน้า
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.face_recognition_enabled" type="checkbox" />
          เปิดการจดจำใบหน้า
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.liveness_enabled" type="checkbox" />
          เปิดการตรวจสอบใบหน้าจริง
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.snapshot_enabled" type="checkbox" />
          เปิดการบันทึกภาพนิ่ง
        </label>
        <label v-if="featuresStore.isOn('alpr')" class="flex items-center gap-2 text-sm">
          <input
            v-model="form.plate_recognition_enabled"
            id="plate-recognition-toggle"
            type="checkbox"
          />
          อ่านป้ายทะเบียน
        </label>
      </div>

      <!-- Step 4: Review -->
      <div v-if="step === 4" class="space-y-2 text-sm">
        <div class="pb-3 space-y-2">
          <p class="font-medium">ภาพจากกล้องก่อนบันทึก</p>
          <p v-if="!form.rtsp_url" class="text-gray-500">
            ยังไม่ได้ระบุ RTSP URL จึงยังดูภาพไม่ได้ (บันทึกได้และเพิ่มภายหลังได้)
          </p>
          <template v-else>
            <div
              v-if="previewLoading"
              class="w-full aspect-video rounded-lg border border-gray-200 bg-gray-100 flex items-center justify-center text-gray-500"
            >
              กำลังดึงภาพจากกล้อง...
            </div>
            <img
              v-else-if="preview"
              :src="preview"
              alt="ภาพตัวอย่างจากกล้อง"
              data-testid="review-preview-image"
              class="w-full rounded-lg border border-gray-200 bg-black"
            />
            <div
              v-else-if="previewError"
              class="p-3 rounded-lg bg-yellow-50 border border-yellow-200 text-yellow-800"
            >
              {{ previewError }} — ยังบันทึกได้ แต่ควรตรวจสอบการเชื่อมต่อก่อน
            </div>
            <button
              type="button"
              :disabled="previewLoading"
              class="px-3 py-1.5 text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 disabled:opacity-50"
              @click="loadPreview"
            >
              ดึงภาพใหม่
            </button>
          </template>
        </div>
        <p>
          <span class="font-medium">กล้อง:</span> {{ form.name }} ({{
            form.camera_code || 'สร้างอัตโนมัติ'
          }})
        </p>
        <p>
          <span class="font-medium">พื้นที่ / โซน:</span>
          {{ selectedSite ? selectedSite.name : 'พื้นที่หลัก (สร้างอัตโนมัติ)' }} /
          {{ selectedZone ? selectedZone.name : 'โซนทั่วไป (สร้างอัตโนมัติ)' }}
        </p>
        <p><span class="font-medium">RTSP URL:</span> {{ form.rtsp_url || 'ยังไม่ระบุ' }}</p>
        <p>
          <span class="font-medium">หน่วยประมวลผล:</span>
          {{ selectedNode ? selectedNode.name : 'ยังไม่กำหนด' }}
        </p>
        <p>
          <span class="font-medium">ตั้งค่าปัญญาประดิษฐ์:</span>
          ตรวจจับ {{ onOff(form.face_detection_enabled) }}, จดจำ
          {{ onOff(form.face_recognition_enabled) }}, ตรวจใบหน้าจริง
          {{ onOff(form.liveness_enabled) }}, ภาพนิ่ง {{ onOff(form.snapshot_enabled) }}
        </p>
      </div>

      <p v-if="stepError" data-testid="step-error" class="text-sm text-red-600">{{ stepError }}</p>
      <div class="flex justify-between pt-4 border-t border-gray-200">
        <button
          v-if="step > 1"
          @click="prevStep"
          class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
        >
          ย้อนกลับ
        </button>
        <span v-else></span>

        <button
          v-if="step < steps.length"
          @click="nextStep"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700"
        >
          ถัดไป
        </button>
        <button
          v-else
          @click="submit"
          :disabled="loading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          <span v-if="loading">กำลังบันทึก...</span>
          <span v-else>บันทึกกล้อง</span>
        </button>
      </div>
    </div>
  </div>
</template>
