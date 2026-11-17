<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import gatesService from '@/services/gates'
import {
  apiErrorText,
  isFeatureDisabled,
  ERROR_MESSAGES,
  GATE_DIRECTION_LABELS,
  GATE_STATUS_LABELS,
} from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const gates = ref([])
const loading = ref(false)
const errorMessage = ref('')
const disabledModule = ref(false)

const filters = reactive({ keyword: '', status: '', page: 1, per_page: 15 })
const pagination = reactive({ current_page: 1, total: 0, last_page: 1 })

const canManage = computed(() => authStore.can('gate.manage'))

// Create form. A new gate starts switched off; cameras are assigned on the detail page.
const showCreate = ref(false)
const saving = ref(false)
const createError = ref('')
const sites = ref([])
const form = reactive({
  site_uuid: '',
  zone_uuid: '',
  code: '',
  name: '',
  direction: 'BOTH',
  correlation_window_seconds: 10,
  notes: '',
})
const zones = computed(() => sites.value.find((s) => s.uuid === form.site_uuid)?.zones || [])

async function fetchGates() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await gatesService.listGates({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })
    gates.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.last_page = res.meta.pagination.last_page
      pagination.total = res.meta.pagination.total
    }
  } catch (err) {
    disabledModule.value = isFeatureDisabled(err)
    errorMessage.value = disabledModule.value
      ? ERROR_MESSAGES.FEATURE_DISABLED
      : apiErrorText(err, 'โหลดรายการจุดเข้าออกไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function search() {
  filters.page = 1
  fetchGates()
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchGates()
}

async function openCreate() {
  showCreate.value = !showCreate.value
  if (!showCreate.value || sites.value.length) return
  try {
    const res = await gatesService.getFormOptions()
    sites.value = res.data || []
  } catch (err) {
    createError.value = apiErrorText(err, 'โหลดข้อมูลไซต์และโซนไม่สำเร็จ')
  }
}

function onSiteChange() {
  form.zone_uuid = ''
}

async function createGate() {
  createError.value = ''
  if (!form.site_uuid || !form.zone_uuid || !form.code.trim() || !form.name.trim()) {
    createError.value = 'กรุณาเลือกไซต์และโซน และกรอกรหัสกับชื่อจุดเข้าออก'
    return
  }
  saving.value = true
  try {
    const res = await gatesService.createGate({
      site_uuid: form.site_uuid,
      zone_uuid: form.zone_uuid,
      code: form.code.trim(),
      name: form.name.trim(),
      direction: form.direction,
      correlation_window_seconds: Number(form.correlation_window_seconds),
      notes: form.notes.trim() || null,
    })
    showCreate.value = false
    router.push({ name: 'gates.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    createError.value = apiErrorText(err, 'สร้างจุดเข้าออกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function statusClass(status) {
  return status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'
}

onMounted(fetchGates)
</script>

<template>
  <div class="gate-list-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จุดเข้าออกอัจฉริยะ</h1>
        <p class="text-sm text-gray-500">
          จุดเข้าออกที่จับคู่ใบหน้ากับป้ายทะเบียนรถ ใบหน้ายังเป็นหลักในการยืนยันตัวตน
          ระบบตัดสินและแจ้งผลเท่านั้น ยังไม่สั่งเปิดไม้กั้น
        </p>
      </div>
      <button
        v-if="canManage && !disabledModule"
        @click="openCreate"
        data-test="open-create"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
      >
        + เพิ่มจุดเข้าออก
      </button>
    </div>

    <form
      v-if="showCreate"
      @submit.prevent="createGate"
      class="bg-surface p-5 rounded-xl border border-gray-200 grid grid-cols-1 md:grid-cols-3 gap-4"
      data-test="create-form"
    >
      <div>
        <label for="gate-site" class="block text-sm font-medium text-gray-700 mb-1">ไซต์</label>
        <select
          id="gate-site"
          v-model="form.site_uuid"
          @change="onSiteChange"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        >
          <option value="">เลือกไซต์</option>
          <option v-for="s in sites" :key="s.uuid" :value="s.uuid">{{ s.name }}</option>
        </select>
      </div>
      <div>
        <label for="gate-zone" class="block text-sm font-medium text-gray-700 mb-1">โซน</label>
        <select
          id="gate-zone"
          v-model="form.zone_uuid"
          :disabled="!form.site_uuid"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        >
          <option value="">เลือกโซน</option>
          <option v-for="z in zones" :key="z.uuid" :value="z.uuid">{{ z.name }}</option>
        </select>
      </div>
      <div>
        <label for="gate-direction" class="block text-sm font-medium text-gray-700 mb-1">
          ทิศทาง
        </label>
        <select
          id="gate-direction"
          v-model="form.direction"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        >
          <option v-for="(label, code) in GATE_DIRECTION_LABELS" :key="code" :value="code">
            {{ label }}
          </option>
        </select>
      </div>
      <div>
        <label for="gate-code" class="block text-sm font-medium text-gray-700 mb-1">รหัส</label>
        <input
          id="gate-code"
          v-model="form.code"
          placeholder="เช่น MAIN_IN"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <div>
        <label for="gate-name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อ</label>
        <input
          id="gate-name"
          v-model="form.name"
          placeholder="เช่น ประตูหน้า ขาเข้า"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <div>
        <label for="gate-window" class="block text-sm font-medium text-gray-700 mb-1">
          ช่วงเวลาจับคู่ใบหน้ากับทะเบียน (วินาที)
        </label>
        <input
          id="gate-window"
          v-model="form.correlation_window_seconds"
          type="number"
          min="1"
          max="60"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <div class="md:col-span-3">
        <label for="gate-notes" class="block text-sm font-medium text-gray-700 mb-1">
          หมายเหตุ
        </label>
        <input
          id="gate-notes"
          v-model="form.notes"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <p v-if="createError" class="md:col-span-3 text-sm text-red-600">{{ createError }}</p>
      <p class="md:col-span-3 text-xs text-gray-500">
        จุดเข้าออกใหม่จะปิดใช้งานไว้ก่อน เพิ่มกล้องในหน้ารายละเอียดแล้วจึงเปิดใช้งาน
        ไซต์และรหัสแก้ไขไม่ได้หลังสร้าง
      </p>
      <div class="md:col-span-3 flex gap-2">
        <button
          type="submit"
          :disabled="saving"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
        <button
          type="button"
          @click="showCreate = false"
          class="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"
        >
          ยกเลิก
        </button>
      </div>
    </form>

    <div class="bg-surface p-4 rounded-xl border border-gray-200 flex flex-wrap gap-4 items-center">
      <input
        v-model="filters.keyword"
        @keyup.enter="search"
        type="text"
        placeholder="ค้นหารหัสหรือชื่อจุดเข้าออก..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm"
      />
      <select v-model="filters.status" @change="search" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">ทุกสถานะ</option>
        <option v-for="(label, code) in GATE_STATUS_LABELS" :key="code" :value="code">
          {{ label }}
        </option>
      </select>
      <button
        @click="search"
        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
      >
        ค้นหา
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div class="bg-surface rounded-xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดรายการจุดเข้าออก...</div>
      <div v-else-if="gates.length === 0" class="text-center py-12 text-gray-400">
        ยังไม่มีจุดเข้าออก
      </div>
      <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500">จุดเข้าออก</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">ไซต์ / โซน</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">ทิศทาง</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">กล้อง</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">สถานะ</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500">การดำเนินการ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="g in gates" :key="g.uuid" class="hover:bg-gray-50">
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900">{{ g.name }}</div>
              <div class="text-xs text-gray-500">{{ g.code }}</div>
            </td>
            <td class="px-6 py-4 text-xs text-gray-600">
              {{ [g.site?.name, g.zone?.name].filter(Boolean).join(' / ') || '-' }}
            </td>
            <td class="px-6 py-4 text-gray-700">
              {{ GATE_DIRECTION_LABELS[g.direction] || g.direction }}
            </td>
            <td class="px-6 py-4 text-gray-700">{{ g.camera_count ?? 0 }} ตัว</td>
            <td class="px-6 py-4">
              <span
                class="px-2.5 py-1 rounded-full text-xs font-semibold"
                :class="statusClass(g.status)"
              >
                {{ GATE_STATUS_LABELS[g.status] || g.status }}
              </span>
            </td>
            <td class="px-6 py-4 text-right">
              <button
                @click="router.push({ name: 'gates.show', params: { uuid: g.uuid } })"
                class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
              >
                ดูรายละเอียด
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div
        v-if="pagination.last_page > 1"
        class="flex justify-between items-center px-6 py-3 border-t border-gray-200 text-sm"
      >
        <span class="text-gray-500">ทั้งหมด {{ pagination.total }} จุด</span>
        <div class="space-x-2">
          <button
            @click="goToPage(pagination.current_page - 1)"
            :disabled="pagination.current_page <= 1"
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
          >
            ก่อนหน้า
          </button>
          <span>{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          <button
            @click="goToPage(pagination.current_page + 1)"
            :disabled="pagination.current_page >= pagination.last_page"
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
