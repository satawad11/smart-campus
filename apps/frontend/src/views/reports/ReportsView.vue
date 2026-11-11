<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { apiErrorText, statusLabel } from '@/constants/labels'

const activeTab = ref('generator') // 'generator' | 'exports'

// Report Generator State
const reportType = ref('daily')
const reportDate = ref(new Date().toISOString().split('T')[0])
const fromDate = ref('')
const toDate = ref('')
const year = ref(new Date().getFullYear())
const calendarType = ref('calendar')

const reportLoading = ref(false)
const reportError = ref(null)
const reportData = ref(null)

// Export State
const exporting = ref(false)
const exportSuccess = ref(null)

// Export History State
const exportsList = ref([])
const exportsLoading = ref(false)
const exportsMeta = ref({ current_page: 1, last_page: 1, total: 0 })

async function generateReport() {
  reportLoading.value = true
  reportError.value = null
  reportData.value = null

  try {
    let endpoint = `/reports/${reportType.value}`
    const params = {}

    if (reportType.value === 'daily') {
      params.date = reportDate.value
    } else if (reportType.value === 'weekly') {
      params.date = reportDate.value
    } else if (reportType.value === 'monthly') {
      params.month = reportDate.value.substring(0, 7)
    } else if (reportType.value === 'yearly') {
      params.year = year.value
      params.type = calendarType.value
    } else if (reportType.value === 'custom') {
      params.from = fromDate.value
      params.to = toDate.value
    } else if (['review', 'unknown', 'camera'].includes(reportType.value)) {
      if (fromDate.value) params.from = fromDate.value
      if (toDate.value) params.to = toDate.value
    }

    const res = await api.get(endpoint, { params })
    reportData.value = res.data.data
  } catch (err) {
    reportError.value = apiErrorText(err, 'ไม่สามารถสร้างรายงานได้')
  } finally {
    reportLoading.value = false
  }
}

async function triggerExport(format) {
  exporting.value = true
  exportSuccess.value = null
  try {
    const filters = {}
    if (reportType.value === 'daily') filters.date = reportDate.value
    if (reportType.value === 'weekly') filters.date = reportDate.value
    if (reportType.value === 'monthly') filters.date = reportDate.value
    if (reportType.value === 'yearly') {
      filters.year = year.value
      filters.type = calendarType.value
    }
    if (fromDate.value) filters.from = fromDate.value
    if (toDate.value) filters.to = toDate.value

    await api.post('/reports/exports', {
      report_type: reportType.value,
      format,
      filters,
    })

    exportSuccess.value = `ส่งออกรายงาน (${format}) สำเร็จ! กำลังประมวลผลในระบบ`
    fetchExports()
  } catch (err) {
    reportError.value = apiErrorText(err, 'ไม่สามารถส่งออกรายงานได้')
  } finally {
    exporting.value = false
  }
}

async function fetchExports(page = 1) {
  exportsLoading.value = true
  try {
    const res = await api.get('/reports/exports', { params: { page } })
    exportsList.value = res.data.data || []
    exportsMeta.value = res.data.meta || {
      current_page: 1,
      last_page: 1,
      total: exportsList.value.length,
    }
  } catch (err) {
    console.error('Failed to load export history', err)
  } finally {
    exportsLoading.value = false
  }
}

function downloadExport(exportItem) {
  window.open(`/api/v1/reports/exports/${exportItem.uuid}/download`, '_blank')
}

function formatTime(isoStr) {
  if (!isoStr) return '-'
  return new Date(isoStr).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' })
}

