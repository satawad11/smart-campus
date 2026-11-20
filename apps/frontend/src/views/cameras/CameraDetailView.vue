<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useFeaturesStore } from '@/stores/features'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { apiErrorText, statusLabel } from '@/constants/labels'
import CameraLiveView from '@/components/cameras/CameraLiveView.vue'

const tabLabels = {
  overview: 'ภาพรวม',
  preview: 'ภาพตัวอย่าง',
  ai: 'ตั้งค่าปัญญาประดิษฐ์',
  roi: 'พื้นที่ตรวจจับ',
  health: 'สุขภาพกล้อง',
}

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const featuresStore = useFeaturesStore()
featuresStore.load()

const camera = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const activeTab = ref('overview')

const canUpdate = computed(() => authStore.can('camera.update'))
const canTest = computed(() => authStore.can('camera.test'))
const canLive = computed(() => authStore.can('camera.live'))

// AI settings tab
const aiForm = reactive({
  face_detection_enabled: false,
  face_recognition_enabled: false,
  liveness_enabled: false,
  snapshot_enabled: false,
  plate_recognition_enabled: false,
})
const aiSaving = ref(false)
const aiError = ref('')

// Overview edit form (PUT /cameras/{uuid}). camera_code/site/zone are
// immutable on update; credentials follow the three-state contract:
// blank input = unchanged (key omitted), clear checkbox = explicit null.
const editing = ref(false)
const editSaving = ref(false)
const editError = ref('')
const editFieldErrors = ref({})
const aiNodes = ref([])
const aiNodesLoaded = ref(false)
const editForm = reactive({
  name: '',
  camera_type: '',
  ip_address: '',
  stream_protocol: '',
  onvif_url: '',
  ai_node_uuid: '',
  rtsp_url: '',
  username: '',
  password: '',
  clear_credentials: false,
})

// Connection test
const testResult = ref(null)
const testLoading = ref(false)

// ROI tab
const roiPoints = ref([])
const roiSaving = ref(false)
const roiError = ref('')
const ROI_SIZE = 400

// Health tab
const healthLogs = ref([])
const healthLoading = ref(false)

async function fetchCamera() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await camerasService.getCamera(route.params.uuid)
    camera.value = res.data
    aiForm.face_detection_enabled = camera.value.face_detection_enabled
    aiForm.face_recognition_enabled = camera.value.face_recognition_enabled
    aiForm.liveness_enabled = camera.value.liveness_enabled
    aiForm.snapshot_enabled = camera.value.snapshot_enabled
    aiForm.plate_recognition_enabled = !!camera.value.plate_recognition_enabled
    roiPoints.value = (camera.value.roi || []).map((p) => ({ x: p.x, y: p.y }))
    if (route.query?.edit === '1' && canUpdate.value) startEdit()
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดข้อมูลกล้องไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function saveAiSettings() {
  aiSaving.value = true
  aiError.value = ''
  try {
    // Leave the plate switch out while ALPR is off: the backend refuses to turn it on then,
    // and a camera switched on earlier must not block saving the face settings.
    const payload = { ...aiForm }
    if (!featuresStore.isOn('alpr')) delete payload.plate_recognition_enabled
    const res = await camerasService.updateCamera(camera.value.uuid, payload)
    camera.value = res.data
  } catch (err) {
    aiError.value = apiErrorText(err, 'บันทึกการตั้งค่าไม่สำเร็จ')
  } finally {
    aiSaving.value = false
  }
}

async function runTest() {
  testLoading.value = true
  testResult.value = null
  try {
    const res = await camerasService.testConnection(camera.value.uuid)
    testResult.value = res
  } catch (err) {
    testResult.value = { success: false, message: err.message }
  } finally {
    testLoading.value = false
  }
}

async function fetchAiNodes() {
  if (aiNodesLoaded.value) return
  try {
    const res = await aiNodesService.listNodes({ per_page: 100 })
    aiNodes.value = res.data || []
  } catch {
    // Non-fatal: keep the current assignment selectable.
    aiNodes.value = []
  } finally {
    aiNodesLoaded.value = true
  }
}

const aiNodeOptions = computed(() => {
  const current = camera.value?.ai_node
  if (current && !aiNodes.value.some((n) => n.uuid === current.uuid)) {
    return [current, ...aiNodes.value]
  }
  return aiNodes.value
})

