<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import gatesService from '@/services/gates'
import {
  apiErrorText,
  isFeatureDisabled,
  ERROR_MESSAGES,
  GATE_DIRECTION_LABELS,
  GATE_CAMERA_ROLE_LABELS,
  GATE_STATUS_LABELS,
} from '@/constants/labels'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const gate = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const canManage = computed(() => authStore.can('gate.manage'))

// Sites with zones and cameras (managers only; needs gate.manage).
const site = ref(null)

// Settings form
const editing = ref(false)
const saving = ref(false)
const settingsError = ref('')
const settings = reactive({
  name: '',
  zone_uuid: '',
  direction: 'BOTH',
  correlation_window_seconds: 10,
  status: 'INACTIVE',
  notes: '',
})

// Camera assignment draft: [{ camera_uuid, name, role }]
const draft = ref([])
const addCameraUuid = ref('')
const savingCameras = ref(false)
const camerasError = ref('')
const camerasMessage = ref('')

const availableCameras = computed(() => {
  const chosen = new Set(draft.value.map((c) => c.camera_uuid))
  return (site.value?.cameras || []).filter(
    (c) => !chosen.has(c.uuid) && (!c.gate_uuid || c.gate_uuid === gate.value?.uuid),
  )
})

const hasFaceCamera = computed(() => (gate.value?.cameras || []).some((c) => c.role !== 'PLATE'))

function cameraWarnings(camera) {
  const warnings = []
  if (camera.role !== 'PLATE' && !camera.face_recognition_enabled) {
    warnings.push('กล้องนี้ยังไม่ได้เปิดการรู้จำใบหน้า')
  }
  if (camera.role !== 'FACE' && !camera.plate_recognition_enabled) {
    warnings.push('กล้องนี้ยังไม่ได้เปิดการอ่านป้ายทะเบียน')
  }
  if (camera.status && camera.status !== 'ACTIVE') {
    warnings.push('กล้องนี้ปิดใช้งานอยู่')
  }
  return warnings
}

/** Warnings for a draft row, using the role being edited and the camera's own switches. */
function draftWarnings(row) {
  const camera =
    site.value?.cameras?.find((s) => s.uuid === row.camera_uuid) ||
    gate.value?.cameras?.find((g) => g.uuid === row.camera_uuid)
  return camera ? cameraWarnings({ ...camera, role: row.role }) : []
}

