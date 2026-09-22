<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'

const router = useRouter()

const step = ref(1)
const steps = ['General', 'Connection', 'AI Settings', 'Review']

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
})

const aiNodes = ref([])
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

async function fetchAiNodes() {
  try {
    const res = await aiNodesService.listNodes({ per_page: 100 })
    aiNodes.value = res.data || []
  } catch {
    // Non-fatal: AI node assignment remains optional/manual.
  }
}

function nextStep() {
  if (step.value < steps.length) step.value++
}

function prevStep() {
  if (step.value > 1) step.value--
}

async function submit() {
  loading.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  const payload = {
    site_uuid: form.site_uuid,
    zone_uuid: form.zone_uuid,
    ai_node_uuid: form.ai_node_uuid || null,
    camera_code: form.camera_code,
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
  }

  try {
    const res = await camerasService.createCamera(payload)
    router.push({ name: 'cameras.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    errorMessage.value = err.message || 'Failed to create camera.'
    fieldErrors.value = err.fields || {}
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchAiNodes()
})
</script>

<template>
  <div class="camera-create-view max-w-3xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Add Camera</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        General &rarr; Connection &rarr; AI Settings &rarr; Review (CAM-017 wizard).
      </p>
    </div>

    <div class="flex items-center gap-2 text-sm">
      <span
        v-for="(label, index) in steps"
        :key="label"
        class="px-3 py-1 rounded-full font-medium"
        :class="
          step === index + 1
            ? 'bg-blue-600 text-white'
            : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
        "
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

    <div
      class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
    >
      <!-- Step 1: General -->
      <div v-if="step === 1" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Site UUID *</label
          >
          <input
            v-model="form.site_uuid"
            type="text"
            placeholder="Site UUID"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <p v-if="fieldErrors.site_uuid" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.site_uuid[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Zone UUID *</label
          >
          <input
            v-model="form.zone_uuid"
            type="text"
            placeholder="Zone UUID"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <p v-if="fieldErrors.zone_uuid" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.zone_uuid[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Camera Code *</label
          >
          <input
            v-model="form.camera_code"
            type="text"
            placeholder="e.g. GATE-1"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <p v-if="fieldErrors.camera_code" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.camera_code[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Name *</label
          >
          <input
            v-model="form.name"
            type="text"
            placeholder="e.g. Main Gate"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <p v-if="fieldErrors.name" class="text-xs text-red-600 mt-1">{{ fieldErrors.name[0] }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Camera Type</label
          >
          <input
            v-model="form.camera_type"
            type="text"
            placeholder="e.g. fixed, ptz"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
      </div>

      <!-- Step 2: Connection -->
      <div v-if="step === 2" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >IP Address</label
          >
          <input
            v-model="form.ip_address"
            type="text"
            placeholder="192.168.1.10"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
          <p v-if="fieldErrors.ip_address" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.ip_address[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Stream Protocol</label
          >
          <input
            v-model="form.stream_protocol"
            type="text"
            placeholder="rtsp"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >RTSP URL</label
          >
          <input
            v-model="form.rtsp_url"
            type="text"
            placeholder="rtsp://camera-host:554/stream1"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
              >Username</label
            >
            <input
              v-model="form.username"
              type="text"
              class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
              >Password</label
            >
            <input
              v-model="form.password"
              type="password"
              class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >ONVIF URL</label
          >
          <input
            v-model="form.onvif_url"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
      </div>

      <!-- Step 3: AI Settings -->
      <div v-if="step === 3" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Assigned AI Node</label
          >
          <select
            v-model="form.ai_node_uuid"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          >
            <option value="">Unassigned</option>
            <option v-for="node in aiNodes" :key="node.uuid" :value="node.uuid">
              {{ node.name }} ({{ node.node_code }})
            </option>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.face_detection_enabled" type="checkbox" />
          Face detection enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.face_recognition_enabled" type="checkbox" />
          Face recognition enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.liveness_enabled" type="checkbox" />
          Liveness detection enabled
        </label>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.snapshot_enabled" type="checkbox" />
          Snapshot capture enabled
        </label>
      </div>

      <!-- Step 4: Review -->
      <div v-if="step === 4" class="space-y-2 text-sm">
        <p><span class="font-medium">Camera:</span> {{ form.name }} ({{ form.camera_code }})</p>
        <p>
          <span class="font-medium">Site / Zone:</span> {{ form.site_uuid }} / {{ form.zone_uuid }}
        </p>
        <p><span class="font-medium">RTSP URL:</span> {{ form.rtsp_url || 'Not set' }}</p>
        <p><span class="font-medium">AI Node:</span> {{ form.ai_node_uuid || 'Unassigned' }}</p>
        <p>
          <span class="font-medium">AI Settings:</span>
          detection={{ form.face_detection_enabled }}, recognition={{
            form.face_recognition_enabled
          }}, liveness={{ form.liveness_enabled }}, snapshot={{ form.snapshot_enabled }}
        </p>
      </div>

      <div class="flex justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
        <button
          v-if="step > 1"
          @click="prevStep"
          class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
        >
          Back
        </button>
        <span v-else></span>

        <button
          v-if="step < steps.length"
          @click="nextStep"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700"
        >
          Next
        </button>
        <button
          v-else
          @click="submit"
          :disabled="loading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          <span v-if="loading">Saving...</span>
          <span v-else>Save Camera</span>
        </button>
      </div>
    </div>
  </div>
</template>
