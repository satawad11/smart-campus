<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import aiNodesService from '@/services/aiNodes'
import NodeOperationsPanel from '@/components/aiNodes/NodeOperationsPanel.vue'
import { apiErrorText, statusLabel } from '@/constants/labels'

const tabLabels = {
  overview: 'ภาพรวม',
  credentials: 'กุญแจเชื่อมต่อ',
  health: 'สุขภาพระบบ',
  operations: 'การควบคุม',
}

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const node = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const activeTab = ref('overview')

const canManage = computed(() => authStore.can('ai_node.manage'))

// Administrative fields only. Identity/status and runtime-reported fields
// remain on their dedicated flows and are never submitted from this form.
const editing = ref(false)
const editSaving = ref(false)
const editError = ref('')
const editFieldErrors = ref({})
const editForm = reactive({
  site_uuid: '',
  name: '',
  hostname: '',
  ip_address: '',
  api_url: '',
  hardware_type: '',
  cpu_model: '',
  gpu_name: '',
  gpu_memory_mb: '',
  max_camera: '',
})

// Credentials tab
const credentials = ref([])
const credentialsLoading = ref(false)
const issuedToken = ref(null)
const issueError = ref('')
const issueLoading = ref(false)

// Health tab
const healthLogs = ref([])
const healthLoading = ref(false)

async function fetchNode() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await aiNodesService.getNode(route.params.uuid)
    node.value = res.data
    if (route.query?.edit === '1' && canManage.value) startEdit()
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดข้อมูลหน่วยประมวลผลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function startEdit() {
  const current = node.value
  Object.assign(editForm, {
    site_uuid: current.site?.uuid || '',
    name: current.name || '',
    hostname: current.hostname || '',
    ip_address: current.ip_address || '',
    api_url: current.api_url || '',
    hardware_type: current.hardware_type || '',
    cpu_model: current.cpu_model || '',
    gpu_name: current.gpu_name || '',
    gpu_memory_mb: current.gpu_memory_mb ?? '',
    max_camera: current.max_camera ?? '',
  })
  editError.value = ''
  editFieldErrors.value = {}
  editing.value = true
}

function cancelEdit() {
  editing.value = false
  editError.value = ''
  editFieldErrors.value = {}
}

function nullableText(value) {
  const normalized = String(value ?? '').trim()
  return normalized || null
}

function nullableNumber(value) {
  return value === '' || value == null ? null : Number(value)
}

async function saveEdit() {
  editError.value = ''
  editFieldErrors.value = {}
  if (!editForm.name.trim()) {
    editFieldErrors.value = { name: ['กรุณาระบุชื่อหน่วยประมวลผล'] }
    return
  }

  editSaving.value = true
  try {
    const res = await aiNodesService.updateNode(node.value.uuid, {
      site_uuid: editForm.site_uuid.trim() || null,
      name: editForm.name.trim(),
      hostname: nullableText(editForm.hostname),
      ip_address: nullableText(editForm.ip_address),
      api_url: nullableText(editForm.api_url),
      hardware_type: nullableText(editForm.hardware_type),
      cpu_model: nullableText(editForm.cpu_model),
      gpu_name: nullableText(editForm.gpu_name),
      gpu_memory_mb: nullableNumber(editForm.gpu_memory_mb),
      max_camera: nullableNumber(editForm.max_camera),
    })
    node.value = res.data
    editing.value = false
  } catch (err) {
    editFieldErrors.value = err?.fields || {}
    editError.value = apiErrorText(err, 'บันทึกข้อมูลหน่วยประมวลผลไม่สำเร็จ')
  } finally {
    editSaving.value = false
  }
}

async function fetchCredentials() {
  credentialsLoading.value = true
  try {
    const res = await aiNodesService.listCredentials(node.value.uuid)
    credentials.value = res.data || []
  } catch {
    credentials.value = []
  } finally {
    credentialsLoading.value = false
  }
}

async function issueCredential() {
  issueLoading.value = true
  issueError.value = ''
  issuedToken.value = null
  try {
    const res = await aiNodesService.issueCredential(node.value.uuid)
    issuedToken.value = res.data.token
    await fetchCredentials()
  } catch (err) {
    issueError.value = apiErrorText(err, 'ออกกุญแจเชื่อมต่อไม่สำเร็จ')
  } finally {
    issueLoading.value = false
  }
}

// COM-03e: one-time enrollment code for ./enroll.sh on the node
const enrollmentCode = ref(null)
const enrollmentError = ref('')
const enrollmentLoading = ref(false)

