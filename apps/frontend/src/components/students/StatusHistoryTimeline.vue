<script setup>
import { ref, onMounted, watch } from 'vue'
import { studentsService } from '@/services/students'
import AcademicStatusBadge from './AcademicStatusBadge.vue'

const props = defineProps({
  studentUuid: {
    type: String,
    required: true,
  },
})

const historyItems = ref([])
const loading = ref(true)
const errorMessage = ref('')
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 5,
  total: 0,
})

const sourceLabels = {
  MANUAL: { label: 'เจ้าหน้าที่บันทึก', class: 'bg-gray-100 text-gray-700' },
  API: { label: 'API ภายนอก', class: 'bg-blue-50 text-blue-700' },
  IMPORT: { label: 'นำเข้าข้อมูล', class: 'bg-indigo-50 text-indigo-700' },
  SIS_SYNC: { label: 'เชื่อมระบบทะเบียน SIS', class: 'bg-emerald-50 text-emerald-700' },
}

async function fetchHistory(page = 1) {
  if (!props.studentUuid) return
  loading.value = true
  errorMessage.value = ''

  try {
    const res = await studentsService.getStatusHistory(props.studentUuid, {
      page,
      per_page: pagination.value.per_page,
    })
    historyItems.value = res.data || []
    if (res.meta && res.meta.pagination) {
      pagination.value = {
        current_page: res.meta.pagination.current_page || page,
        last_page: res.meta.pagination.last_page || 1,
        per_page: res.meta.pagination.per_page || 5,
        total: res.meta.pagination.total || 0,
      }
    }
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถโหลดประวัติการเปลี่ยนสถานะได้'
  } finally {
    loading.value = false
  }
}

function formatDate(isoStr) {
  if (!isoStr) return '-'
  try {
    const d = new Date(isoStr)
    return d.toLocaleString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return isoStr
  }
}

function formatDateShort(dateStr) {
  if (!dateStr) return '-'
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    })
  } catch {
    return dateStr
  }
}

function getSourceDisplay(source) {
  return sourceLabels[source] || { label: source || 'MANUAL', class: 'bg-gray-100 text-gray-700' }
}

watch(
  () => props.studentUuid,
  (newUuid) => {
    if (newUuid) {
      fetchHistory(1)
    }
  },
)

onMounted(() => {
  fetchHistory(1)
})

defineExpose({
  refresh: () => fetchHistory(pagination.value.current_page),
})
</script>

<template>
  <div class="rounded-lg bg-white p-6 shadow">
    <div class="flex items-center justify-between border-b border-gray-200 pb-3 mb-4">
      <div>
        <h3 class="text-base font-semibold text-gray-900">
          ประวัติการเปลี่ยนสถานะการศึกษา (Academic Status History)
        </h3>
        <p class="text-xs text-gray-500 mt-0.5">
          บันทึกเส้นทางการเปลี่ยนแปลงสถานะทางการศึกษาของนักเรียน
        </p>
      </div>
      <button
        type="button"
        :disabled="loading"
        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
        title="รีเฟรชประวัติ"
        @click="fetchHistory(pagination.current_page)"
      >
        <svg
          class="h-3.5 w-3.5 text-gray-500 mr-1"
          :class="{ 'animate-spin': loading }"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        รีเฟรช
      </button>
    </div>

    <!-- Error Alert -->
    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-3 text-sm text-red-800 border border-red-200 mb-4"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <!-- Loading State -->
    <div v-if="loading && historyItems.length === 0" class="py-8 text-center">
      <svg class="mx-auto h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        />
      </svg>
      <p class="mt-2 text-xs text-gray-500">กำลังโหลดประวัติสถานะ...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="historyItems.length === 0" class="py-8 text-center">
      <svg
        class="mx-auto h-8 w-8 text-gray-400"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
      <p class="mt-2 text-sm text-gray-500">ยังไม่มีบันทึกประวัติการเปลี่ยนสถานะ</p>
    </div>

    <!-- Timeline List -->
    <div v-else class="flow-root">
      <ul role="list" class="-mb-8">
        <li v-for="(item, itemIdx) in historyItems" :key="item.uuid">
          <div class="relative pb-8">
            <span
              v-if="itemIdx !== historyItems.length - 1"
              class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-gray-200"
              aria-hidden="true"
            />
            <div class="relative flex space-x-3">
              <div>
                <span
                  class="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white bg-blue-50 text-blue-600"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                </span>
              </div>
              <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                <div class="flex-1">
                  <!-- Status Transition -->
                  <div class="flex flex-wrap items-center gap-2">
                    <template v-if="item.old_status">
                      <AcademicStatusBadge :status="item.old_status" size="sm" />
                      <svg
                        class="h-4 w-4 text-gray-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M14 5l7 7m0 0l-7 7m7-7H3"
                        />
                      </svg>
                    </template>
                    <span v-else class="text-xs text-gray-500 font-medium">เริ่มต้น:</span>
                    <AcademicStatusBadge :status="item.new_status" size="sm" />

                    <!-- Source Tag -->
                    <span
                      class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium"
                      :class="getSourceDisplay(item.source).class"
                    >
                      {{ getSourceDisplay(item.source).label }}
                    </span>
                  </div>

                  <!-- Reason -->
                  <p
                    v-if="item.reason"
                    class="mt-2 text-xs text-gray-700 bg-gray-50 p-2 rounded border border-gray-100"
                  >
                    <span class="font-medium text-gray-900">เหตุผล:</span> {{ item.reason }}
                  </p>

                  <!-- Effective & Actor Metadata -->
                  <div
                    class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500"
                  >
                    <div>
                      ช่วงวันมีผล:
                      <span class="text-gray-700">
                        {{ formatDateShort(item.effective_from) }} -
                        {{ item.effective_to ? formatDateShort(item.effective_to) : 'ปัจจุบัน' }}
                      </span>
                    </div>
                    <div v-if="item.changed_by">
                      ผู้ดำเนินการ:
                      <span class="font-medium text-gray-800">
                        {{
                          item.changed_by.display_name || item.changed_by.username || 'เจ้าหน้าที่'
                        }}
                      </span>
                    </div>
                  </div>
                </div>

                <div class="whitespace-nowrap text-right text-xs text-gray-500">
                  <time :datetime="item.created_at">{{ formatDate(item.created_at) }}</time>
                </div>
              </div>
            </div>
          </div>
        </li>
      </ul>

      <!-- Pagination Controls -->
      <div
        v-if="pagination.last_page > 1"
        class="flex items-center justify-between border-t border-gray-200 pt-4 mt-6"
      >
        <div class="text-xs text-gray-500">
          แสดงหน้า <span class="font-medium text-gray-900">{{ pagination.current_page }}</span> จาก
          <span class="font-medium text-gray-900">{{ pagination.last_page }}</span>
          (ทั้งหมด {{ pagination.total }} รายการ)
        </div>
        <div class="flex space-x-2">
          <button
            type="button"
            :disabled="pagination.current_page <= 1 || loading"
            class="inline-flex items-center rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="fetchHistory(pagination.current_page - 1)"
          >
            ย้อนกลับ
          </button>
          <button
            type="button"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            class="inline-flex items-center rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="fetchHistory(pagination.current_page + 1)"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