function resetDrafts() {
  const g = gate.value
  Object.assign(settings, {
    name: g.name,
    zone_uuid: g.zone?.uuid || '',
    direction: g.direction,
    correlation_window_seconds: g.correlation_window_seconds,
    status: g.status,
    notes: g.notes || '',
  })
  draft.value = (g.cameras || []).map((c) => ({
    camera_uuid: c.uuid,
    name: c.name,
    role: c.role,
  }))
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await gatesService.getGate(route.params.uuid)
    gate.value = res.data
    resetDrafts()
    if (canManage.value) {
      try {
        const options = await gatesService.getFormOptions()
        site.value = (options.data || []).find((s) => s.uuid === gate.value.site?.uuid) || null
      } catch {
        site.value = null
      }
    }
  } catch (err) {
    errorMessage.value = isFeatureDisabled(err)
      ? ERROR_MESSAGES.FEATURE_DISABLED
      : apiErrorText(err, 'โหลดข้อมูลจุดเข้าออกไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function cancelEdit() {
  editing.value = false
  settingsError.value = ''
  resetDrafts()
}

async function saveSettings() {
  settingsError.value = ''
  saving.value = true
  try {
    const res = await gatesService.updateGate(gate.value.uuid, {
      name: settings.name.trim(),
      zone_uuid: settings.zone_uuid || undefined,
      direction: settings.direction,
      correlation_window_seconds: Number(settings.correlation_window_seconds),
      status: settings.status,
      notes: settings.notes.trim() || null,
    })
    gate.value = res.data
    resetDrafts()
    editing.value = false
  } catch (err) {
    settingsError.value = apiErrorText(err, 'บันทึกการตั้งค่าไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function addCamera() {
  const camera = availableCameras.value.find((c) => c.uuid === addCameraUuid.value)
  if (!camera) return
  draft.value.push({ camera_uuid: camera.uuid, name: camera.name, role: 'FACE' })
  addCameraUuid.value = ''
}

function removeCamera(uuid) {
  draft.value = draft.value.filter((c) => c.camera_uuid !== uuid)
}

async function saveCameras() {
  camerasError.value = ''
  camerasMessage.value = ''
  savingCameras.value = true
  try {
    const res = await gatesService.replaceCameras(
      gate.value.uuid,
      draft.value.map(({ camera_uuid, role }) => ({ camera_uuid, role })),
    )
    gate.value = res.data
    resetDrafts()
    camerasMessage.value = 'บันทึกกล้องแล้ว'
  } catch (err) {
    camerasError.value = apiErrorText(err, 'บันทึกกล้องไม่สำเร็จ')
  } finally {
    savingCameras.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="gate-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'gates.index' })"
      class="text-sm text-gray-500 hover:text-gray-700"
    >
      ← กลับไปรายการจุดเข้าออก
    </button>

    <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลด...</div>
    <div
      v-else-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <template v-else-if="gate">
      <div class="flex flex-col sm:flex-row justify-between gap-4 border-b pb-4">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ gate.name }}</h1>
          <p class="text-sm text-gray-500">
            {{ gate.code }} · {{ [gate.site?.name, gate.zone?.name].filter(Boolean).join(' / ') }}
          </p>
        </div>
        <span
          class="self-start px-3 py-1 rounded-full text-xs font-semibold"
          :class="
            gate.status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'
          "
          data-test="gate-status"
        >
          {{ GATE_STATUS_LABELS[gate.status] || gate.status }}
        </span>
      </div>

      <div class="p-3 bg-blue-50 border border-blue-200 text-blue-800 rounded-lg text-xs">
        โหมดใบหน้าเป็นหลัก: ป้ายทะเบียนอย่างเดียวไม่ทำให้ได้ผล "อนุญาต" ระบบตัดสินและแจ้งผลเท่านั้น
        ยังไม่สั่งเปิดไม้กั้น
      </div>

      <!-- Settings -->
      <section class="bg-surface p-5 rounded-xl border border-gray-200 space-y-4">
        <div class="flex justify-between items-center">
          <h2 class="text-lg font-semibold text-gray-900">การตั้งค่า</h2>
          <button
            v-if="canManage && !editing"
            @click="editing = true"
            data-test="edit-settings"
            class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"
          >
            แก้ไข
          </button>
        </div>

        <dl v-if="!editing" class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
          <div>
            <dt class="text-gray-500">ทิศทาง</dt>
            <dd class="text-gray-900">{{ GATE_DIRECTION_LABELS[gate.direction] }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">ช่วงเวลาจับคู่ใบหน้ากับทะเบียน</dt>
            <dd class="text-gray-900">{{ gate.correlation_window_seconds }} วินาที</dd>
          </div>
          <div class="sm:col-span-2">
            <dt class="text-gray-500">หมายเหตุ</dt>
            <dd class="text-gray-900">{{ gate.notes || '-' }}</dd>
          </div>
        </dl>

        <form
          v-else
          @submit.prevent="saveSettings"
          class="grid grid-cols-1 sm:grid-cols-2 gap-4"
          data-test="settings-form"
        >
          <div>
            <label for="gate-name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ</label>
            <input
              id="gate-name"
              v-model="settings.name"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
          <div>
            <label for="gate-zone" class="block text-sm font-medium text-gray-700 mb-1">โซน</label>
            <select
              id="gate-zone"
              v-model="settings.zone_uuid"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-if="!site" :value="settings.zone_uuid">{{ gate.zone?.name }}</option>
              <option v-for="z in site?.zones || []" :key="z.uuid" :value="z.uuid">
                {{ z.name }}
              </option>
            </select>
          </div>
          <div>
            <label for="gate-direction" class="block text-sm font-medium text-gray-700 mb-1">
              ทิศทาง
            </label>
            <select
              id="gate-direction"
              v-model="settings.direction"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-for="(label, code) in GATE_DIRECTION_LABELS" :key="code" :value="code">
                {{ label }}
              </option>
            </select>
          </div>
          <div>
            <label for="gate-window" class="block text-sm font-medium text-gray-700 mb-1">
              ช่วงเวลาจับคู่ (วินาที)
            </label>
            <input
              id="gate-window"
              v-model="settings.correlation_window_seconds"
              type="number"
              min="1"
              max="60"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
          <div>
            <label for="gate-status" class="block text-sm font-medium text-gray-700 mb-1">
              สถานะ
            </label>
            <select
              id="gate-status"
              v-model="settings.status"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-for="(label, code) in GATE_STATUS_LABELS" :key="code" :value="code">
                {{ label }}
              </option>
            </select>
            <p
              v-if="settings.status === 'ACTIVE' && !hasFaceCamera"
              class="mt-1 text-xs text-amber-700"
            >
              ต้องมีกล้องจับใบหน้าอย่างน้อย 1 ตัวก่อนเปิดใช้งาน
            </p>
          </div>
          <div>
            <label for="gate-notes" class="block text-sm font-medium text-gray-700 mb-1">
              หมายเหตุ
            </label>
            <input
              id="gate-notes"
              v-model="settings.notes"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
          <p v-if="settingsError" class="sm:col-span-2 text-sm text-red-600">
            {{ settingsError }}
          </p>
          <div class="sm:col-span-2 flex gap-2">
            <button
              type="submit"
              :disabled="saving"
              class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
            <button
              type="button"
              @click="cancelEdit"
              class="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"
            >
              ยกเลิก
            </button>
          </div>
        </form>
      </section>

      <!-- Cameras -->
      <section class="bg-surface p-5 rounded-xl border border-gray-200 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">กล้องของจุดเข้าออก</h2>

        <div v-if="!gate.cameras?.length && !canManage" class="text-sm text-gray-400">
          ยังไม่มีกล้อง
        </div>

        <ul v-if="!canManage" class="divide-y divide-gray-100">
          <li v-for="c in gate.cameras" :key="c.uuid" class="py-3 text-sm">
            <div class="font-medium text-gray-900">{{ c.name }}</div>
            <div class="text-xs text-gray-500">{{ GATE_CAMERA_ROLE_LABELS[c.role] }}</div>
            <p v-for="w in cameraWarnings(c)" :key="w" class="text-xs text-amber-700">{{ w }}</p>
          </li>
        </ul>

        <template v-else>
          <ul class="divide-y divide-gray-100" data-test="camera-draft">
            <li v-if="!draft.length" class="py-3 text-sm text-gray-400">ยังไม่มีกล้อง</li>
            <li
              v-for="c in draft"
              :key="c.camera_uuid"
              class="py-3 flex flex-wrap items-center gap-3 text-sm"
            >
              <div class="flex-1 min-w-[160px]">
                <div class="font-medium text-gray-900">{{ c.name }}</div>
                <p v-for="w in draftWarnings(c)" :key="w" class="text-xs text-amber-700">
                  {{ w }}
                </p>
              </div>
              <select
                v-model="c.role"
                :aria-label="`หน้าที่ของ ${c.name}`"
                class="px-3 py-1.5 border rounded-lg text-sm"
              >
                <option v-for="(label, code) in GATE_CAMERA_ROLE_LABELS" :key="code" :value="code">
                  {{ label }}
                </option>
              </select>
              <button
                type="button"
                @click="removeCamera(c.camera_uuid)"
                class="px-2.5 py-1 text-xs text-red-700 bg-red-50 hover:bg-red-100 rounded"
              >
                นำออก
              </button>
            </li>
          </ul>

          <div class="flex flex-wrap gap-2 items-center">
            <select
              v-model="addCameraUuid"
              id="add-camera"
              aria-label="เลือกกล้องที่จะเพิ่ม"
              class="flex-1 min-w-[200px] px-3 py-2 border rounded-lg text-sm"
            >
              <option value="">เลือกกล้องในไซต์นี้</option>
              <option v-for="c in availableCameras" :key="c.uuid" :value="c.uuid">
                {{ c.name }} ({{ c.camera_code }})
              </option>
            </select>
            <button
              type="button"
              @click="addCamera"
              :disabled="!addCameraUuid"
              data-test="add-camera"
              class="px-3 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg disabled:opacity-50"
            >
              เพิ่มกล้อง
            </button>
          </div>
          <p class="text-xs text-gray-500">
            กล้อง 1 ตัวใช้ได้กับจุดเข้าออกเดียว กล้องที่ใช้กับจุดอื่นแล้วจะไม่แสดงในรายการ
          </p>
          <p v-if="camerasError" class="text-sm text-red-600">{{ camerasError }}</p>
          <p v-if="camerasMessage" class="text-sm text-green-700">{{ camerasMessage }}</p>
          <button
            type="button"
            @click="saveCameras"
            :disabled="savingCameras"
            data-test="save-cameras"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            {{ savingCameras ? 'กำลังบันทึก...' : 'บันทึกกล้อง' }}
          </button>
        </template>
      </section>
    </template>
  </div>
</template>