async function issueEnrollmentCode() {
  enrollmentLoading.value = true
  enrollmentError.value = ''
  enrollmentCode.value = null
  try {
    const res = await aiNodesService.issueEnrollmentCode(node.value.uuid)
    enrollmentCode.value = res.data
  } catch (err) {
    enrollmentError.value =
      err.code === 'AI_NODE_NOT_ACTIVE'
        ? 'ต้องเปิดใช้งานหน่วยประมวลผลก่อนจึงจะสร้างรหัสลงทะเบียนได้'
        : 'สร้างรหัสลงทะเบียนไม่สำเร็จ'
  } finally {
    enrollmentLoading.value = false
  }
}

async function revokeCredential(credential) {
  try {
    await aiNodesService.revokeCredential(node.value.uuid, credential.uuid)
    await fetchCredentials()
  } catch (err) {
    issueError.value = apiErrorText(err, 'เพิกถอนกุญแจเชื่อมต่อไม่สำเร็จ')
  }
}

async function fetchHealth() {
  healthLoading.value = true
  try {
    const res = await aiNodesService.getHealth(node.value.uuid, { per_page: 20 })
    healthLogs.value = res.data || []
  } catch {
    healthLogs.value = []
  } finally {
    healthLoading.value = false
  }
}

function setTab(tab) {
  activeTab.value = tab
  if (tab === 'credentials' && credentials.value.length === 0) fetchCredentials()
  if (tab === 'health' && healthLogs.value.length === 0) fetchHealth()
}

function healthBadgeClass(status) {
  if (status === 'ONLINE') return 'bg-green-100 text-green-800'
  if (status === 'DEGRADED') return 'bg-yellow-100 text-yellow-800'
  return 'bg-red-100 text-red-800'
}

function credentialBadgeClass(status) {
  return status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
}

onMounted(() => {
  fetchNode()
})
</script>