function startEdit() {
  const c = camera.value
  Object.assign(editForm, {
    name: c.name || '',
    camera_type: c.camera_type || '',
    ip_address: c.ip_address || '',
    stream_protocol: c.stream_protocol || 'rtsp',
    onvif_url: c.onvif_url || '',
    ai_node_uuid: c.ai_node?.uuid || '',
    rtsp_url: '',
    username: '',
    password: '',
    clear_credentials: false,
  })
  editError.value = ''
  editFieldErrors.value = {}
  editing.value = true
  fetchAiNodes()
}

function cancelEdit() {
  editing.value = false
  editError.value = ''
  editFieldErrors.value = {}
}

function buildUpdatePayload() {
  const payload = {
    name: editForm.name.trim(),
    camera_type: editForm.camera_type.trim() || null,
    ip_address: editForm.ip_address.trim() || null,
    stream_protocol: editForm.stream_protocol.trim() || null,
    onvif_url: editForm.onvif_url.trim() || null,
    ai_node_uuid: editForm.ai_node_uuid || null,
  }
  // Secrets are never returned by the API, so blank means "keep current".
  if (editForm.rtsp_url.trim()) payload.rtsp_url = editForm.rtsp_url.trim()
  if (editForm.clear_credentials) {
    payload.username = null
    payload.password = null
  } else {
    if (editForm.username.trim()) payload.username = editForm.username.trim()
    if (editForm.password) payload.password = editForm.password
  }
  return payload
}

async function saveEdit() {
  editError.value = ''
  editFieldErrors.value = {}
  if (!editForm.name.trim()) {
    editFieldErrors.value = { name: ['กรุณาระบุชื่อกล้อง'] }
    return
  }
  editSaving.value = true
  try {
    const res = await camerasService.updateCamera(camera.value.uuid, buildUpdatePayload())
    camera.value = res.data
    editing.value = false
  } catch (err) {
    editFieldErrors.value = err?.fields || {}
    editError.value = apiErrorText(err, 'บันทึกข้อมูลกล้องไม่สำเร็จ')
  } finally {
    editSaving.value = false
  }
}

function addRoiPoint(event) {
  const rect = event.currentTarget.getBoundingClientRect()
  const x = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
  const y = Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height))
  roiPoints.value.push({ x: Number(x.toFixed(3)), y: Number(y.toFixed(3)) })
}

function undoRoiPoint() {
  roiPoints.value.pop()
}

function clearRoi() {
  roiPoints.value = []
}

const roiPolygonSvgPoints = computed(() =>
  roiPoints.value.map((p) => `${p.x * ROI_SIZE},${p.y * ROI_SIZE}`).join(' '),
)

async function saveRoi() {
  roiError.value = ''
  if (roiPoints.value.length > 0 && roiPoints.value.length < 3) {
    roiError.value = 'พื้นที่ตรวจจับต้องมีอย่างน้อย 3 จุด หรือล้างออกทั้งหมด'
    return
  }

  roiSaving.value = true
  try {
    const res = await camerasService.updateRoi(
      camera.value.uuid,
      roiPoints.value.length === 0 ? null : roiPoints.value,
    )
    camera.value = res.data
  } catch (err) {
    roiError.value = apiErrorText(err, 'บันทึกพื้นที่ตรวจจับไม่สำเร็จ')
  } finally {
    roiSaving.value = false
  }
}

async function fetchHealth() {
  healthLoading.value = true
  try {
    const res = await camerasService.getHealth(camera.value.uuid, { per_page: 20 })
    healthLogs.value = res.data || []
  } catch {
    healthLogs.value = []
  } finally {
    healthLoading.value = false
  }
}

function setTab(tab) {
  activeTab.value = tab
  if (tab === 'health' && healthLogs.value.length === 0) fetchHealth()
}

function healthBadgeClass(status) {
  if (status === 'ONLINE') return 'bg-green-100 text-green-800'
  if (status === 'DEGRADED') return 'bg-yellow-100 text-yellow-800'
  return 'bg-red-100 text-red-800'
}

onMounted(() => {
  fetchCamera()
})
</script>

