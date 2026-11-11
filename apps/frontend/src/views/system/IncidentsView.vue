<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * Incident tracking (SEC-014 API; V1 audit A-17, COM-05). Listing needs
 * system.view; creating and updating need system.manage (enforced by the
 * backend; the controls are hidden here only for convenience).
 */
const authStore = useAuthStore()
const canManage = computed(() => authStore.can('system.manage'))

const STATUSES = ['OPEN', 'INVESTIGATING', 'MITIGATED', 'RESOLVED', 'POST_MORTEM']
const SEVERITIES = ['SEV1', 'SEV2', 'SEV3']

const incidents = ref([])
const statusFilter = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const busy = ref(false)
const form = reactive({ title: '', severity: 'SEV2', description: '', impact_summary: '' })
const editing = ref(null)
const edit = reactive({ status: '', root_cause: '', resolution_summary: '' })

function reset() {
  errorMessage.value = ''
  successMessage.value = ''
}

async function load() {
  reset()
  try {
    const params = statusFilter.value ? { status: statusFilter.value } : {}
    const response = await api.get('/system/incidents', { params })
    incidents.value = response.data.data
  } catch (err) {
    errorMessage.value = err?.message || 'โหลดรายการเหตุการณ์ไม่สำเร็จ'
  }
}

async function create() {
  reset()
  if (!form.title.trim() || !form.description.trim()) {
    errorMessage.value = 'กรุณากรอกหัวข้อและรายละเอียด'
    return
  }
  busy.value = true
  try {
    await api.post('/system/incidents', { ...form })
    Object.assign(form, { title: '', severity: 'SEV2', description: '', impact_summary: '' })
    await load()
    successMessage.value = 'บันทึกเหตุการณ์แล้ว'
  } catch (err) {
    errorMessage.value = err?.message || 'บันทึกเหตุการณ์ไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

function startEdit(incident) {
  editing.value = incident.id
  Object.assign(edit, {
    status: incident.status,
    root_cause: incident.root_cause || '',
    resolution_summary: incident.resolution_summary || '',
  })
}

async function saveEdit(incident) {
  reset()
  busy.value = true
  try {
    await api.put(`/system/incidents/${incident.id}`, { ...edit })
    editing.value = null
    await load()
    successMessage.value = `อัปเดต ${incident.incident_number} แล้ว`
  } catch (err) {
    errorMessage.value = err?.message || 'อัปเดตไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

function severityClass(severity) {
  return (
    { SEV1: 'bg-red-100 text-red-800', SEV2: 'bg-amber-100 text-amber-800' }[severity] ||
    'bg-gray-100 text-gray-800'
  )
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-4">
    <div class="flex items-end justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">เหตุการณ์ (Incidents)</h1>
        <p class="mt-1 text-sm text-gray-500">บันทึกและติดตามเหตุขัดข้องของระบบจนปิดงาน</p>
      </div>
      <label class="text-sm text-gray-700">
        สถานะ
        <select
          v-model="statusFilter"
          data-test="status-filter"
          class="ml-2 rounded-md border border-gray-300 px-2 py-1 text-sm"
          @change="load"
        >
          <option value="">ทั้งหมด</option>
          <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
        </select>
      </label>
    </div>

    <div
      v-if="successMessage"
      class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800"
      role="status"
    >
      {{ successMessage }}
    </div>
    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <form
      v-if="canManage"
      data-test="create-form"
      class="space-y-2 rounded-lg border border-gray-200 bg-surface p-4"
      @submit.prevent="create"
    >
      <h2 class="font-semibold text-gray-900">บันทึกเหตุการณ์ใหม่</h2>
      <div class="flex flex-wrap gap-2">
        <input
          v-model="form.title"
          data-test="new-title"
          class="min-w-64 flex-1 rounded-md border border-gray-300 px-2 py-1 text-sm"
          placeholder="หัวข้อ"
        />
        <select v-model="form.severity" class="rounded-md border border-gray-300 px-2 py-1 text-sm">
          <option v-for="s in SEVERITIES" :key="s" :value="s">{{ s }}</option>
        </select>
      </div>
      <textarea
        v-model="form.description"
        data-test="new-description"
        rows="2"
        class="block w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
        placeholder="รายละเอียด"
      ></textarea>
      <textarea
        v-model="form.impact_summary"
        rows="1"
        class="block w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
        placeholder="ผลกระทบ (ถ้ามี)"
      ></textarea>
      <button
        data-test="create"
        type="submit"
        :disabled="busy"
        class="rounded-md bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
      >
        บันทึก
      </button>
    </form>

    <div class="space-y-2">
      <p
        v-if="incidents.length === 0"
        class="rounded-lg border border-gray-200 bg-surface p-6 text-center text-sm text-gray-500"
      >
        ไม่มีเหตุการณ์
      </p>
      <div
        v-for="incident in incidents"
        :key="incident.id"
        data-test="incident"
        class="rounded-lg border border-gray-200 bg-surface p-4"
      >
        <div class="flex items-center justify-between gap-3">
          <div>
            <span class="font-mono text-xs text-gray-500">{{ incident.incident_number }}</span>
            <span
              class="ml-2 rounded-full px-2 py-0.5 text-xs font-medium"
              :class="severityClass(incident.severity)"
              >{{ incident.severity }}</span
            >
            <span class="ml-2 text-sm font-semibold text-gray-900">{{ incident.title }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span data-test="incident-status" class="text-xs font-medium text-gray-700">{{
              incident.status
            }}</span>
            <button
              v-if="canManage && editing !== incident.id"
              data-test="edit"
              type="button"
              class="text-xs text-blue-600 hover:underline"
              @click="startEdit(incident)"
            >
              อัปเดต
            </button>
          </div>
        </div>
        <p class="mt-1 text-sm text-gray-600">{{ incident.description }}</p>
        <div v-if="editing === incident.id" class="mt-3 space-y-2 border-t border-gray-100 pt-3">
          <select
            v-model="edit.status"
            data-test="edit-status"
            class="rounded-md border border-gray-300 px-2 py-1 text-sm"
          >
            <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
          </select>
          <textarea
            v-model="edit.root_cause"
            rows="1"
            class="block w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
            placeholder="สาเหตุ"
          ></textarea>
          <textarea
            v-model="edit.resolution_summary"
            rows="1"
            class="block w-full rounded-md border border-gray-300 px-2 py-1 text-sm"
            placeholder="การแก้ไข"
          ></textarea>
          <div class="flex gap-2">
            <button
              data-test="save"
              type="button"
              :disabled="busy"
              class="rounded-md bg-blue-600 px-3 py-1 text-sm text-white disabled:opacity-50"
              @click="saveEdit(incident)"
            >
              บันทึก
            </button>
            <button
              type="button"
              class="rounded-md border border-gray-300 px-3 py-1 text-sm"
              @click="editing = null"
            >
              ยกเลิก
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
