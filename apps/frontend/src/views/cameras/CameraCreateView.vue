<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { apiErrorText, onOff } from '@/constants/labels'

const router = useRouter()

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
    errorMessage.value = apiErrorText(err, 'เพิ่มกล้องไม่สำเร็จ')
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
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รหัสพื้นที่ (UUID) *</label>
          <input
            v-model="form.site_uuid"
            type="text"
            placeholder="รหัสพื้นที่"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <p v-if="fieldErrors.site_uuid" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.site_uuid[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รหัสโซน (UUID) *</label>
          <input
            v-model="form.zone_uuid"
            type="text"
            placeholder="รหัสโซน"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
          <p v-if="fieldErrors.zone_uuid" class="text-xs text-red-600 mt-1">
            {{ fieldErrors.zone_uuid[0] }}
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">รหัสกล้อง *</label>
          <input
            v-model="form.camera_code"
            type="text"
            placeholder="เช่น GATE-1"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
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
            placeholder="เช่น fixed, ptz"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
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
      </div>

      <!-- Step 4: Review -->
      <div v-if="step === 4" class="space-y-2 text-sm">
        <p><span class="font-medium">กล้อง:</span> {{ form.name }} ({{ form.camera_code }})</p>
        <p>
          <span class="font-medium">พื้นที่ / โซน:</span> {{ form.site_uuid }} /
          {{ form.zone_uuid }}
        </p>
        <p><span class="font-medium">RTSP URL:</span> {{ form.rtsp_url || 'ยังไม่ระบุ' }}</p>
        <p>
          <span class="font-medium">หน่วยประมวลผล:</span> {{ form.ai_node_uuid || 'ยังไม่กำหนด' }}
        </p>
        <p>
          <span class="font-medium">ตั้งค่าปัญญาประดิษฐ์:</span>
          ตรวจจับ {{ onOff(form.face_detection_enabled) }}, จดจำ
          {{ onOff(form.face_recognition_enabled) }}, ตรวจใบหน้าจริง
          {{ onOff(form.liveness_enabled) }}, ภาพนิ่ง {{ onOff(form.snapshot_enabled) }}
        </p>
      </div>

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