onMounted(() => {
  generateReport()
  fetchExports()
})
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ศูนย์รายงานและการส่งออกข้อมูล</h1>
        <p class="text-sm text-gray-500">
          สร้างรายงานสรุปสถิติประจำรอบเวลา และดาวน์โหลดไฟล์ส่งออก (XLSX, CSV, PDF)
        </p>
      </div>
      <!-- Tab Switcher -->
      <div class="flex bg-gray-100 p-1 rounded-lg">
        <button
          @click="activeTab = 'generator'"
          class="px-4 py-2 text-sm font-medium rounded-md transition"
          :class="
            activeTab === 'generator'
              ? 'bg-surface shadow-sm text-blue-700'
              : 'text-gray-600 hover:text-gray-900'
          "
        >
          สร้างรายงาน
        </button>
        <button
          @click="activeTab = 'exports'"
          class="px-4 py-2 text-sm font-medium rounded-md transition"
          :class="
            activeTab === 'exports'
              ? 'bg-surface shadow-sm text-blue-700'
              : 'text-gray-600 hover:text-gray-900'
          "
        >
          ประวัติการส่งออก
        </button>
      </div>
    </div>

    <!-- TAB 1: Report Generator -->
    <div v-if="activeTab === 'generator'" class="space-y-6">
      <!-- Filter Panel -->
      <div class="bg-surface p-5 rounded-lg shadow-sm border border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Report Type -->
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">ประเภทรายงาน</label>
            <select
              v-model="reportType"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            >
              <option value="daily">รายงานประจำวัน</option>
              <option value="weekly">รายงานประจำสัปดาห์</option>
              <option value="monthly">รายงานประจำเดือน</option>
              <option value="yearly">รายงานประจำปี</option>
              <option value="custom">รายงานกำหนดช่วงเวลา</option>
              <option value="review">รายงานคิวตรวจสอบ</option>
              <option value="unknown">รายงานบุคคลไม่ทราบตัวตน</option>
              <option value="camera">รายงานสถิติแยกตามกล้อง</option>
              <option value="ai-node">รายงานสถานะหน่วยประมวลผล</option>
            </select>
          </div>

          <!-- Date field for daily/weekly/monthly -->
          <div v-if="['daily', 'weekly', 'monthly'].includes(reportType)">
            <label class="block text-xs font-medium text-gray-700 mb-1">เลือกวันที่อ้างอิง</label>
            <input
              v-model="reportDate"
              type="date"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <!-- Year & Calendar type for yearly -->
          <div v-if="reportType === 'yearly'" class="flex space-x-2">
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">ปี (ค.ศ.)</label>
              <input
                v-model="year"
                type="number"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">รูปแบบปี</label>
              <select
                v-model="calendarType"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              >
                <option value="calendar">ปีปฏิทิน</option>
                <option value="academic">ปีการศึกษา</option>
              </select>
            </div>
          </div>

          <!-- Date Range for custom / review / unknown / camera -->
          <div
            v-if="['custom', 'review', 'unknown', 'camera'].includes(reportType)"
            class="md:col-span-2 flex space-x-2"
          >
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">ตั้งแต่วันที่</label>
              <input
                v-model="fromDate"
                type="date"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">ถึงวันที่</label>
              <input
                v-model="toDate"
                type="date"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
              />
            </div>
          </div>

          <!-- Generate & Export buttons -->
          <div class="flex items-end space-x-2">
            <button
              @click="generateReport"
              class="px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
              :disabled="reportLoading"
            >
              แสดงรายงาน
            </button>
            <div class="inline-flex rounded-md shadow-sm">
              <button
                @click="triggerExport('CSV')"
                class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-l-md text-xs font-medium text-gray-700 hover:bg-gray-200"
                :disabled="exporting"
              >
                CSV
              </button>
              <button
                @click="triggerExport('XLSX')"
                class="px-3 py-2 bg-gray-100 border-t border-b border-gray-300 text-xs font-medium text-gray-700 hover:bg-gray-200"
                :disabled="exporting"
              >
                XLSX
              </button>
              <button
                @click="triggerExport('PDF')"
                class="px-3 py-2 bg-gray-100 border border-gray-300 rounded-r-md text-xs font-medium text-gray-700 hover:bg-gray-200"
                :disabled="exporting"
              >
                PDF
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Alerts -->
      <div
        v-if="reportError"
        class="rounded-md bg-red-50 p-4 border border-red-200 text-sm text-red-700"
      >
        {{ reportError }}
      </div>
      <div
        v-if="exportSuccess"
        class="rounded-md bg-green-50 p-4 border border-green-200 text-sm text-green-700 flex justify-between items-center"
      >
        <span>{{ exportSuccess }}</span>
        <button @click="activeTab = 'exports'" class="text-xs font-bold underline">
          ดูคิวส่งออก
        </button>
      </div>

      <!-- Report Preview Content -->
      <div v-if="reportData" class="space-y-6">
        <!-- Totals Cards -->
        <div v-if="reportData.totals" class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-gray-500 uppercase font-semibold">ตรวจพบทั้งหมด</span>
            <div class="text-xl font-bold text-gray-900 mt-1">
              {{ reportData.totals.detected.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-blue-600 uppercase font-semibold">ยืนยันตัวตน</span>
            <div class="text-xl font-bold text-blue-700 mt-1">
              {{ reportData.totals.recognized.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-green-600 uppercase font-semibold">อนุญาต</span>
            <div class="text-xl font-bold text-green-700 mt-1">
              {{ reportData.totals.allow.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-red-600 uppercase font-semibold">ปฏิเสธ</span>
            <div class="text-xl font-bold text-red-700 mt-1">
              {{ reportData.totals.deny.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-amber-600 uppercase font-semibold">รอตรวจสอบ</span>
            <div class="text-xl font-bold text-amber-700 mt-1">
              {{ reportData.totals.review.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-gray-600 uppercase font-semibold">ไม่ทราบตัวตน</span>
            <div class="text-xl font-bold text-gray-700 mt-1">
              {{ reportData.totals.unknown.toLocaleString() }}
            </div>
          </div>
          <div class="bg-surface p-3 rounded-lg border border-gray-200">
            <span class="text-[10px] text-purple-600 uppercase font-semibold">คนไม่ซ้ำ</span>
            <div class="text-xl font-bold text-purple-700 mt-1">
              {{ reportData.totals.unique_persons_recognized.toLocaleString() }}
            </div>
          </div>
        </div>

        <!-- Note if present (e.g. unknown report) -->
        <div
          v-if="reportData.note"
          class="bg-amber-50 border border-amber-200 rounded-md p-3 text-xs text-amber-800"
        >
          💡 {{ reportData.note }}
        </div>

        <!-- Hourly breakdown (for daily) -->
        <div v-if="reportData.by_hour" class="bg-surface p-5 rounded-lg border border-gray-200">
          <h3 class="text-sm font-bold text-gray-900 mb-3">แจกแจงรายชั่วโมง</h3>
          <div class="grid grid-cols-6 md:grid-cols-12 gap-2 text-center text-xs">
            <div v-for="h in reportData.by_hour" :key="h.hour" class="p-2 bg-gray-50 rounded">
              <span class="text-gray-500">{{ h.hour }}</span>
              <div class="font-bold text-gray-900 mt-1">{{ h.count }}</div>
            </div>
          </div>
        </div>

        <!-- Daily breakdown (for weekly / monthly / custom) -->
        <div v-if="reportData.by_day" class="bg-surface p-5 rounded-lg border border-gray-200">
          <h3 class="text-sm font-bold text-gray-900 mb-3">แจกแจงรายวัน</h3>
          <div class="overflow-x-auto max-h-60">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
              <thead class="bg-gray-50 text-left font-semibold text-gray-500 uppercase">
                <tr>
                  <th class="p-2">วันที่</th>
                  <th class="p-2">วัน</th>
                  <th class="p-2 text-right">จำนวนครั้ง</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="d in reportData.by_day" :key="d.date">
                  <td class="p-2 font-mono">{{ d.date }}</td>
                  <td class="p-2">{{ d.day_name }}</td>
                  <td class="p-2 text-right font-medium">{{ d.count.toLocaleString() }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Camera List (for camera report) -->
        <div v-if="reportData.cameras" class="bg-surface p-5 rounded-lg border border-gray-200">
          <h3 class="text-sm font-bold text-gray-900 mb-3">สถิติรายกล้องทั้งหมด</h3>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
              <thead class="bg-gray-50 text-left font-semibold text-gray-500 uppercase">
                <tr>
                  <th class="p-2">รหัสกล้อง</th>
                  <th class="p-2">ชื่อกล้อง</th>
                  <th class="p-2">ไซต์ / โซน</th>
                  <th class="p-2">สถานะ</th>
                  <th class="p-2 text-right">ตรวจพบ</th>
                  <th class="p-2 text-right">อนุญาต</th>
                  <th class="p-2 text-right">คนไม่ซ้ำ</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="c in reportData.cameras" :key="c.camera_uuid">
                  <td class="p-2 font-mono">{{ c.camera_code }}</td>
                  <td class="p-2 font-medium">{{ c.name }}</td>
                  <td class="p-2 text-gray-500">{{ c.site_name }} / {{ c.zone_name }}</td>
                  <td class="p-2">
                    <span
                      class="px-2 py-0.5 rounded text-[10px]"
                      :class="
                        c.status === 'ONLINE'
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      "
                    >
                      {{ statusLabel(c.status) }}
                    </span>
                  </td>
                  <td class="p-2 text-right font-medium">
                    {{ c.totals.detected.toLocaleString() }}
                  </td>
                  <td class="p-2 text-right text-green-600 font-medium">
                    {{ c.totals.allow.toLocaleString() }}
                  </td>
                  <td class="p-2 text-right text-purple-600 font-medium">
                    {{ c.totals.unique_persons_recognized.toLocaleString() }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- AI Nodes (for ai-node report) -->
        <div v-if="reportData.nodes" class="bg-surface p-5 rounded-lg border border-gray-200">
          <h3 class="text-sm font-bold text-gray-900 mb-3">สถานะหน่วยประมวลผลทั้งหมด</h3>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
              <thead class="bg-gray-50 text-left font-semibold text-gray-500 uppercase">
                <tr>
                  <th class="p-2">รหัสหน่วย</th>
                  <th class="p-2">ชื่อ</th>
                  <th class="p-2">ไซต์</th>
                  <th class="p-2">ที่อยู่ไอพี</th>
                  <th class="p-2">รุ่น GPU</th>
                  <th class="p-2">กล้องที่เชื่อม</th>
                  <th class="p-2">สถานะ</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="n in reportData.nodes" :key="n.node_uuid">
                  <td class="p-2 font-mono">{{ n.node_code }}</td>
                  <td class="p-2 font-medium">{{ n.name }}</td>
                  <td class="p-2 text-gray-500">{{ n.site_name }}</td>
                  <td class="p-2 font-mono">{{ n.ip_address }}</td>
                  <td class="p-2">{{ n.gpu_name || '-' }}</td>
                  <td class="p-2 text-center">{{ n.camera_count }}</td>
                  <td class="p-2">
                    <span
                      class="px-2 py-0.5 rounded text-[10px]"
                      :class="
                        n.status === 'ONLINE'
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      "
                    >
                      {{ statusLabel(n.status) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB 2: Export History -->
    <div v-if="activeTab === 'exports'" class="space-y-4">
      <div class="bg-surface rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
          <h2 class="text-base font-semibold text-gray-900">ประวัติการส่งออกไฟล์รายงาน</h2>
          <button
            @click="fetchExports(1)"
            class="px-3 py-1.5 border border-gray-300 rounded text-xs text-gray-700 hover:bg-gray-50"
            :disabled="exportsLoading"
          >
            รีเฟรชประวัติ
          </button>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase">
              <tr>
                <th class="px-4 py-3">ประเภทรายงาน</th>
                <th class="px-4 py-3">ฟอร์แมต</th>
                <th class="px-4 py-3">สถานะ</th>
                <th class="px-4 py-3">ขนาดไฟล์</th>
                <th class="px-4 py-3">เวลาสร้าง</th>
                <th class="px-4 py-3">หมดอายุ</th>
                <th class="px-4 py-3 text-right">ดาวน์โหลด</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-surface">
              <tr v-for="item in exportsList" :key="item.uuid" class="hover:bg-gray-50">
                <td class="px-4 py-3 font-medium text-gray-900">{{ item.report_type }}</td>
                <td class="px-4 py-3">
                  <span
                    class="px-2 py-0.5 rounded text-xs font-mono font-bold bg-gray-100 text-gray-800"
                  >
                    {{ item.format }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold"
                    :class="{
                      'bg-green-100 text-green-800': item.status === 'COMPLETED',
                      'bg-yellow-100 text-yellow-800':
                        item.status === 'PENDING' || item.status === 'PROCESSING',
                      'bg-red-100 text-red-800': item.status === 'FAILED',
                      'bg-gray-100 text-gray-600': item.status === 'EXPIRED',
                    }"
                  >
                    {{ statusLabel(item.status) }}
                  </span>
                </td>
                <td class="px-4 py-3 text-xs text-gray-500 font-mono">
                  {{ item.file_size ? `${(item.file_size / 1024).toFixed(1)} KB` : '-' }}
                </td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ formatTime(item.created_at) }}</td>
                <td class="px-4 py-3 text-xs text-gray-500">{{ formatTime(item.expires_at) }}</td>
                <td class="px-4 py-3 text-right text-xs">
                  <button
                    v-if="item.status === 'COMPLETED'"
                    @click="downloadExport(item)"
                    class="inline-flex items-center px-2.5 py-1.5 bg-blue-600 text-white rounded font-medium hover:bg-blue-700"
                  >
                    ดาวน์โหลด
                  </button>
                  <span v-else-if="item.status === 'EXPIRED'" class="text-gray-400">หมดอายุ</span>
                  <span v-else-if="item.status === 'FAILED'" class="text-red-500 text-xs"
                    >ล้มเหลว</span
                  >
                  <span v-else class="text-amber-600 text-xs">กำลังสร้าง...</span>
                </td>
              </tr>
              <tr v-if="exportsList.length === 0 && !exportsLoading">
                <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                  ยังไม่มีประวัติการส่งออกไฟล์
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