<template>
  <div class="camera-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'cameras.index' })"
      class="text-sm text-blue-600 hover:underline"
    >
      &larr; กลับไปหน้ากล้อง
    </button>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดข้อมูลกล้อง...</div>

    <template v-else-if="camera">
      <div class="border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">{{ camera.name }}</h1>
        <p class="text-sm text-gray-500 font-mono">{{ camera.camera_code }}</p>
      </div>

      <div class="flex gap-2 border-b border-gray-200 text-sm">
        <button
          v-for="tab in ['overview', 'preview', 'ai', 'roi', 'health']"
          :key="tab"
          @click="setTab(tab)"
          class="px-4 py-2 font-medium capitalize"
          :class="
            activeTab === tab
              ? 'border-b-2 border-blue-600 text-blue-600'
              : 'text-gray-500 hover:text-gray-700'
          "
        >
          {{ tabLabels[tab] }}
        </button>
      </div>

      <!-- Overview: edit form -->
      <form
        v-if="activeTab === 'overview' && editing"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4 text-sm"
        data-testid="camera-edit-form"
        @submit.prevent="saveEdit"
      >
        <h2 class="font-semibold text-gray-900">แก้ไขข้อมูลกล้อง</h2>
        <p class="text-xs text-gray-500">
          รหัสกล้อง พื้นที่ และโซน แก้ไขไม่ได้ · สถานะกล้องเปลี่ยนจากหน้ารายการกล้อง
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-gray-600">ชื่อกล้อง *</span>
            <input
              v-model="editForm.name"
              name="name"
              type="text"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
            <span v-if="editFieldErrors.name" class="text-xs text-red-600">{{
              [].concat(editFieldErrors.name)[0]
            }}</span>
          </label>
          <label class="block">
            <span class="text-gray-600">ประเภทกล้อง</span>
            <input
              v-model="editForm.camera_type"
              name="camera_type"
              type="text"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">ที่อยู่ไอพี</span>
            <input
              v-model="editForm.ip_address"
              name="ip_address"
              type="text"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
            <span v-if="editFieldErrors.ip_address" class="text-xs text-red-600">{{
              [].concat(editFieldErrors.ip_address)[0]
            }}</span>
          </label>
          <label class="block">
            <span class="text-gray-600">โปรโตคอลสตรีม</span>
            <input
              v-model="editForm.stream_protocol"
              name="stream_protocol"
              type="text"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block md:col-span-2">
            <span class="text-gray-600">RTSP URL ใหม่</span>
            <input
              v-model="editForm.rtsp_url"
              name="rtsp_url"
              type="text"
              maxlength="2048"
              autocomplete="off"
              :placeholder="
                camera.has_rtsp_url ? 'เว้นว่างเพื่อใช้ค่าเดิม' : 'rtsp://ไอพีกล้อง:554/...'
              "
              class="mt-1 w-full px-3 py-2 border rounded-lg font-mono"
            />
            <span v-if="editFieldErrors.rtsp_url" class="text-xs text-red-600">{{
              [].concat(editFieldErrors.rtsp_url)[0]
            }}</span>
          </label>
          <label class="block">
            <span class="text-gray-600">ชื่อผู้ใช้กล้องใหม่</span>
            <input
              v-model="editForm.username"
              name="username"
              type="text"
              autocomplete="off"
              :disabled="editForm.clear_credentials"
              :placeholder="camera.has_credentials ? 'เว้นว่างเพื่อใช้ค่าเดิม' : ''"
              class="mt-1 w-full px-3 py-2 border rounded-lg disabled:bg-gray-100"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">รหัสผ่านกล้องใหม่</span>
            <input
              v-model="editForm.password"
              name="password"
              type="password"
              autocomplete="new-password"
              :disabled="editForm.clear_credentials"
              :placeholder="camera.has_credentials ? 'เว้นว่างเพื่อใช้ค่าเดิม' : ''"
              class="mt-1 w-full px-3 py-2 border rounded-lg disabled:bg-gray-100"
            />
          </label>
          <label v-if="camera.has_credentials" class="flex items-center gap-2 md:col-span-2">
            <input v-model="editForm.clear_credentials" name="clear_credentials" type="checkbox" />
            ลบชื่อผู้ใช้และรหัสผ่านกล้องที่บันทึกไว้
          </label>
          <label class="block">
            <span class="text-gray-600">ONVIF URL</span>
            <input
              v-model="editForm.onvif_url"
              name="onvif_url"
              type="text"
              maxlength="2048"
              class="mt-1 w-full px-3 py-2 border rounded-lg font-mono"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">หน่วยประมวลผล</span>
            <select
              v-model="editForm.ai_node_uuid"
              name="ai_node_uuid"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            >
              <option value="">ยังไม่กำหนด</option>
              <option v-for="node in aiNodeOptions" :key="node.uuid" :value="node.uuid">
                {{ node.name }}{{ node.node_code ? ` (${node.node_code})` : '' }}
              </option>
            </select>
          </label>
        </div>

        <p v-if="editError" class="text-sm text-red-600" role="alert">{{ editError }}</p>

        <div class="flex gap-2 pt-2 border-t border-gray-200">
          <button
            type="submit"
            :disabled="editSaving"
            class="px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ editSaving ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข' }}
          </button>
          <button
            type="button"
            @click="cancelEdit"
            class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ยกเลิก
          </button>
        </div>
      </form>

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview' && !editing"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-3 text-sm"
      >
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-gray-500">สถานะ:</span> {{ statusLabel(camera.status) }}</div>
          <div><span class="text-gray-500">ประเภทกล้อง:</span> {{ camera.camera_type || '-' }}</div>
          <div><span class="text-gray-500">ที่อยู่ไอพี:</span> {{ camera.ip_address || '-' }}</div>
          <div>
            <span class="text-gray-500">โปรโตคอลสตรีม:</span> {{ camera.stream_protocol || '-' }}
          </div>
          <div>
            <span class="text-gray-500">มีที่อยู่ RTSP:</span>
            {{ camera.has_rtsp_url ? 'มี' : 'ไม่มี' }}
          </div>
          <div>
            <span class="text-gray-500">มีบัญชีเข้ากล้อง:</span>
            {{ camera.has_credentials ? 'มี' : 'ไม่มี' }}
          </div>
          <div><span class="text-gray-500">พื้นที่:</span> {{ camera.site?.name || '-' }}</div>
          <div><span class="text-gray-500">โซน:</span> {{ camera.zone?.name || '-' }}</div>
          <div>
            <span class="text-gray-500">หน่วยประมวลผล:</span>
            {{ camera.ai_node?.name || 'ยังไม่กำหนด' }}
          </div>
          <div>
            <span class="text-gray-500">ติดต่อล่าสุด:</span>
            {{
              camera.last_seen_at
                ? new Date(camera.last_seen_at).toLocaleString('th-TH')
                : 'ยังไม่เคย'
            }}
          </div>
        </div>

        <div v-if="canTest || canUpdate" class="pt-4 border-t border-gray-200 flex flex-wrap gap-2">
          <button
            v-if="canUpdate"
            @click="startEdit"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700"
          >
            แก้ไขข้อมูลกล้อง
          </button>
          <button
            v-if="canTest"
            @click="runTest"
            :disabled="testLoading"
            class="px-4 py-2 text-sm text-gray-50 bg-gray-800 rounded-lg hover:bg-gray-900 disabled:opacity-50"
          >
            {{ testLoading ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ' }}
          </button>
          <p
            v-if="testResult"
            class="w-full mt-2 text-sm"
            :class="
              (testResult.success ?? testResult.data?.reachable) ? 'text-green-600' : 'text-red-600'
            "
          >
            {{ testResult.message }}
          </p>
        </div>
      </div>

      <!-- Preview -->
      <div
        v-if="activeTab === 'preview'"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4"
      >
        <CameraLiveView v-if="canLive" :camera-uuid="camera.uuid" :camera-name="camera.name" />
        <p v-else class="text-sm text-gray-500">ไม่มีสิทธิ์ดูภาพสดจากกล้อง (camera.live)</p>
      </div>

      <!-- AI Settings -->
      <div
        v-if="activeTab === 'ai'"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4"
      >
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.face_detection_enabled" type="checkbox" :disabled="!canUpdate" />
          เปิดการตรวจจับใบหน้า
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.face_recognition_enabled" type="checkbox" :disabled="!canUpdate" />
          เปิดการจดจำใบหน้า
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.liveness_enabled" type="checkbox" :disabled="!canUpdate" />
          เปิดการตรวจสอบใบหน้าจริง
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.snapshot_enabled" type="checkbox" :disabled="!canUpdate" />
          เปิดการบันทึกภาพนิ่ง
        </label>
        <!-- VEH-011: shown only while the ALPR module is on (ACR-VEH-01 D1) -->
        <label v-if="featuresStore.isOn('alpr')" class="flex items-center gap-2 text-sm">
          <input
            v-model="aiForm.plate_recognition_enabled"
            id="plate-recognition-toggle"
            type="checkbox"
            :disabled="!canUpdate"
          />
          อ่านป้ายทะเบียน
          <span class="text-xs text-gray-500">(ใบหน้ายังเป็นหลักในการยืนยันตัวตน)</span>
        </label>

        <p v-if="aiError" class="text-sm text-red-600">{{ aiError }}</p>

        <button
          v-if="canUpdate"
          @click="saveAiSettings"
          :disabled="aiSaving"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ aiSaving ? 'กำลังบันทึก...' : 'บันทึกการตั้งค่า' }}
        </button>
      </div>

      <!-- ROI -->
      <div
        v-if="activeTab === 'roi'"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4"
      >
        <p class="text-sm text-gray-500">
          คลิกในภาพเพื่อเพิ่มจุดของพื้นที่ (อย่างน้อย 3 จุด) ระบบจะจดจำใบหน้าเฉพาะที่จุดกึ่งกลาง
          อยู่ในพื้นที่นี้เท่านั้น
        </p>

        <svg
          :width="ROI_SIZE"
          :height="ROI_SIZE"
          class="border border-gray-300 bg-gray-100 cursor-crosshair"
          @click="canUpdate && addRoiPoint($event)"
        >
          <polygon
            v-if="roiPoints.length >= 3"
            :points="roiPolygonSvgPoints"
            fill="rgba(37, 99, 235, 0.25)"
            stroke="rgb(37, 99, 235)"
            stroke-width="2"
          />
          <circle
            v-for="(p, i) in roiPoints"
            :key="i"
            :cx="p.x * ROI_SIZE"
            :cy="p.y * ROI_SIZE"
            r="4"
            fill="rgb(37, 99, 235)"
          />
        </svg>

        <p v-if="roiError" class="text-sm text-red-600">{{ roiError }}</p>

        <div v-if="canUpdate" class="flex gap-2">
          <button
            @click="undoRoiPoint"
            class="px-3 py-1.5 text-sm bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ย้อนจุดล่าสุด
          </button>
          <button
            @click="clearRoi"
            class="px-3 py-1.5 text-sm bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ล้างพื้นที่
          </button>
          <button
            @click="saveRoi"
            :disabled="roiSaving"
            class="px-4 py-1.5 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ roiSaving ? 'กำลังบันทึก...' : 'บันทึกพื้นที่ตรวจจับ' }}
          </button>
        </div>
      </div>

      <!-- Health -->
      <div
        v-if="activeTab === 'health'"
        class="bg-surface rounded-xl border border-gray-200 overflow-hidden"
      >
        <div v-if="healthLoading" class="text-center py-8 text-gray-500">
          กำลังโหลดประวัติสุขภาพกล้อง...
        </div>
        <div v-else-if="healthLogs.length === 0" class="text-center py-8 text-gray-400">
          ยังไม่มีข้อมูลสุขภาพกล้อง
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">สถานะ</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">FPS</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">ความหน่วง</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">เฟรมตก</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">เชื่อมต่อใหม่</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">บันทึกเมื่อ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="(log, i) in healthLogs" :key="i">
              <td class="px-4 py-2">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(log.status)"
                  >{{ statusLabel(log.status) }}</span
                >
              </td>
              <td class="px-4 py-2">{{ log.fps ?? '-' }}</td>
              <td class="px-4 py-2">{{ log.latency_ms ?? '-' }} ms</td>
              <td class="px-4 py-2">{{ log.dropped_frames ?? '-' }}</td>
              <td class="px-4 py-2">{{ log.reconnect_count ?? '-' }}</td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ log.recorded_at ? new Date(log.recorded_at).toLocaleString('th-TH') : '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
