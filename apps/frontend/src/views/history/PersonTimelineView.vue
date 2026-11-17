<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { apiErrorText } from '@/constants/labels'
import { personTypeLabel, statusLabel } from '@/constants/labels'

const route = useRoute()
const router = useRouter()

const personUuid = route.params.uuid
const loading = ref(true)
const error = ref(null)
const person = ref(null)
const events = ref([])

async function fetchTimeline() {
  loading.value = true
  error.value = null
  try {
    const res = await api.get(`/persons/${personUuid}/timeline`)
    person.value = res.data.meta?.person || null
    events.value = (Array.isArray(res.data.data) ? res.data.data : []).map((item) => {
      if (item.type === 'STATUS_CHANGE') return {
        uuid: item.status_change.uuid,
        event_time: item.timestamp,
        timelineType: item.type,
        event_type: 'เปลี่ยนสถานะการศึกษา',
        status_change: item.status_change,
      }
      const event = item.event || {}
      return {
        ...event,
        uuid: event.uuid || item.timestamp,
        event_time: event.occurred_at || item.timestamp,
        decision: event.current_decision?.decision || 'UNKNOWN',
        confidence_score: event.face_score,
        timelineType: item.type,
      }
    })
  } catch (err) {
    error.value = apiErrorText(err, 'ไม่สามารถโหลดข้อมูลไทม์ไลน์บุคคลได้')
  } finally {
    loading.value = false
  }
}

function formatTime(isoStr) {
  if (!isoStr) return '-'
  return new Date(isoStr).toLocaleString('th-TH', {
    dateStyle: 'medium',
    timeStyle: 'medium',
  })
}

onMounted(() => {
  fetchTimeline()
})
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- Back button & Header -->
    <div class="flex items-center space-x-4">
      <button
        @click="router.back()"
        class="inline-flex items-center px-3 py-1.5 border border-gray-300 rounded-md text-sm text-gray-700 bg-surface hover:bg-gray-50"
      >
        <svg class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M10 19l-7-7m0 0l7-7m-7 7h18"
          />
        </svg>
        ย้อนกลับ
      </button>
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ไทม์ไลน์ประวัติการเข้า-ออกรายบุคคล</h1>
        <p class="text-sm text-gray-500">ลำดับเหตุการณ์การผ่านจุดตรวจของบุคคล</p>
      </div>
    </div>

    <!-- Error message -->
    <div v-if="error" class="rounded-md bg-red-50 p-4 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Person Header Card -->
    <div
      v-if="person"
      class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200 flex flex-col md:flex-row md:items-center justify-between gap-4"
    >
      <div class="flex items-center space-x-4">
        <div
          class="h-14 w-14 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xl"
        >
          {{ person.name?.charAt(0) || 'P' }}
        </div>
        <div>
          <h2 class="text-lg font-bold text-gray-900">{{ person.name }}</h2>
          <div class="text-xs text-gray-500 space-x-2">
            <span>รหัส: {{ person.person_code }}</span>
            <span>•</span>
            <span>ประเภท: {{ personTypeLabel(person.person_type) }}</span>
            <span v-if="person.department">•</span>
            <span v-if="person.department">แผนก: {{ person.department.name }}</span>
          </div>
        </div>
      </div>
      <div>
        <span
          class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
          :class="
            person.status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
          "
        >
          {{ statusLabel(person.status) }}
        </span>
      </div>
    </div>

    <!-- Timeline Chronological Stream -->
    <div class="bg-surface p-6 rounded-lg shadow-sm border border-gray-200">
      <h3 class="text-base font-semibold text-gray-900 mb-6">บันทึกเหตุการณ์ตามลำดับเวลา</h3>

      <div class="relative pl-6 border-l-2 border-gray-200 space-y-6">
          <div v-for="evt in events" :key="evt.uuid" class="relative">
          <!-- Timeline dot -->
          <div
            class="absolute -left-[31px] top-1.5 w-4 h-4 rounded-full border-2 border-white shadow"
            :class="{
              'bg-green-500': evt.decision === 'ALLOW',
              'bg-red-500': evt.decision === 'DENY',
              'bg-amber-500': evt.decision === 'REVIEW',
              'bg-gray-400': evt.decision === 'UNKNOWN' || evt.timelineType === 'STATUS_CHANGE',
            }"
          ></div>

          <div class="bg-gray-50 p-4 rounded-md border border-gray-100">
            <div class="flex justify-between items-start">
              <div>
                <span class="text-xs font-mono text-gray-500">{{
                  formatTime(evt.event_time)
                }}</span>
                <h4 class="text-sm font-semibold text-gray-900 mt-1">
                  {{ evt.camera?.name || 'ไม่ระบุกล้อง' }} ({{ evt.site?.name || 'ไม่ระบุไซต์' }})
                </h4>
              </div>
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
            </div>
            <p class="text-xs text-gray-600 mt-2">
              ประเภท: {{ evt.event_type }}
              <span v-if="evt.status_change"> · {{ statusLabel(evt.status_change.old_status) }} → {{ statusLabel(evt.status_change.new_status) }}<span v-if="evt.status_change.reason"> · {{ evt.status_change.reason }}</span></span>
              <span v-if="evt.confidence_score">
                | ค่าความเชื่อมั่น: {{ (evt.confidence_score * 100).toFixed(1) }}%</span
              >
            </p>
          </div>
        </div>

        <div v-if="events.length === 0 && !loading" class="text-sm text-gray-400 py-4">
          ไม่มีประวัติการเข้า-ออกของบุคคลนี้
        </div>
      </div>
    </div>
  </div>
</template>