<template>
  <div class="ai-node-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'ai-nodes.index' })"
      class="text-sm text-blue-600 hover:underline"
    >
      &larr; กลับไปหน้าหน่วยประมวลผล
    </button>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">
      กำลังโหลดข้อมูลหน่วยประมวลผล...
    </div>

    <template v-else-if="node">
      <div class="border-b pb-4">
        <h1 class="text-2xl font-bold text-gray-900">{{ node.name }}</h1>
        <p class="text-sm text-gray-500 font-mono">{{ node.node_code }}</p>
      </div>

      <div class="flex gap-2 border-b border-gray-200 text-sm">
        <button
          v-for="tab in ['overview', 'credentials', 'health', 'operations']"
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

      <!-- Overview edit -->
      <form
        v-if="activeTab === 'overview' && editing"
        data-testid="ai-node-edit-form"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4 text-sm"
        @submit.prevent="saveEdit"
      >
        <div>
          <h2 class="font-semibold text-gray-900">แก้ไขข้อมูลหน่วยประมวลผล</h2>
          <p class="mt-1 text-xs text-gray-500">
            รหัสหน่วย สถานะ รุ่นซอฟต์แวร์ โมเดล และข้อมูลสุขภาพเป็นค่าที่ระบบควบคุมหรือรายงานจาก
            Node
          </p>
        </div>

        <div v-if="editError" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg">
          {{ editError }}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label class="block">
            <span class="text-gray-600">ชื่อหน่วยประมวลผล *</span>
            <input
              v-model="editForm.name"
              name="name"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
            <span v-if="editFieldErrors.name" class="text-xs text-red-600">{{
              editFieldErrors.name[0]
            }}</span>
          </label>
          <label class="block">
            <span class="text-gray-600">รหัสพื้นที่ (UUID)</span>
            <input
              v-model="editForm.site_uuid"
              name="site_uuid"
              placeholder="เว้นว่างหากไม่กำหนดพื้นที่"
              class="mt-1 w-full px-3 py-2 border rounded-lg font-mono text-xs"
            />
            <span v-if="editFieldErrors.site_uuid" class="text-xs text-red-600">{{
              editFieldErrors.site_uuid[0]
            }}</span>
          </label>
          <label class="block">
            <span class="text-gray-600">ชื่อเครื่อง</span>
            <input
              v-model="editForm.hostname"
              name="hostname"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">ที่อยู่ไอพี</span>
            <input
              v-model="editForm.ip_address"
              name="ip_address"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
            <span v-if="editFieldErrors.ip_address" class="text-xs text-red-600">{{
              editFieldErrors.ip_address[0]
            }}</span>
          </label>
          <label class="block md:col-span-2">
            <span class="text-gray-600">ที่อยู่ API</span>
            <input
              v-model="editForm.api_url"
              name="api_url"
              maxlength="2048"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">ประเภทฮาร์ดแวร์</span>
            <input
              v-model="editForm.hardware_type"
              name="hardware_type"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">รุ่นซีพียู</span>
            <input
              v-model="editForm.cpu_model"
              name="cpu_model"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">รุ่น GPU</span>
            <input
              v-model="editForm.gpu_name"
              name="gpu_name"
              maxlength="255"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">หน่วยความจำ GPU (MB)</span>
            <input
              v-model="editForm.gpu_memory_mb"
              name="gpu_memory_mb"
              type="number"
              min="0"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
          <label class="block">
            <span class="text-gray-600">จำนวนกล้องสูงสุด</span>
            <input
              v-model="editForm.max_camera"
              name="max_camera"
              type="number"
              min="0"
              max="65535"
              class="mt-1 w-full px-3 py-2 border rounded-lg"
            />
          </label>
        </div>

        <div class="flex justify-end gap-2 pt-4 border-t border-gray-200">
          <button
            type="button"
            @click="cancelEdit"
            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            :disabled="editSaving"
            class="px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ editSaving ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข' }}
          </button>
        </div>
      </form>

      <!-- Overview -->
      <div
        v-if="activeTab === 'overview' && !editing"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-3 text-sm"
      >
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-gray-500">สถานะ:</span> {{ statusLabel(node.status) }}</div>
          <div>
            <span class="text-gray-500">พื้นที่:</span> {{ node.site?.name || 'ยังไม่กำหนด' }}
          </div>
          <div><span class="text-gray-500">ชื่อเครื่อง:</span> {{ node.hostname || '-' }}</div>
          <div><span class="text-gray-500">ที่อยู่ไอพี:</span> {{ node.ip_address || '-' }}</div>
          <div><span class="text-gray-500">ที่อยู่ API:</span> {{ node.api_url || '-' }}</div>
          <div>
            <span class="text-gray-500">รุ่นซอฟต์แวร์:</span> {{ node.software_version || '-' }}
          </div>
          <div data-test="node-model">
            <span class="text-gray-500">โมเดล:</span>
            {{ node.model_code ? `${node.model_code} ${node.model_version}` : '-' }}
            <span
              v-if="node.model_compatible === false"
              class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-800"
              >ไม่ตรงกับทะเบียนโมเดล</span
            >
            <span
              v-else-if="node.model_compatible === true"
              class="ml-1 rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-800"
              >ตรงกับทะเบียนโมเดล</span
            >
          </div>
          <div>
            <span class="text-gray-500">ประเภทฮาร์ดแวร์:</span> {{ node.hardware_type || '-' }}
          </div>
          <div><span class="text-gray-500">รุ่นซีพียู:</span> {{ node.cpu_model || '-' }}</div>
          <div><span class="text-gray-500">การ์ดจอ (GPU):</span> {{ node.gpu_name || '-' }}</div>
          <div>
            <span class="text-gray-500">หน่วยความจำ GPU:</span>
            {{ node.gpu_memory_mb ? `${node.gpu_memory_mb} MB` : '-' }}
          </div>
          <div>
            <span class="text-gray-500">จำนวนกล้องสูงสุด:</span> {{ node.max_camera ?? '-' }}
          </div>
          <div>
            <span class="text-gray-500">ติดต่อล่าสุด:</span>
            {{
              node.last_seen_at ? new Date(node.last_seen_at).toLocaleString('th-TH') : 'ยังไม่เคย'
            }}
          </div>
        </div>
        <div v-if="canManage" class="pt-4 border-t border-gray-200">
          <button
            @click="startEdit"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700"
          >
            แก้ไขข้อมูลหน่วยประมวลผล
          </button>
        </div>
      </div>

      <!-- Credentials -->
      <div
        v-if="activeTab === 'credentials'"
        class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4"
      >
        <div
          v-if="canManage"
          data-test="enrollment-code"
          class="p-4 rounded-lg border border-blue-200 bg-blue-50 space-y-2 text-sm"
        >
          <p class="font-semibold text-blue-900">ลงทะเบียนหน่วยประมวลผลด้วยรหัสใช้ครั้งเดียว</p>
          <p class="text-blue-800">
            สร้างรหัสแล้วรันคำสั่ง <code>./enroll.sh รหัส</code> บนเครื่องหน่วยประมวลผล
            ระบบจะบันทึกตัวตนและกุญแจลงในไฟล์ตั้งค่าให้เอง รหัสใช้ได้ครั้งเดียวและหมดอายุใน 30 นาที
            การลงทะเบียนจะยกเลิกกุญแจเดิมของหน่วยนี้
          </p>
          <button
            @click="issueEnrollmentCode"
            :disabled="enrollmentLoading"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ enrollmentLoading ? 'กำลังสร้าง...' : 'สร้างรหัสลงทะเบียน' }}
          </button>
          <p v-if="enrollmentError" class="text-red-600">{{ enrollmentError }}</p>
          <div v-if="enrollmentCode" class="space-y-1">
            <p class="text-blue-900">รหัสลงทะเบียน (แสดงครั้งเดียว):</p>
            <code
              data-test="enrollment-code-value"
              class="block text-2xl font-mono tracking-widest bg-surface p-3 rounded border text-center"
              >{{ enrollmentCode.code }}</code
            >
            <p class="text-xs text-blue-700">
              หมดอายุ {{ new Date(enrollmentCode.expires_at).toLocaleString('th-TH') }}
            </p>
          </div>
        </div>

        <p class="text-sm text-gray-500">
          กุญแจเชื่อมต่อเฉพาะของหน่วยประมวลผลนี้ ใช้ยืนยันตัวตนเมื่อหน่วยประมวลผลติดต่อเซิร์ฟเวอร์
          กุญแจใหม่จะแสดงเพียงครั้งเดียวและเรียกดูซ้ำไม่ได้
        </p>

        <button
          v-if="canManage"
          @click="issueCredential"
          :disabled="issueLoading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ issueLoading ? 'กำลังออกกุญแจ...' : 'ออกกุญแจเชื่อมต่อใหม่' }}
        </button>

        <p v-if="issueError" class="text-sm text-red-600">{{ issueError }}</p>

        <div
          v-if="issuedToken"
          class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg text-sm space-y-1"
        >
          <p class="font-semibold text-yellow-800">กุญแจเชื่อมต่อใหม่ (แสดงครั้งเดียว):</p>
          <code class="block break-all bg-surface p-2 rounded border text-xs">{{
            issuedToken
          }}</code>
        </div>

        <div v-if="credentialsLoading" class="text-center py-6 text-gray-500">
          กำลังโหลดกุญแจเชื่อมต่อ...
        </div>
        <table
          v-else-if="credentials.length > 0"
          class="min-w-full divide-y divide-gray-200 text-sm"
        >
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">สถานะ</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">ออกเมื่อ</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">ใช้ล่าสุด</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">เพิกถอนเมื่อ</th>
              <th class="px-4 py-2 text-right font-medium text-gray-500 uppercase">การดำเนินการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr v-for="cred in credentials" :key="cred.uuid">
              <td class="px-4 py-2">
                <span
                  class="px-2 py-0.5 rounded-full text-xs font-semibold"
                  :class="credentialBadgeClass(cred.status)"
                  >{{ statusLabel(cred.status) }}</span
                >
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ cred.created_at ? new Date(cred.created_at).toLocaleString('th-TH') : '-' }}
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{
                  cred.last_used_at
                    ? new Date(cred.last_used_at).toLocaleString('th-TH')
                    : 'ยังไม่เคย'
                }}
              </td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ cred.revoked_at ? new Date(cred.revoked_at).toLocaleString('th-TH') : '-' }}
              </td>
              <td class="px-4 py-2 text-right">
                <button
                  v-if="canManage && cred.status === 'ACTIVE'"
                  @click="revokeCredential(cred)"
                  class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs"
                >
                  เพิกถอน
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-center py-6 text-gray-400 text-sm">ยังไม่มีกุญแจเชื่อมต่อ</p>
      </div>

      <!-- Health -->
      <div
        v-if="activeTab === 'health'"
        class="bg-surface rounded-xl border border-gray-200 overflow-hidden"
      >
        <div v-if="healthLoading" class="text-center py-8 text-gray-500">
          กำลังโหลดประวัติสุขภาพระบบ...
        </div>
        <div v-else-if="healthLogs.length === 0" class="text-center py-8 text-gray-400">
          ยังไม่มีข้อมูลสุขภาพระบบ
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">สถานะ</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">CPU</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">RAM</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">GPU</th>
              <th class="px-4 py-2 text-left font-medium text-gray-500 uppercase">งานค้างในคิว</th>
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
              <td class="px-4 py-2">{{ log.cpu_usage != null ? `${log.cpu_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.ram_usage != null ? `${log.ram_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.gpu_usage != null ? `${log.gpu_usage}%` : '-' }}</td>
              <td class="px-4 py-2">{{ log.queue_depth ?? '-' }}</td>
              <td class="px-4 py-2 text-xs text-gray-500">
                {{ log.recorded_at ? new Date(log.recorded_at).toLocaleString('th-TH') : '-' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Operations (COM-03) -->
      <NodeOperationsPanel v-if="activeTab === 'operations'" :node-uuid="node.uuid" />
    </template>
  </div>
</template>
