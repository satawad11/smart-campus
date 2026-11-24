<script setup>
// GATE-008 (ACR-VEH-01 Phase 5): who and which car passed which Smart Gate, and what the system
// decided. Read only; the gate decides and notifies, it never opens a barrier (D5).
import { ref, onMounted } from 'vue'
import gatesService from '@/services/gates'
import { useAuthStore } from '@/stores/auth'
import { apiErrorText, decisionLabel, GATE_MATCH_RESULT_LABELS } from '@/constants/labels'
import GatePassInfo from '@/components/common/GatePassInfo.vue'

const auth = useAuthStore()

const loading = ref(false)
const error = ref(null)
const passes = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const gates = ref([])

const emptyFilters = () => ({
  gate_uuid: '',
  match_result: '',
  decision: '',
  plate: '',
  from: '',
  to: '',
})
const filters = ref(emptyFilters())

const DECISION_CLASSES = {
  ALLOW: 'bg-green-100 text-green-800',
  DENY: 'bg-red-100 text-red-800',
  REVIEW: 'bg-amber-100 text-amber-800',
  UNKNOWN: 'bg-gray-100 text-gray-800',
}

async function fetchPasses(page = 1) {
  loading.value = true
  error.value = null
  try {
    const params = { page, ...filters.value }
    Object.keys(params).forEach((key) => {
      if (!params[key]) delete params[key]
    })
    // Whole days, like the history search date inputs.
    if (params.from) params.from = `${params.from} 00:00:00`
    if (params.to) params.to = `${params.to} 23:59:59`

    const res = await gatesService.listPasses(params)
    passes.value = res.data || []
    pagination.value = res.meta?.pagination || {
      current_page: 1,
      last_page: 1,
      total: passes.value.length,
    }
  } catch (err) {
    error.value = apiErrorText(err, 'ไม่สามารถค้นหาประวัติการผ่านจุดเข้าออกได้')
  } finally {
    loading.value = false
  }
}

// The gate list needs gate.view; without it the search still works, just without that filter.
async function loadGates() {
  if (!auth.can('gate.view')) return
  try {
    const res = await gatesService.listGates({ per_page: 100 })
    gates.value = res.data || []
  } catch {
    gates.value = []
  }
}

function resetFilters() {
  filters.value = emptyFilters()
  fetchPasses(1)
}

function formatTime(iso) {
  if (!iso) return '-'
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'medium' })
}

// The event the decision was written on: the face event, or the plate event for a plate-only pass.
function primaryEventUuid(pass) {
  return pass.face_event_uuid || pass.plate_event_uuid
}

onMounted(() => {
  loadGates()
  fetchPasses(1)
})
</script>

