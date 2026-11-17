<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import { apiErrorText } from '@/constants/labels'
import { personTypeLabel, statusLabel } from '@/constants/labels'
import { useFeaturesStore } from '@/stores/features'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const canView = computed(() => authStore.can('event.view'))
// VEH-012: plate search only while the ALPR module is on.
const featuresStore = useFeaturesStore()
featuresStore.load()

const events = ref([])
const loading = ref(false)
const errorMessage = ref('')

const filters = reactive({
  decision: route.query.decision || '',
  event_type: route.query.event_type || '',
  plate: route.query.plate || '',
  from: route.query.from || '',
  to: route.query.to || '',
  page: 1,
  per_page: 20,
})

const pagination = reactive({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

// Filtering to decision=REVIEW is the review queue (docs/09 — no separate
// endpoint exists; GET /events?decision=REVIEW already defaults to
// oldest-first for exactly this workflow, see EVT-009).
const isReviewQueue = computed(() => filters.decision === 'REVIEW')

async function fetchEvents() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''

  try {
    const res = await eventsService.listEvents({
      decision: filters.decision || undefined,
      event_type: filters.event_type || undefined,
      plate: filters.plate || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })

    events.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.last_page = res.meta.pagination.last_page
      pagination.total = res.meta.pagination.total
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายการเหตุการณ์ไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  filters.page = 1
  fetchEvents()
}

function showReviewQueue() {
  filters.decision = 'REVIEW'
  applyFilters()
}

function clearFilters() {
  filters.decision = ''
  filters.event_type = ''
  filters.from = ''
  filters.to = ''
  applyFilters()
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchEvents()
}

function decisionBadgeClass(decision) {
  return (
    {
      ALLOW: 'bg-green-100 text-green-800',
      REVIEW: 'bg-amber-100 text-amber-800',
      DENY: 'bg-red-100 text-red-800',
      UNKNOWN: 'bg-gray-100 text-gray-800',
    }[decision] || 'bg-gray-100 text-gray-600'
  )
}

onMounted(() => {
  fetchEvents()
})
</script>

<template>
  <div class="event-list-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ isReviewQueue ? 'คิวรอตรวจสอบ' : 'ประวัติเหตุการณ์' }}
        </h1>
        <p class="text-sm text-gray-500">
          {{
            isReviewQueue
              ? 'เหตุการณ์ที่รอการตรวจสอบจากเจ้าหน้าที่ (EVT-017)'
              : 'ค้นหาและกรองเหตุการณ์การรับรู้ใบหน้าทั้งหมด (EVT-017)'
          }}
        </p>
      </div>
      <button
        v-if="!isReviewQueue"
        @click="showReviewQueue"
        class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700"
      >
        ดูคิวรอตรวจสอบ
      </button>
      <button
        v-else
        @click="clearFilters"
        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-200"
      >
        กลับไปดูทั้งหมด
      </button>
    </div>

    <div
      v-if="!canView"
      class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm"
    >
      คุณไม่มีสิทธิ์เข้าถึงประวัติเหตุการณ์นี้ (ต้องมีสิทธิ์ event.view)
    </div>

    <template v-else>
      <div
        class="bg-surface p-4 rounded-xl border border-gray-200 flex flex-wrap gap-4 items-center"
      >
        <select
          v-model="filters.decision"
          @change="applyFilters"
          class="px-3 py-2 border rounded-lg text-sm"
        >
          <option value="">ทุกผลการตัดสินใจ</option>
          <option value="ALLOW">ALLOW</option>
          <option value="REVIEW">REVIEW</option>
          <option value="DENY">DENY</option>
          <option value="UNKNOWN">UNKNOWN</option>
        </select>
        <select
          v-model="filters.event_type"
          @change="applyFilters"
          class="px-3 py-2 border rounded-lg text-sm"
        >
          <option value="">ทุกประเภทเหตุการณ์</option>
          <option value="FACE_RECOGNIZED">FACE_RECOGNIZED</option>
          <option value="UNKNOWN_PERSON">UNKNOWN_PERSON</option>
          <option value="LIVENESS_FAILED">LIVENESS_FAILED</option>
          <option value="PLATE_RECOGNIZED">PLATE_RECOGNIZED</option>
          <option value="PLATE_UNKNOWN">PLATE_UNKNOWN</option>
          <option value="PLATE_LOW_CONFIDENCE">PLATE_LOW_CONFIDENCE</option>
        </select>
        <input
          v-if="featuresStore.isOn('alpr')"
          v-model="filters.plate"
          id="plate-filter"
          @keyup.enter="applyFilters"
          placeholder="ค้นหาทะเบียนรถ เช่น 1กข 1234"
          class="px-3 py-2 border rounded-lg text-sm"
        />
        <input
          v-model="filters.from"
          @change="applyFilters"
          type="datetime-local"
          class="px-3 py-2 border rounded-lg text-sm"
        />
        <input
          v-model="filters.to"
          @change="applyFilters"
          type="datetime-local"
          class="px-3 py-2 border rounded-lg text-sm"
        />
        <button
          @click="clearFilters"
          class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm"
        >
          ล้างตัวกรอง
        </button>
      </div>

      <div
        v-if="errorMessage"
        class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
      >
        {{ errorMessage }}
      </div>

      <div class="bg-surface rounded-xl border border-gray-200 overflow-hidden">
        <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลด...</div>
        <div v-else-if="events.length === 0" class="text-center py-12 text-gray-400">
          ไม่พบเหตุการณ์
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">บุคคล</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ประเภท</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สถานที่</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ผลการตัดสินใจ</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">เวลา</th>
              <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr
              v-for="event in events"
              :key="event.uuid"
              class="hover:bg-gray-50 cursor-pointer"
              @click="router.push({ name: 'events.show', params: { uuid: event.uuid } })"
            >
              <td class="px-6 py-4">
                <div v-if="event.plate" class="font-semibold text-gray-900">
                  {{ event.plate.plate_display || event.plate.raw_text || 'อ่านทะเบียนไม่ได้' }}
                  <span class="text-xs font-normal text-gray-500">{{
                    event.plate.plate_province_name || ''
                  }}</span>
                </div>
                <div v-else class="font-semibold text-gray-900">
                  {{ event.person?.name || event.person?.display_name || 'ไม่ทราบตัวตน' }}
                </div>
                <div v-if="event.person" class="mt-1 text-xs text-gray-500">
                  {{ personTypeLabel(event.person.person_type)
                  }}<span v-if="event.person.status">
                    · {{ statusLabel(event.person.status) }}</span
                  >
                </div>
              </td>
              <td class="px-6 py-4 text-xs text-gray-600">
                {{ event.event_type }}
              </td>
              <td class="px-6 py-4 text-xs">
                <div class="text-gray-800">{{ event.site?.name || '-' }}</div>
                <div class="text-gray-400">{{ event.camera?.name || '-' }}</div>
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="decisionBadgeClass(event.current_decision?.decision)"
                >
                  {{ event.current_decision?.decision || '-' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{ event.occurred_at ? new Date(event.occurred_at).toLocaleString() : '-' }}
              </td>
              <td class="px-6 py-4 text-right">
                <button
                  @click.stop="router.push({ name: 'events.show', params: { uuid: event.uuid } })"
                  class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
                >
                  รายละเอียด
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div
          v-if="pagination.last_page > 1"
          class="flex justify-between items-center px-6 py-3 border-t border-gray-200 text-sm"
        >
          <span class="text-gray-500">{{ pagination.total }} รายการทั้งหมด</span>
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
    </template>
  </div>
</template>
