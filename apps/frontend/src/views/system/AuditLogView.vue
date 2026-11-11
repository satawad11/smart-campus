<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'

/**
 * Audit log viewer (V1 audit A-17, COM-05) over the existing read-only
 * GET /audit-logs API (permission audit.view). The backend already strips
 * secrets and never stores biometric vectors in audit payloads.
 */
const logs = ref([])
const pagination = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const expanded = ref(null)
const filters = reactive({ action: '', module: '', date_from: '', date_to: '' })
const page = ref(1)

async function load() {
  loading.value = true
  errorMessage.value = ''
  const params = { page: page.value, per_page: 25 }
  for (const [key, value] of Object.entries(filters)) {
    if (value) params[key] = value
  }
  try {
    const response = await api.get('/audit-logs', { params })
    logs.value = response.data.data
    pagination.value = response.data.meta?.pagination || null
  } catch (err) {
    errorMessage.value = err?.message || 'โหลดบันทึกการตรวจสอบไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

function search() {
  page.value = 1
  expanded.value = null
  load()
}

function goTo(target) {
  page.value = target
  expanded.value = null
  load()
}

function toggle(uuid) {
  expanded.value = expanded.value === uuid ? null : uuid
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString('th-TH') : '-'
}

function pretty(value) {
  return value ? JSON.stringify(value, null, 2) : '-'
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-4">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">บันทึกการตรวจสอบ (Audit Log)</h1>
      <p class="mt-1 text-sm text-gray-500">ใครทำอะไร เมื่อไร กับข้อมูลใด (อ่านอย่างเดียว)</p>
    </div>

    <form
      class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-surface p-4"
      @submit.prevent="search"
    >
      <label class="text-sm text-gray-700">
        Action
        <input
          v-model="filters.action"
          data-test="filter-action"
          class="mt-1 block w-48 rounded-md border border-gray-300 px-2 py-1 text-sm"
          placeholder="เช่น FACE_REVOKED"
        />
      </label>
      <label class="text-sm text-gray-700">
        Module
        <input
          v-model="filters.module"
          data-test="filter-module"
          class="mt-1 block w-40 rounded-md border border-gray-300 px-2 py-1 text-sm"
          placeholder="เช่น license"
        />
      </label>
      <label class="text-sm text-gray-700">
        ตั้งแต่
        <input
          v-model="filters.date_from"
          type="date"
          class="mt-1 block rounded-md border border-gray-300 px-2 py-1 text-sm"
        />
      </label>
      <label class="text-sm text-gray-700">
        ถึง
        <input
          v-model="filters.date_to"
          type="date"
          class="mt-1 block rounded-md border border-gray-300 px-2 py-1 text-sm"
        />
      </label>
      <button
        data-test="search"
        type="submit"
        class="rounded-md bg-blue-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
      >
        ค้นหา
      </button>
    </form>

    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-surface">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
          <tr>
            <th class="px-4 py-2">เวลา</th>
            <th class="px-4 py-2">Action</th>
            <th class="px-4 py-2">Module</th>
            <th class="px-4 py-2">ผู้กระทำ</th>
            <th class="px-4 py-2">ข้อมูลที่เกี่ยวข้อง</th>
            <th class="px-4 py-2">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="!loading && logs.length === 0">
            <td colspan="6" class="px-4 py-6 text-center text-gray-500">ไม่พบรายการ</td>
          </tr>
          <template v-for="log in logs" :key="log.uuid">
            <tr
              data-test="audit-row"
              class="cursor-pointer hover:bg-gray-50"
              @click="toggle(log.uuid)"
            >
              <td class="whitespace-nowrap px-4 py-2 text-gray-700">
                {{ formatDate(log.created_at) }}
              </td>
              <td class="px-4 py-2 font-mono text-xs text-gray-900">{{ log.action }}</td>
              <td class="px-4 py-2 text-gray-700">{{ log.module }}</td>
              <td class="px-4 py-2 text-gray-700">{{ log.actor?.username || 'ระบบ' }}</td>
              <td class="px-4 py-2 text-gray-700">
                {{ log.subject_type }}
                <span class="font-mono text-xs text-gray-500">{{ log.subject_uuid }}</span>
              </td>
              <td class="px-4 py-2 text-gray-500">{{ log.ip_address || '-' }}</td>
            </tr>
            <tr v-if="expanded === log.uuid" data-test="audit-detail">
              <td colspan="6" class="bg-gray-50 px-4 py-3">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                  <div>
                    <div class="text-xs font-medium text-gray-500">ค่าเดิม</div>
                    <pre class="overflow-x-auto whitespace-pre-wrap text-xs text-gray-800">{{
                      pretty(log.old_values)
                    }}</pre>
                  </div>
                  <div>
                    <div class="text-xs font-medium text-gray-500">ค่าใหม่</div>
                    <pre class="overflow-x-auto whitespace-pre-wrap text-xs text-gray-800">{{
                      pretty(log.new_values)
                    }}</pre>
                  </div>
                  <div>
                    <div class="text-xs font-medium text-gray-500">ข้อมูลประกอบ</div>
                    <pre class="overflow-x-auto whitespace-pre-wrap text-xs text-gray-800">{{
                      pretty(log.metadata)
                    }}</pre>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <div v-if="pagination" class="flex items-center justify-between text-sm text-gray-600">
      <span data-test="pagination"
        >หน้า {{ pagination.current_page }} / {{ pagination.last_page }} ({{
          pagination.total
        }}
        รายการ)</span
      >
      <div class="flex gap-2">
        <button
          data-test="prev"
          type="button"
          :disabled="pagination.current_page <= 1"
          class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
          @click="goTo(pagination.current_page - 1)"
        >
          ก่อนหน้า
        </button>
        <button
          data-test="next"
          type="button"
          :disabled="pagination.current_page >= pagination.last_page"
          class="rounded-md border border-gray-300 px-3 py-1 disabled:opacity-40"
          @click="goTo(pagination.current_page + 1)"
        >
          ถัดไป
        </button>
      </div>
    </div>
  </div>
</template>