<template>
  <div class="space-y-6 p-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">ประวัติการผ่านจุดเข้าออก</h1>
      <p class="text-sm text-gray-500">
        ใบหน้าและป้ายทะเบียนที่ระบบจับคู่ได้ในแต่ละจุดเข้าออก พร้อมผลการตัดสินใจล่าสุด
        (ระบบตัดสินและแจ้งเตือนเท่านั้น ไม่ได้สั่งเปิดไม้กั้น)
      </p>
    </div>

    <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
      <form
        class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4"
        @submit.prevent="fetchPasses(1)"
      >
        <div v-if="gates.length">
          <label for="gp-gate" class="block text-xs font-medium text-gray-700 mb-1"
            >จุดเข้าออก</label
          >
          <select
            id="gp-gate"
            v-model="filters.gate_uuid"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">ทั้งหมด</option>
            <option v-for="gate in gates" :key="gate.uuid" :value="gate.uuid">
              {{ gate.name }}
            </option>
          </select>
        </div>
        <div>
          <label for="gp-match" class="block text-xs font-medium text-gray-700 mb-1"
            >ผลการจับคู่</label
          >
          <select
            id="gp-match"
            v-model="filters.match_result"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">ทั้งหมด</option>
            <option v-for="(label, code) in GATE_MATCH_RESULT_LABELS" :key="code" :value="code">
              {{ label }}
            </option>
          </select>
        </div>
        <div>
          <label for="gp-decision" class="block text-xs font-medium text-gray-700 mb-1"
            >ผลการตัดสินใจ</label
          >
          <select
            id="gp-decision"
            v-model="filters.decision"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          >
            <option value="">ทั้งหมด</option>
            <option
              v-for="code in ['ALLOW', 'REVIEW', 'DENY', 'UNKNOWN']"
              :key="code"
              :value="code"
            >
              {{ decisionLabel(code) }}
            </option>
          </select>
        </div>
        <div>
          <label for="gp-plate" class="block text-xs font-medium text-gray-700 mb-1"
            >ทะเบียนรถที่อ่านได้</label
          >
          <input
            id="gp-plate"
            v-model="filters.plate"
            type="text"
            placeholder="เช่น 1กข 1234"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label for="gp-from" class="block text-xs font-medium text-gray-700 mb-1"
            >ตั้งแต่วันที่</label
          >
          <input
            id="gp-from"
            v-model="filters.from"
            type="date"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label for="gp-to" class="block text-xs font-medium text-gray-700 mb-1">ถึงวันที่</label>
          <input
            id="gp-to"
            v-model="filters.to"
            type="date"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div class="lg:col-span-6 flex justify-end gap-3">
          <button
            type="button"
            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
            @click="resetFilters"
          >
            ล้างตัวกรอง
          </button>
          <button
            type="submit"
            data-test="gp-search"
            class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700"
            :disabled="loading"
          >
            ค้นหา
          </button>
        </div>
      </form>
    </div>

    <div v-if="error" class="rounded-md bg-red-50 p-4 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <div class="bg-surface rounded-lg shadow-sm border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead
            class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"
          >
            <tr>
              <th class="px-4 py-3">วัน-เวลา</th>
              <th class="px-4 py-3">จุดเข้าออก / ผลการจับคู่</th>
              <th class="px-4 py-3">บุคคล</th>
              <th class="px-4 py-3">ผลการตัดสินใจ</th>
              <th class="px-4 py-3 text-right">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-surface">
            <tr
              v-for="pass in passes"
              :key="pass.uuid"
              data-test="gate-pass-row"
              class="hover:bg-gray-50 align-top"
            >
              <td class="px-4 py-3 whitespace-nowrap text-gray-700 font-mono text-xs">
                {{ formatTime(pass.occurred_at) }}
              </td>
              <td class="px-4 py-3">
                <GatePassInfo :gate-pass="pass" compact />
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <div v-if="pass.person">
                  <div class="font-medium text-gray-900">{{ pass.person.name }}</div>
                  <div class="text-xs text-gray-500">{{ pass.person.person_code }}</div>
                </div>
                <span v-else class="text-xs text-gray-400">ไม่ทราบตัวตน</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <span
                  v-if="pass.decision"
                  data-test="gate-pass-decision"
                  class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold"
                  :class="DECISION_CLASSES[pass.decision.decision] || 'bg-gray-100 text-gray-800'"
                >
                  {{ decisionLabel(pass.decision.decision) }}
                </span>
                <span v-else class="text-xs text-gray-400">ยังไม่ตัดสิน</span>
                <div
                  v-if="pass.decision?.decision_source === 'OFFICER'"
                  class="text-xs text-gray-500"
                >
                  ตรวจสอบโดยเจ้าหน้าที่
                </div>
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-right text-xs">
                <router-link
                  v-if="auth.can('event.view') && primaryEventUuid(pass)"
                  :to="`/events/${primaryEventUuid(pass)}`"
                  class="text-blue-600 hover:text-blue-800 font-medium"
                >
                  รายละเอียด
                </router-link>
              </td>
            </tr>
            <tr v-if="passes.length === 0 && !loading">
              <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                ไม่พบการผ่านจุดเข้าออกตามเงื่อนไขที่ระบุ
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="pagination.total > 0"
        class="flex items-center justify-between border-t border-gray-200 px-4 py-3 bg-gray-50"
      >
        <div class="text-xs text-gray-700">
          หน้า {{ pagination.current_page }} จาก {{ pagination.last_page }} (ทั้งหมด
          {{ pagination.total }} รายการ)
        </div>
        <div class="flex gap-2">
          <button
            class="px-3 py-1 border border-gray-300 rounded text-xs bg-surface text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            :disabled="pagination.current_page <= 1 || loading"
            @click="fetchPasses(pagination.current_page - 1)"
          >
            ก่อนหน้า
          </button>
          <button
            class="px-3 py-1 border border-gray-300 rounded text-xs bg-surface text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            @click="fetchPasses(pagination.current_page + 1)"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
