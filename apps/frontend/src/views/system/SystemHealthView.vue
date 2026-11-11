<template>
  <div class="system-health-view p-6 max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">สุขภาพระบบและการแจ้งเตือน</h1>
        <p class="text-sm text-gray-500">
          สถานะโครงสร้างพื้นฐาน บริการต่าง ๆ และการจัดการการแจ้งเตือน
        </p>
      </div>
      <button
        @click="loadHealth"
        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-surface hover:bg-gray-50"
      >
        รีเฟรช
      </button>
    </div>

    <!-- Health Overview Cards -->
    <div v-if="health" class="grid grid-cols-1 md:grid-cols-4 gap-6">
      <div class="bg-surface p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">สถานะโดยรวม</span>
        <div class="mt-2 flex items-center gap-2">
          <span
            :class="
              health.status === 'HEALTHY'
                ? 'bg-green-100 text-green-800'
                : 'bg-red-100 text-red-800'
            "
            class="px-2.5 py-0.5 rounded-full text-sm font-bold"
          >
            {{ statusLabel(health.status) }}
          </span>
        </div>
      </div>

      <div class="bg-surface p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">ฐานข้อมูลและแคช</span>
        <div class="mt-2 text-sm text-gray-900 space-y-1">
          <div>
            ฐานข้อมูล:
            <span class="font-semibold text-green-600">{{
              statusLabel(health.core_services?.database?.status)
            }}</span>
          </div>
          <div>
            แคช (Redis):
            <span class="font-semibold text-green-600">{{
              statusLabel(health.core_services?.redis?.status)
            }}</span>
          </div>
        </div>
      </div>

      <div class="bg-surface p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">กล้อง</span>
        <div class="mt-2 text-2xl font-bold text-gray-900">
          {{ health.edge_devices?.cameras?.active }} / {{ health.edge_devices?.cameras?.total }}
        </div>
        <div class="text-xs text-gray-500 mt-1">ใช้งาน / ทั้งหมด</div>
      </div>

      <div class="bg-surface p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">การแจ้งเตือนที่ยังไม่ปิด</span>
        <div class="mt-2 text-2xl font-bold text-red-600">
          {{ health.alerts?.open_total || 0 }}
        </div>
        <div class="text-xs text-gray-500 mt-1">
          วิกฤต {{ health.alerts?.open_critical || 0 }} รายการ
        </div>
      </div>
    </div>

    <!-- Active Alerts Triage -->
    <div class="bg-surface shadow-sm rounded-lg border border-gray-200 p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">การแจ้งเตือนที่ยังไม่ปิด</h2>
      <div v-if="alerts.length === 0" class="text-sm text-gray-500 py-4 text-center">
        ไม่มีการแจ้งเตือนค้าง ระบบทำงานปกติ
      </div>
      <div v-else class="divide-y divide-gray-200">
        <div v-for="alert in alerts" :key="alert.id" class="py-4 flex items-center justify-between">
          <div>
            <div class="flex items-center gap-2">
              <span
                :class="
                  alert.severity === 'CRITICAL'
                    ? 'bg-red-100 text-red-800'
                    : 'bg-yellow-100 text-yellow-800'
                "
                class="px-2 py-0.5 rounded text-xs font-semibold"
              >
                {{ statusLabel(alert.severity) }}
              </span>
              <span class="font-medium text-gray-900">{{ alertTitle(alert) }}</span>
              <span class="text-xs text-gray-400">({{ statusLabel(alert.status) }})</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">{{ alert.message }}</p>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="alert.status === 'OPEN'"
              @click="acknowledgeAlert(alert.id)"
              class="px-3 py-1 bg-yellow-50 text-yellow-700 hover:bg-yellow-100 rounded text-xs font-medium border border-yellow-200"
            >
              รับทราบ
            </button>
            <button
              v-if="alert.status !== 'RESOLVED'"
              @click="resolveAlert(alert.id)"
              class="px-3 py-1 bg-green-50 text-green-700 hover:bg-green-100 rounded text-xs font-medium border border-green-200"
            >
              ปิดการแจ้งเตือน
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { alertTitle, statusLabel } from '@/constants/labels'

const health = ref(null)
const alerts = ref([])

const loadHealth = async () => {
  try {
    const res = await api.get('/system/health')
    health.value = res.data.data
  } catch (e) {
    console.error('Failed to load system health', e)
  }
}

const loadAlerts = async () => {
  try {
    const res = await api.get('/system/alerts')
    alerts.value = res.data.data || []
  } catch (e) {
    console.error('Failed to load alerts', e)
  }
}

const acknowledgeAlert = async (id) => {
  await api.post(`/system/alerts/${id}/acknowledge`)
  await loadAlerts()
  await loadHealth()
}

const resolveAlert = async (id) => {
  await api.post(`/system/alerts/${id}/resolve`, { notes: 'ปิดโดยผู้ดูแลระบบ' })
  await loadAlerts()
  await loadHealth()
}

onMounted(() => {
  loadHealth()
  loadAlerts()
})
</script>
