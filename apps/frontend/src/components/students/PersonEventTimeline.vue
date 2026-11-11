<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import { apiErrorText } from '@/constants/labels'

const props = defineProps({
  personUuid: {
    type: String,
    required: true,
  },
})

const router = useRouter()
const authStore = useAuthStore()

const canView = ref(authStore.can('event.view'))
const events = ref([])
const loading = ref(true)
const errorMessage = ref('')
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 5,
  total: 0,
})

const decisionLabels = {
  ALLOW: { label: 'ALLOW', class: 'bg-green-50 text-green-700' },
  REVIEW: { label: 'REVIEW', class: 'bg-amber-50 text-amber-700' },
  DENY: { label: 'DENY', class: 'bg-red-50 text-red-700' },
  UNKNOWN: { label: 'UNKNOWN', class: 'bg-gray-100 text-gray-700' },
}

async function fetchTimeline(page = 1) {
  if (!props.personUuid || !canView.value) return
  loading.value = true
  errorMessage.value = ''

  try {
    // person_uuid + sort/order are all part of the same GET /events surface
    // EVT-008/EVT-015 already ship — no dedicated per-person endpoint needed.
    const res = await eventsService.listEvents({
      person_uuid: props.personUuid,
      sort: 'occurred_at',
      order: 'desc',
      page,
      per_page: pagination.value.per_page,
    })
    events.value = res.data || []
    if (res.meta && res.meta.pagination) {
      pagination.value = {
        current_page: res.meta.pagination.current_page || page,
        last_page: res.meta.pagination.last_page || 1,
        per_page: res.meta.pagination.per_page || 5,
        total: res.meta.pagination.total || 0,
      }
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ไม่สามารถโหลดประวัติเหตุการณ์การเข้าออกได้')
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

function getDecisionDisplay(decision) {
  return decisionLabels[decision] || { label: decision || '-', class: 'bg-gray-100 text-gray-600' }
}

watch(
  () => props.personUuid,
  (newUuid) => {
    if (newUuid) fetchTimeline(1)
  },
)

onMounted(() => {
  fetchTimeline(1)
})

defineExpose({
  refresh: () => fetchTimeline(pagination.value.current_page),
})
</script>

<template>
  <div v-if="canView" class="rounded-lg bg-surface p-6 shadow">
    <div class="flex items-center justify-between border-b border-gray-200 pb-3 mb-4">
      <div>
        <h3 class="text-base font-semibold text-gray-900">
          ประวัติการรับรู้ใบหน้า (Event Timeline)
        </h3>
        <p class="text-xs text-gray-500 mt-0.5">
          เหตุการณ์การรับรู้ใบหน้าและผลการตัดสินใจของบุคคลนี้ (EVT-019)
        </p>
      </div>
      <button
        type="button"
        :disabled="loading"
        class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
        title="รีเฟรชประวัติ"
        @click="fetchTimeline(pagination.current_page)"
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

    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-3 text-sm text-red-800 border border-red-200 mb-4"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading && events.length === 0" class="py-8 text-center">
      <svg class="mx-auto h-6 w-6 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        />
      </svg>
      <p class="mt-2 text-xs text-gray-500">กำลังโหลดประวัติเหตุการณ์...</p>
    </div>

    <div v-else-if="events.length === 0" class="py-8 text-center">
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
          d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
        />
      </svg>
      <p class="mt-2 text-sm text-gray-500">ยังไม่มีบันทึกเหตุการณ์การรับรู้ใบหน้าของบุคคลนี้</p>
    </div>

    <div v-else class="flow-root">
      <ul role="list" class="-mb-8">
        <li v-for="(item, itemIdx) in events" :key="item.uuid">
          <div class="relative pb-8">
            <span
              v-if="itemIdx !== events.length - 1"
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
                      d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
                    />
                  </svg>
                </span>
              </div>
              <div
                class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5 cursor-pointer"
                @click="router.push({ name: 'events.show', params: { uuid: item.uuid } })"
              >
                <div class="flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-gray-700 font-medium">{{ item.event_type }}</span>
                    <span
                      class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium"
                      :class="getDecisionDisplay(item.current_decision?.decision).class"
                    >
                      {{ getDecisionDisplay(item.current_decision?.decision).label }}
                    </span>
                  </div>

                  <div
                    class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500"
                  >
                    <div>
                      สถานที่:
                      <span class="text-gray-700">
                        {{ item.site?.name || '-' }} / {{ item.camera?.name || '-' }}
                      </span>
                    </div>
                    <div v-if="item.current_decision?.reason_code">
                      เหตุผล:
                      <span class="text-gray-700">{{ item.current_decision.reason_code }}</span>
                    </div>
                  </div>
                </div>

                <div class="whitespace-nowrap text-right text-xs text-gray-500">
                  <time :datetime="item.occurred_at">{{ formatDate(item.occurred_at) }}</time>
                </div>
              </div>
            </div>
          </div>
        </li>
      </ul>

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
            class="inline-flex items-center rounded border border-gray-300 bg-surface px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="fetchTimeline(pagination.current_page - 1)"
          >
            ย้อนกลับ
          </button>
          <button
            type="button"
            :disabled="pagination.current_page >= pagination.last_page || loading"
            class="inline-flex items-center rounded border border-gray-300 bg-surface px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
            @click="fetchTimeline(pagination.current_page + 1)"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
