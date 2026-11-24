<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import vehiclesService from '@/services/vehicles'
import {
  apiErrorText,
  isFeatureDisabled,
  ERROR_MESSAGES,
  VEHICLE_TYPE_LABELS,
  VEHICLE_STATUS_LABELS,
} from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const vehicles = ref([])
const loading = ref(false)
const errorMessage = ref('')
const disabledModule = ref(false)

const filters = reactive({ keyword: '', status: '', vehicle_type: '', page: 1, per_page: 15 })
const pagination = reactive({ current_page: 1, total: 0, last_page: 1 })

const canManage = computed(() => authStore.can('vehicle.manage'))

// Register form
const showCreate = ref(false)
const saving = ref(false)
const createError = ref('')
const form = reactive({
  plate_number: '',
  plate_province: '',
  vehicle_type: 'CAR',
  brand: '',
  model: '',
  color: '',
  notes: '',
})

async function fetchVehicles() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await vehiclesService.listVehicles({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      vehicle_type: filters.vehicle_type || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })
    vehicles.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.last_page = res.meta.pagination.last_page
      pagination.total = res.meta.pagination.total
    }
  } catch (err) {
    disabledModule.value = isFeatureDisabled(err)
    errorMessage.value = disabledModule.value
      ? ERROR_MESSAGES.FEATURE_DISABLED
      : apiErrorText(err, 'โหลดรายการยานพาหนะไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function search() {
  filters.page = 1
  fetchVehicles()
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchVehicles()
}

async function registerVehicle() {
  createError.value = ''
  if (!form.plate_number.trim() || !form.plate_province.trim()) {
    createError.value = 'กรุณากรอกทะเบียนและจังหวัด'
    return
  }
  saving.value = true
  try {
    const payload = Object.fromEntries(
      Object.entries(form).map(([k, v]) => [
        k,
        typeof v === 'string' && v.trim() === '' ? null : v,
      ]),
    )
    const res = await vehiclesService.createVehicle(payload)
    showCreate.value = false
    router.push({ name: 'vehicles.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    createError.value = apiErrorText(err, 'ลงทะเบียนรถไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function statusClass(status) {
  if (status === 'BLOCKED') return 'bg-red-100 text-red-800'
  if (status === 'ACTIVE') return 'bg-green-100 text-green-800'
  return 'bg-gray-100 text-gray-700'
}

onMounted(fetchVehicles)
</script>

<template>
  <div class="vehicle-list-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการยานพาหนะ</h1>
        <p class="text-sm text-gray-500">
          ทะเบียนรถที่ลงทะเบียนไว้และบุคคลที่ผูกกับรถ ใช้ประกอบการตรวจที่ประตูเข้าออก
          (ใบหน้ายังเป็นหลักในการยืนยันตัวตน)
        </p>
      </div>
      <button
        v-if="canManage && !disabledModule"
        @click="showCreate = !showCreate"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
      >
        + ลงทะเบียนรถ
      </button>
    </div>

    <form
      v-if="showCreate"
      @submit.prevent="registerVehicle"
      class="bg-surface p-5 rounded-xl border border-gray-200 grid grid-cols-1 md:grid-cols-3 gap-4"
      data-test="create-form"
    >
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ทะเบียนรถ</label>
        <input
          v-model="form.plate_number"
          id="plate_number"
          placeholder="เช่น 1กข 1234"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">จังหวัด</label>
        <input
          v-model="form.plate_province"
          id="plate_province"
          placeholder="เช่น กรุงเทพมหานคร"
          class="w-full px-3 py-2 border rounded-lg text-sm"
        />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ประเภทรถ</label>
        <select v-model="form.vehicle_type" class="w-full px-3 py-2 border rounded-lg text-sm">
          <option v-for="(label, code) in VEHICLE_TYPE_LABELS" :key="code" :value="code">
            {{ label }}
          </option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ยี่ห้อ</label>
        <input v-model="form.brand" class="w-full px-3 py-2 border rounded-lg text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">รุ่น</label>
        <input v-model="form.model" class="w-full px-3 py-2 border rounded-lg text-sm" />
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">สี</label>
        <input v-model="form.color" class="w-full px-3 py-2 border rounded-lg text-sm" />
      </div>
      <div class="md:col-span-3">
        <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
        <input v-model="form.notes" class="w-full px-3 py-2 border rounded-lg text-sm" />
      </div>
      <p v-if="createError" class="md:col-span-3 text-sm text-red-600">{{ createError }}</p>
      <p class="md:col-span-3 text-xs text-gray-500">
        ทะเบียนและจังหวัดแก้ไขไม่ได้หลังลงทะเบียน
        ถ้าลงผิดให้เปลี่ยนสถานะเป็นไม่ใช้งานแล้วลงทะเบียนใหม่
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
        placeholder="ค้นหาทะเบียน ยี่ห้อ รุ่น หรือสี..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm"
      />
      <select
        v-model="filters.vehicle_type"
        @change="search"
        class="px-3 py-2 border rounded-lg text-sm"
      >
        <option value="">ทุกประเภท</option>
        <option v-for="(label, code) in VEHICLE_TYPE_LABELS" :key="code" :value="code">
          {{ label }}
        </option>
      </select>
      <select v-model="filters.status" @change="search" class="px-3 py-2 border rounded-lg text-sm">
        <option value="">ทุกสถานะ</option>
        <option v-for="(label, code) in VEHICLE_STATUS_LABELS" :key="code" :value="code">
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
      <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดรายการยานพาหนะ...</div>
      <div v-else-if="vehicles.length === 0" class="text-center py-12 text-gray-400">
        ไม่พบยานพาหนะตามเงื่อนไข
      </div>
      <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500">ทะเบียน</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">ประเภท</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">รายละเอียด</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500">สถานะ</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500">การดำเนินการ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="v in vehicles" :key="v.uuid" class="hover:bg-gray-50">
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900">{{ v.plate_display }}</div>
              <div class="text-xs text-gray-500">
                {{ v.plate_province_name || v.plate_province }}
              </div>
            </td>
            <td class="px-6 py-4 text-gray-700">
              {{ VEHICLE_TYPE_LABELS[v.vehicle_type] || v.vehicle_type }}
            </td>
            <td class="px-6 py-4 text-xs text-gray-600">
              {{ [v.brand, v.model, v.color].filter(Boolean).join(' · ') || '-' }}
            </td>
            <td class="px-6 py-4">
              <span
                class="px-2.5 py-1 rounded-full text-xs font-semibold"
                :class="statusClass(v.status)"
              >
                {{ VEHICLE_STATUS_LABELS[v.status] || v.status }}
              </span>
            </td>
            <td class="px-6 py-4 text-right">
              <button
                @click="router.push({ name: 'vehicles.show', params: { uuid: v.uuid } })"
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
        <span class="text-gray-500">ทั้งหมด {{ pagination.total }} คัน</span>
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
