<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import eventsService from '@/services/events'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const canView = computed(() => authStore.can('event.view'))

const events = ref([])
const loading = ref(false)
const errorMessage = ref('')

const filters = reactive({
  from: '',
  to: '',
  page: 1,
  per_page: 20,
})

const pagination = reactive({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

async function fetchUnknownPersons() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''
  try {
    const res = await eventsService.listEvents({
      event_type: 'UNKNOWN_PERSON',
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
    errorMessage.value = apiErrorText(err, 'โหลดรายการบุคคลไม่ทราบตัวตนไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  filters.page = 1
  fetchUnknownPersons()
}

function clearFilters() {
  filters.from = ''
  filters.to = ''
  applyFilters()
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchUnknownPersons()
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

// A row is "still unresolved" while its latest decision is the system
// default UNKNOWN — an officer hasn't looked at it yet. Once reviewed
// (ALLOW/REVIEW/DENY appended), it drops out of this bucket, matching the
// same "latest decision, never the original" rule the review queue uses
// (EVT-009/EVT-010) — never a second, divergent definition of "resolved".
const unresolvedCount = computed(
  () => events.value.filter((e) => e.current_decision?.decision === 'UNKNOWN').length,
)

onMounted(() => {
  fetchUnknownPersons()
})
</script>

<template>
  <div class="unknown-persons-view max-w-6xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900">บุคคลไม่ทราบตัวตน</h1>
      <p class="text-sm text-gray-500">
        เหตุการณ์ที่ระบบไม่สามารถจับคู่ใบหน้ากับบุคคลที่ลงทะเบียนได้ (EVT-018)
      </p>
    </div>

    <div
      v-if="!canView"
      class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm"
    >
      คุณไม่มีสิทธิ์เข้าถึงหน้านี้ (ต้องมีสิทธิ์ event.view)
    </div>

    <template v-else>
      <div
        class="bg-surface p-4 rounded-xl border border-gray-200 flex flex-wrap gap-4 items-center"
      >
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
        <span class="ml-auto text-sm text-gray-500">
          ยังไม่ได้ตรวจสอบในหน้านี้:
          <span class="font-semibold text-amber-600">{{ unresolvedCount }}</span>
        </span>
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
          ไม่พบเหตุการณ์บุคคลไม่ทราบตัวตนในช่วงเวลานี้
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สถานที่</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">กล้อง</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">
                สถานะการตรวจสอบ
              </th>
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
              <td class="px-6 py-4 text-gray-800">
                {{ event.site?.name || '-' }}
              </td>
              <td class="px-6 py-4 text-xs text-gray-600">
                {{ event.camera?.name || '-' }}
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
