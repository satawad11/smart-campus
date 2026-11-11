<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()

const loading = ref(false)
const error = ref(null)
const events = ref([])
const meta = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
})

const filters = ref({
  keyword: '',
  from: '',
  to: '',
  event_type: '',
  decision: '',
  academic_status: '',
})

async function fetchEvents(page = 1) {
  loading.value = true
  error.value = null
  try {
    const params = {
      page,
      ...filters.value,
    }
    // Remove empty strings
    Object.keys(params).forEach((key) => {
      if (!params[key]) delete params[key]
    })

    const res = await api.get('/history/search', { params })
    events.value = res.data.data || []
    meta.value = res.data.meta || { current_page: 1, last_page: 1, total: events.value.length }
  } catch (err) {
    error.value = apiErrorText(err, 'ไม่สามารถค้นหาข้อมูลเหตุการณ์ได้')
  } finally {
    loading.value = false
  }
}

function resetFilters() {
  filters.value = {
    keyword: '',
    from: '',
    to: '',
    event_type: '',
    decision: '',
    academic_status: '',
  }
  fetchEvents(1)
}

function formatTime(isoStr) {
  if (!isoStr) return '-'
  return new Date(isoStr).toLocaleString('th-TH', {
    dateStyle: 'medium',
    timeStyle: 'medium',
  })
}

function viewTimeline(personUuid) {
  if (personUuid) {
    router.push({ name: 'persons.timeline', params: { uuid: personUuid } })
  }
}

onMounted(() => {
  fetchEvents(1)
})
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          ค้นหาประวัติการเข้า-ออก (Historical Search)
        </h1>
        <p class="text-sm text-gray-500">
          ตรวจสอบและค้นหาบันทึกเหตุการณ์การเข้า-ออกย้อนหลังทั้งหมด
        </p>
      </div>
    </div>

    <!-- Filters Box -->
    <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
      <form
        @submit.prevent="fetchEvents(1)"
        class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4"
      >
        <!-- Keyword -->
        <div class="lg:col-span-2">
          <label class="block text-xs font-medium text-gray-700 mb-1">คำค้นหา (ชื่อ / รหัส)</label>
          <input
            v-model="filters.keyword"
            type="text"
            placeholder="ค้นหาชื่อ, รหัสนักเรียน..."
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <!-- From Date -->
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">ตั้งแต่วันที่</label>
          <input
            v-model="filters.from"
            type="date"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <!-- To Date -->
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">ถึงวันที่</label>
          <input
            v-model="filters.to"
            type="date"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          />
        </div>

        <!-- Event Type -->
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">ประเภทเหตุการณ์</label>
          <select
            v-model="filters.event_type"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="">ทั้งหมด</option>
            <option value="FACE_RECOGNIZED">รู้จักบุคคล (FACE_RECOGNIZED)</option>
            <option value="UNKNOWN_FACE">ไม่ทราบตัวตน (UNKNOWN_FACE)</option>
            <option value="LIVENESS_FAILED">ตรวจจับภาพลวง (LIVENESS_FAILED)</option>
          </select>
        </div>

        <!-- Decision -->
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">ผลการตัดสินใจ</label>
          <select
            v-model="filters.decision"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
          >
            <option value="">ทั้งหมด</option>
            <option value="ALLOW">อนุญาต (ALLOW)</option>
            <option value="DENY">ปฏิเสธ (DENY)</option>
            <option value="REVIEW">รอตรวจสอบ (REVIEW)</option>
            <option value="UNKNOWN">ไม่รู้จัก (UNKNOWN)</option>
          </select>
        </div>

        <!-- Action Buttons -->
        <div class="lg:col-span-6 flex justify-end space-x-3 pt-2">
          <button
            type="button"
            @click="resetFilters"
            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            ล้างตัวกรอง
          </button>
          <button
            type="submit"
            class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
            :disabled="loading"
          >
            ค้นหา
          </button>
        </div>
      </form>
    </div>

    <!-- Error message -->
    <div v-if="error" class="rounded-md bg-red-50 p-4 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Results Table -->
    <div class="bg-surface rounded-lg shadow-sm border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead
            class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider"
          >
            <tr>
              <th class="px-4 py-3">วัน-เวลา</th>
              <th class="px-4 py-3">ประเภท</th>
              <th class="px-4 py-3">ผลการตัดสินใจ</th>
              <th class="px-4 py-3">บุคคล</th>
              <th class="px-4 py-3">กล้อง</th>
              <th class="px-4 py-3">ไซต์</th>
              <th class="px-4 py-3 text-right">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-surface">
            <tr v-for="evt in events" :key="evt.uuid" class="hover:bg-gray-50">
              <td class="px-4 py-3 whitespace-nowrap text-gray-700 font-mono text-xs">
                {{ formatTime(evt.event_time) }}
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-xs font-medium">
                {{ evt.event_type }}
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
                  :class="{
                    'bg-green-100 text-green-800': evt.decision === 'ALLOW',
                    'bg-red-100 text-red-800': evt.decision === 'DENY',
                    'bg-amber-100 text-amber-800': evt.decision === 'REVIEW',
                    'bg-gray-100 text-gray-800': evt.decision === 'UNKNOWN',
                  }"
                >
                  {{ evt.decision }}
                </span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <div v-if="evt.person" class="text-sm">
                  <div class="font-medium text-gray-900">{{ evt.person.name }}</div>
                  <div class="text-xs text-gray-500">{{ evt.person.person_code }}</div>
                </div>
                <span v-else class="text-gray-400 text-xs">-</span>
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-gray-700 text-xs">
                {{ evt.camera?.name || '-' }}
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-gray-500 text-xs">
                {{ evt.site?.name || '-' }}
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-right text-xs">
                <button
                  v-if="evt.person?.uuid"
                  @click="viewTimeline(evt.person.uuid)"
                  class="text-blue-600 hover:text-blue-800 font-medium mr-3"
                >
                  ไทม์ไลน์
                </button>
                <router-link :to="`/events/${evt.uuid}`" class="text-gray-600 hover:text-gray-900">
                  รายละเอียด
                </router-link>
              </td>
            </tr>
            <tr v-if="events.length === 0 && !loading">
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                ไม่พบประวัติเหตุการณ์ตามเงื่อนไขที่ระบุ
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="meta.total > 0"
        class="flex items-center justify-between border-t border-gray-200 px-4 py-3 bg-gray-50 sm:px-6"
      >
        <div class="text-xs text-gray-700">
          แสดงหน้า <span class="font-medium">{{ meta.current_page }}</span> จากทั้งหมด
          <span class="font-medium">{{ meta.last_page }}</span> หน้า (ทั้งหมด
          {{ meta.total }} รายการ)
        </div>
        <div class="flex space-x-2">
          <button
            @click="fetchEvents(meta.current_page - 1)"
            :disabled="meta.current_page <= 1 || loading"
            class="px-3 py-1 border border-gray-300 rounded text-xs bg-surface text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            ก่อนหน้า
          </button>
          <button
            @click="fetchEvents(meta.current_page + 1)"
            :disabled="meta.current_page >= meta.last_page || loading"
            class="px-3 py-1 border border-gray-300 rounded text-xs bg-surface text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
