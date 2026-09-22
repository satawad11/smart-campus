<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'

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
    errorMessage.value = err.message || 'Failed to load camera.'
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
    aiError.value = err.message || 'Failed to update AI settings.'
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
    snapshotError.value = err.message || 'Snapshot capture failed.'
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
    roiError.value = 'An ROI polygon needs at least 3 points, or clear it entirely.'
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
    roiError.value = err.message || 'Failed to update ROI.'
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
  if (status === 'ONLINE')
    return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
  if (status === 'DEGRADED')
    return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
  return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
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
      &larr; Back to Cameras
    </button>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">Loading camera...</div>

    <template v-else-if="camera">
      <div class="border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ camera.name }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ camera.camera_code }}</p>
      </div>

      <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700 text-sm">
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
          {{ tab === 'ai' ? 'AI Settings' : tab === 'roi' ? 'ROI' : tab }}
        </button>
      </div>

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-3 text-sm"
      >
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-gray-500">Status:</span> {{ camera.status }}</div>
          <div><span class="text-gray-500">Camera Type:</span> {{ camera.camera_type || '-' }}</div>
          <div><span class="text-gray-500">IP Address:</span> {{ camera.ip_address || '-' }}</div>
          <div>
            <span class="text-gray-500">Stream Protocol:</span> {{ camera.stream_protocol || '-' }}
          </div>
          <div>
            <span class="text-gray-500">Has RTSP URL:</span>
            {{ camera.has_rtsp_url ? 'Yes' : 'No' }}
          </div>
          <div>
            <span class="text-gray-500">Has Credentials:</span>
            {{ camera.has_credentials ? 'Yes' : 'No' }}
          </div>
          <div><span class="text-gray-500">Site:</span> {{ camera.site?.name || '-' }}</div>
          <div><span class="text-gray-500">Zone:</span> {{ camera.zone?.name || '-' }}</div>
          <div>
            <span class="text-gray-500">AI Node:</span> {{ camera.ai_node?.name || 'Unassigned' }}
          </div>
          <div>
            <span class="text-gray-500">Last Seen:</span>
            {{ camera.last_seen_at ? new Date(camera.last_seen_at).toLocaleString() : 'Never' }}
          </div>
        </div>

        <div v-if="canTest" class="pt-4 border-t border-gray-200 dark:border-gray-700">
          <button
            @click="runTest"
            :disabled="testLoading"
            class="px-4 py-2 text-sm text-white bg-gray-700 rounded-lg hover:bg-gray-800 disabled:opacity-50"
          >
            {{ testLoading ? 'Testing...' : 'Test Connection' }}
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
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
      >
        <button
          v-if="canLive"
          @click="captureSnapshot"
          :disabled="snapshotLoading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ snapshotLoading ? 'Capturing...' : 'Capture Snapshot' }}
        </button>
        <p v-if="snapshotError" class="text-sm text-red-600">{{ snapshotError }}</p>
        <img
          v-if="snapshot"
          :src="snapshot"
          alt="Camera snapshot"
          class="max-w-full rounded-lg border"
        />
      </div>

      <!-- AI Settings -->
      <div
        v-if="activeTab === 'ai'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
      >
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.face_detection_enabled" type="checkbox" :disabled="!canUpdate" />
          Face detection enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.face_recognition_enabled" type="checkbox" :disabled="!canUpdate" />
          Face recognition enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.liveness_enabled" type="checkbox" :disabled="!canUpdate" />
          Liveness detection enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="aiForm.snapshot_enabled" type="checkbox" :disabled="!canUpdate" />
          Snapshot capture enabled
        </label>

        <p v-if="aiError" class="text-sm text-red-600">{{ aiError }}</p>

        <button
          v-if="canUpdate"
          @click="saveAiSettings"
          :disabled="aiSaving"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ aiSaving ? 'Saving...' : 'Save AI Settings' }}
        </button>
      </div>

      <!-- ROI -->
      <div
        v-if="activeTab === 'roi'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
      >
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Click inside the frame to add polygon vertices (at least 3). Recognition only runs on
          detections whose center falls inside this region.
        </p>

        <svg
          :width="ROI_SIZE"
          :height="ROI_SIZE"
          class="border border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-900 cursor-crosshair"
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
            Undo Point
          </button>
          <button
            @click="clearRoi"
            class="px-3 py-1.5 text-sm bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            Clear ROI
          </button>
          <button
            @click="saveRoi"
            :disabled="roiSaving"
            class="px-4 py-1.5 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ roiSaving ? 'Saving...' : 'Save ROI' }}
          </button>
        </div>
      </div>

      <!-- Health -->
      <div
        v-if="activeTab === 'health'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
      >
        <div v-if="healthLoading" class="text-center py-8 text-gray-500">
          Loading health history...
        </div>
        <div v-else-if="healthLogs.length === 0" class="text-center py-8 text-gray-400">
          No health telemetry recorded yet.
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Status</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">FPS</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Latency</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Dropped</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Reconnects</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">Recorded At</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="(log, i) in healthLogs" :key="i">
              <td class="px-4 py-2">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(log.status)"
                  >{{ log.status }}</span
                >
              </td>
              <td class="px-4 py-2">{{ log.fps ?? '-' }}</td>
              <td class="px-4 py-2">{{ log.latency_ms ?? '-' }} ms</td>
              <td class="px-4 py-2">{{ log.dropped_frames ?? '-' }}</td>
              <td class="px-4 py-2">{{ log.reconnect_count ?? '-' }}</td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ log.recorded_at ? new Date(log.recorded_at).toLocaleString() : '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
