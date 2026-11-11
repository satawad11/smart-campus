<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'
import { apiErrorText, statusLabel } from '@/constants/labels'

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
})
const aiSaving = ref(false)
const aiError = ref('')

// Preview tab
const snapshot = ref(null)
const snapshotLoading = ref(false)
const snapshotError = ref('')

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
    roiPoints.value = (camera.value.roi || []).map((p) => ({ x: p.x, y: p.y }))
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
    const res = await camerasService.updateCamera(camera.value.uuid, { ...aiForm })
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

async function captureSnapshot() {
  snapshotLoading.value = true
  snapshotError.value = ''
  snapshot.value = null
  try {
    const res = await camerasService.captureSnapshot(camera.value.uuid)
    snapshot.value = `data:${res.data.content_type};base64,${res.data.image_base64}`
  } catch (err) {
    snapshotError.value = apiErrorText(err, 'บันทึกภาพนิ่งไม่สำเร็จ')
  } finally {
    snapshotLoading.value = false
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

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview'"
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

        <div v-if="canTest" class="pt-4 border-t border-gray-200">
          <button
            @click="runTest"
            :disabled="testLoading"
            class="px-4 py-2 text-sm text-gray-50 bg-gray-800 rounded-lg hover:bg-gray-900 disabled:opacity-50"
          >
            {{ testLoading ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ' }}
          </button>
          <p
            v-if="testResult"
            class="mt-2 text-sm"
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
        <button
          v-if="canLive"
          @click="captureSnapshot"
          :disabled="snapshotLoading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ snapshotLoading ? 'กำลังบันทึกภาพ...' : 'บันทึกภาพนิ่ง' }}
        </button>
        <p v-if="snapshotError" class="text-sm text-red-600">{{ snapshotError }}</p>
        <img
          v-if="snapshot"
          :src="snapshot"
          alt="ภาพนิ่งจากกล้อง"
          class="max-w-full rounded-lg border"
        />
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
