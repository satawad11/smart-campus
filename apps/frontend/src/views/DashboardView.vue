<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { decisionLabel, errorMessage } from '@/constants/labels'

const loading = ref(true)
const error = ref(null)
const summary = ref({
  detected: 0,
  recognized: 0,
  review: 0,
  unknown: 0,
  allow: 0,
  deny: 0,
  cameras_online: 0,
  cameras_total: 0,
  ai_nodes_online: 0,
  ai_nodes_total: 0,
})

const hourlyData = ref([])
const decisionData = ref([])
const cameraData = ref([])
const departmentData = ref([])

const selectedSiteUuid = ref('')

const maxHourlyCount = computed(() => {
  if (!hourlyData.value.length) return 1
  const max = Math.max(...hourlyData.value.map((h) => h.count || 0))
  return max > 0 ? max : 1
})

const totalDecisions = computed(() => {
  return decisionData.value.reduce((acc, curr) => acc + (curr.count || 0), 0)
})

async function fetchDashboard() {
  loading.value = true
  error.value = null
  try {
    const params = {}
    if (selectedSiteUuid.value) {
      params.site_uuid = selectedSiteUuid.value
    }

    const [sumRes, hourRes, decRes, camRes, deptRes] = await Promise.all([
      api.get('/dashboard/summary', { params }),
      api.get('/dashboard/events-by-hour', { params }),
      api.get('/dashboard/events-by-decision', { params }),
      api.get('/dashboard/events-by-camera', { params }),
      api.get('/dashboard/events-by-department', { params }),
    ])

    summary.value = sumRes.data.data
    hourlyData.value = hourRes.data.data || []
    decisionData.value = decRes.data.data || []
    cameraData.value = camRes.data.data || []
    departmentData.value = deptRes.data.data || []
  } catch (err) {
    // Thai only: backend messages are English, so map known codes instead.
    error.value = errorMessage(err, 'ไม่สามารถโหลดข้อมูลแดชบอร์ดได้ กรุณาลองใหม่อีกครั้ง')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchDashboard()
})
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">แดชบอร์ดภาพรวมระบบ</h1>
        <p class="text-sm text-gray-500">สถิติเหตุการณ์และการเข้า-ออกประจำวัน</p>
      </div>
      <div class="flex items-center space-x-3">
        <button
          @click="fetchDashboard"
          class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-surface hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          :disabled="loading"
        >
          <svg
            class="w-4 h-4 mr-2"
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
          รีเฟรชข้อมูล
        </button>
      </div>
    </div>

    <!-- Error alert -->
    <div v-if="error" class="rounded-md bg-red-50 p-4 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Metric Cards Grid -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
      <!-- Detected -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-gray-500 uppercase">ตรวจพบทั้งหมด</span>
        <div class="mt-2 text-2xl font-bold text-gray-900">
          {{ summary.detected.toLocaleString() }}
        </div>
        <div class="mt-1 text-xs text-gray-500">วันนี้</div>
      </div>

      <!-- Recognized -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-blue-600 uppercase">ยืนยันตัวตนสำเร็จ</span>
        <div class="mt-2 text-2xl font-bold text-blue-700">
          {{ summary.recognized.toLocaleString() }}
        </div>
        <div class="mt-1 text-xs text-blue-500">คนรู้จัก</div>
      </div>

      <!-- Allow -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-green-600 uppercase">อนุญาตผ่านทาง</span>
        <div class="mt-2 text-2xl font-bold text-green-700">
          {{ summary.allow.toLocaleString() }}
        </div>
        <div class="mt-1 text-xs text-green-500">ผ่านเข้า-ออก</div>
      </div>

      <!-- Deny -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-red-600 uppercase">ปฏิเสธผ่านทาง</span>
        <div class="mt-2 text-2xl font-bold text-red-700">{{ summary.deny.toLocaleString() }}</div>
        <div class="mt-1 text-xs text-red-500">ไม่อนุญาต</div>
      </div>

      <!-- Cameras Online -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-gray-500 uppercase">กล้องออนไลน์</span>
        <div class="mt-2 text-2xl font-bold text-gray-900">
          {{ summary.cameras_online }} / {{ summary.cameras_total }}
        </div>
        <div
          class="mt-1 text-xs"
          :class="
            summary.cameras_online === summary.cameras_total ? 'text-green-600' : 'text-amber-600'
          "
        >
          {{
            summary.cameras_total > 0
              ? Math.round((summary.cameras_online / summary.cameras_total) * 100)
              : 0
          }}% พร้อมใช้งาน
        </div>
      </div>

      <!-- AI Nodes Online -->
      <div class="bg-surface p-4 rounded-lg shadow-sm border border-gray-200">
        <span class="text-xs font-medium text-gray-500 uppercase"
          >หน่วยประมวลผลปัญญาประดิษฐ์ออนไลน์</span
        >
        <div class="mt-2 text-2xl font-bold text-gray-900">
          {{ summary.ai_nodes_online }} / {{ summary.ai_nodes_total }}
        </div>
        <div class="mt-1 text-xs text-green-600">พร้อมประมวลผล</div>
      </div>
    </div>

    <!-- Charts / Breakdowns Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Hourly Activity Chart (2 Columns) -->
      <div class="lg:col-span-2 bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-base font-semibold text-gray-900 mb-4">
          ปริมาณการเข้า-ออกรายชั่วโมง (วันนี้)
        </h2>
        <div class="h-64 flex items-end space-x-1 pt-6 pb-2">
          <div
            v-for="hour in hourlyData"
            :key="hour.hour"
            class="flex-1 flex flex-col items-center group relative h-full justify-end"
          >
            <!-- Tooltip -->
            <div
              class="absolute -top-8 bg-gray-900 text-gray-50 shadow-lg text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition pointer-events-none whitespace-nowrap z-10"
            >
              {{ hour.hour }}: {{ hour.count }} ครั้ง
            </div>
            <!-- Bar -->
            <div
              class="w-full bg-blue-500 rounded-t hover:bg-blue-600 transition-all min-h-[4px]"
              :style="{ height: `${Math.max(4, (hour.count / maxHourlyCount) * 100)}%` }"
            ></div>
            <!-- Label -->
            <span
              class="text-[10px] text-gray-400 mt-1 transform -rotate-45 md:rotate-0 origin-center"
            >
              {{ hour.hour.split(':')[0] }}
            </span>
          </div>
        </div>
      </div>

      <!-- Decision Breakdown (1 Column) -->
      <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-base font-semibold text-gray-900 mb-4">สัดส่วนผลการตัดสินใจ</h2>
        <div class="space-y-4">
          <div v-for="item in decisionData" :key="item.decision" class="space-y-1">
            <div class="flex justify-between text-sm">
              <span
                class="font-medium"
                :class="{
                  'text-green-600': item.decision === 'ALLOW',
                  'text-red-600': item.decision === 'DENY',
                  'text-amber-600': item.decision === 'REVIEW',
                  'text-gray-600': item.decision === 'UNKNOWN',
                }"
              >
                {{ decisionLabel(item.decision) }}
              </span>
              <span class="text-gray-500"
                >{{ item.count.toLocaleString() }} ({{
                  totalDecisions > 0 ? Math.round((item.count / totalDecisions) * 100) : 0
                }}%)</span
              >
            </div>
            <div class="w-full bg-gray-100 rounded-full h-2">
              <div
                class="h-2 rounded-full"
                :class="{
                  'bg-green-500': item.decision === 'ALLOW',
                  'bg-red-500': item.decision === 'DENY',
                  'bg-amber-500': item.decision === 'REVIEW',
                  'bg-gray-500': item.decision === 'UNKNOWN',
                }"
                :style="{
                  width: `${totalDecisions > 0 ? (item.count / totalDecisions) * 100 : 0}%`,
                }"
              ></div>
            </div>
          </div>

          <div v-if="decisionData.length === 0" class="text-center py-8 text-sm text-gray-400">
            ไม่มีข้อมูลการตัดสินใจสำหรับวันนี้
          </div>
        </div>
      </div>
    </div>

    <!-- Secondary Grids: By Camera & By Department -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- By Camera -->
      <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-base font-semibold text-gray-900 mb-3">
          การเข้า-ออกแยกตามกล้อง (กล้องที่มีการเข้า-ออกมากที่สุด)
        </h2>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead>
              <tr class="text-left text-xs font-medium text-gray-500 uppercase">
                <th class="py-2">ชื่อกล้อง</th>
                <th class="py-2 text-right">จำนวนครั้ง</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="cam in cameraData" :key="cam.camera_uuid || cam.camera_name">
                <td class="py-2 font-medium text-gray-900">{{ cam.camera_name }}</td>
                <td class="py-2 text-right text-gray-700">{{ cam.count.toLocaleString() }}</td>
              </tr>
              <tr v-if="cameraData.length === 0">
                <td colspan="2" class="py-4 text-center text-gray-400">ไม่มีข้อมูล</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- By Department -->
      <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
        <h2 class="text-base font-semibold text-gray-900 mb-3">การเข้า-ออกแยกตามแผนก/หน่วยงาน</h2>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead>
              <tr class="text-left text-xs font-medium text-gray-500 uppercase">
                <th class="py-2">แผนก/หน่วยงาน</th>
                <th class="py-2 text-right">จำนวนครั้ง</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr
                v-for="dept in departmentData"
                :key="dept.department_uuid || dept.department_name"
              >
                <td class="py-2 font-medium text-gray-900">{{ dept.department_name }}</td>
                <td class="py-2 text-right text-gray-700">{{ dept.count.toLocaleString() }}</td>
              </tr>
              <tr v-if="departmentData.length === 0">
                <td colspan="2" class="py-4 text-center text-gray-400">ไม่มีข้อมูล</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
