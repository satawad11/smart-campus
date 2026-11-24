<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const GROUPS = [
  { id: 'GENERAL', label: 'ทั่วไป (General)' },
  { id: 'SECURITY', label: 'ความปลอดภัย (Security)' },
  { id: 'FACE', label: 'การรู้จำใบหน้า (Face AI)' },
  { id: 'EVENT', label: 'เหตุการณ์ (Event & Policy)' },
  { id: 'CAMERA', label: 'กล้อง (Camera Telemetry)' },
  { id: 'STORAGE', label: 'พื้นที่จัดเก็บ (Storage)' },
  { id: 'REPORT', label: 'รายงาน (Reports)' },
  { id: 'NOTIFICATION', label: 'การแจ้งเตือน (Notifications)' },
  { id: 'RETENTION', label: 'ระยะเวลาเก็บข้อมูล (Retention)' },
  { id: 'FEATURE', label: 'ฟีเจอร์ระบบ (Feature Flags)' },
]

const activeGroup = ref('GENERAL')
const allSettings = ref({})
const currentSettings = ref([])
const formValues = ref({})
const isLoading = ref(false)
const isSaving = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

const canManage = computed(() => authStore.hasPermission('system.manage'))

// feature.vehicle and feature.alpr are optional since ACR-VEH-01 and stay editable here;
// the backend still forces them off when FEATURE_VEHICLE/ALPR_ENABLED=false.
const LOCKED_FEATURES = ['feature.door', 'feature.visitor', 'feature.ai_assistant']

async function fetchSettings() {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const res = await api.get('/settings')
    allSettings.value = res.data.data || {}
    loadGroup(activeGroup.value)
  } catch (err) {
    errorMessage.value = err.response?.data?.message || 'ไม่สามารถโหลดการตั้งค่าระบบได้'
  } finally {
    isLoading.value = false
  }
}

function loadGroup(group) {
  activeGroup.value = group
  currentSettings.value = allSettings.value[group] || []
  const initial = {}
  currentSettings.value.forEach((s) => {
    initial[s.key] = s.value
  })
  formValues.value = initial
  successMessage.value = ''
  errorMessage.value = ''
}

async function saveSettings() {
  if (!canManage.value) return
  isSaving.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    const payload = { settings: formValues.value }
    const res = await api.put(`/settings/${activeGroup.value.toLowerCase()}`, payload)
    successMessage.value = `บันทึกการตั้งค่ากลุ่ม ${activeGroup.value} สำเร็จ`

    // Update local state
    if (res.data.data) {
      allSettings.value[activeGroup.value] = res.data.data
      loadGroup(activeGroup.value)
    }
  } catch (err) {
    if (err.response?.status === 422) {
      const errors = err.response.data?.error?.fields || {}
      errorMessage.value = Object.values(errors).flat().join(', ') || 'ข้อมูลการตั้งค่าไม่ถูกต้อง'
    } else {
      errorMessage.value = err.response?.data?.message || 'เกิดข้อผิดพลาดในการบันทึกการตั้งค่า'
    }
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  fetchSettings()
})
</script>

<template>
  <div class="system-settings-view max-w-7xl mx-auto p-6 space-y-6">
    <!-- Header -->
    <div
      class="border-b pb-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">การตั้งค่าระบบ (System Settings)</h1>
        <p class="text-sm text-gray-500">
          กำหนดค่าพารามิเตอร์หลัก นโยบายความปลอดภัย และการเปิด/ปิดฟีเจอร์ระบบ (SEC-001, SEC-002)
        </p>
      </div>
      <div v-if="canManage">
        <button
          @click="saveSettings"
          :disabled="isSaving || isLoading"
          class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg text-sm shadow-sm transition disabled:opacity-50"
        >
          {{ isSaving ? 'กำลังบันทึก...' : 'บันทึกการเปลี่ยนแปลง' }}
        </button>
      </div>
    </div>

    <!-- Alerts -->
    <div
      v-if="successMessage"
      class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-sm"
    >
      {{ successMessage }}
    </div>
    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <!-- Navigation Tabs -->
    <div class="flex overflow-x-auto border-b border-gray-200 gap-2 pb-2">
      <button
        v-for="group in GROUPS"
        :key="group.id"
        @click="loadGroup(group.id)"
        class="px-4 py-2 text-sm font-medium rounded-lg whitespace-nowrap transition"
        :class="
          activeGroup === group.id
            ? 'bg-indigo-600 text-white shadow-sm'
            : 'text-gray-600 hover:bg-gray-100'
        "
      >
        {{ group.label }}
      </button>
    </div>

    <!-- Settings Content -->
    <div v-if="isLoading" class="p-12 text-center text-gray-400">กำลังโหลดข้อมูลการตั้งค่า...</div>

    <div v-else class="bg-surface rounded-xl border border-gray-200 divide-y divide-gray-100">
      <div v-if="currentSettings.length === 0" class="p-8 text-center text-gray-400 text-sm">
        ไม่มีรายการตั้งค่าในหมวดหมู่นี้
      </div>

      <div
        v-for="setting in currentSettings"
        :key="setting.key"
        class="p-6 flex flex-col md:flex-row md:items-center justify-between gap-4"
      >
        <div class="max-w-xl space-y-1">
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm font-semibold text-gray-900">{{ setting.key }}</span>
            <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-500 uppercase">{{
              setting.type
            }}</span>
            <span
              v-if="setting.is_public"
              class="text-xs px-2 py-0.5 rounded bg-blue-50 text-blue-600"
              >Public</span
            >
            <span
              v-if="LOCKED_FEATURES.includes(setting.key)"
              class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-800 font-semibold"
            >
              🔒 Locked V1
            </span>
          </div>
          <p class="text-sm text-gray-500">
            {{ setting.description || 'ไม่มีคำอธิบาย' }}
          </p>
          <p v-if="setting.updated_at" class="text-xs text-gray-400">
            อัปเดตล่าสุด: {{ new Date(setting.updated_at).toLocaleString() }}
          </p>
        </div>

        <!-- Setting Control -->
        <div class="w-full md:w-72">
          <!-- Boolean Switch -->
          <div v-if="setting.type === 'boolean'" class="flex items-center gap-3">
            <label class="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                v-model="formValues[setting.key]"
                :disabled="!canManage || LOCKED_FEATURES.includes(setting.key)"
                class="sr-only peer"
              />
              <div
                class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"
              ></div>
            </label>
            <span
              class="text-sm font-medium"
              :class="formValues[setting.key] ? 'text-indigo-600' : 'text-gray-400'"
            >
              {{ formValues[setting.key] ? 'เปิดใช้งาน (Enabled)' : 'ปิดใช้งาน (Disabled)' }}
            </span>
          </div>

          <!-- Integer / Float Input -->
          <div v-else-if="setting.type === 'integer' || setting.type === 'float'">
            <input
              type="number"
              :step="setting.type === 'float' ? '0.01' : '1'"
              v-model.number="formValues[setting.key]"
              :disabled="!canManage"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>

          <!-- JSON Input -->
          <div v-else-if="setting.type === 'json'">
            <textarea
              v-model="formValues[setting.key]"
              :disabled="!canManage"
              rows="3"
              class="w-full font-mono text-xs px-3 py-2 border rounded-lg"
            ></textarea>
          </div>

          <!-- String / Default Input -->
          <div v-else>
            <input
              type="text"
              v-model="formValues[setting.key]"
              :disabled="!canManage"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
