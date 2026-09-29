<template>
  <div class="system-health-view p-6 max-w-7xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">System Health &amp; Operations</h1>
        <p class="text-sm text-gray-500">Live infrastructure status, service availability, and alert lifecycle management.</p>
      </div>
      <button
        @click="loadHealth"
        class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
      >
        Refresh
      </button>
    </div>

    <!-- Health Overview Cards -->
    <div v-if="health" class="grid grid-cols-1 md:grid-cols-4 gap-6">
      <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">Overall Status</span>
        <div class="mt-2 flex items-center gap-2">
          <span
            :class="health.status === 'HEALTHY' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
            class="px-2.5 py-0.5 rounded-full text-sm font-bold"
          >
            {{ health.status }}
          </span>
        </div>
      </div>

      <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">Database &amp; Redis</span>
        <div class="mt-2 text-sm text-gray-900 space-y-1">
          <div>Postgres: <span class="font-semibold text-green-600">{{ health.core_services?.database?.status }}</span></div>
          <div>Redis: <span class="font-semibold text-green-600">{{ health.core_services?.redis?.status }}</span></div>
        </div>
      </div>

      <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">Edge Cameras</span>
        <div class="mt-2 text-2xl font-bold text-gray-900">
          {{ health.edge_devices?.cameras?.active }} / {{ health.edge_devices?.cameras?.total }}
        </div>
        <div class="text-xs text-gray-500 mt-1">Active / Registered</div>
      </div>

      <div class="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
        <span class="text-sm font-medium text-gray-500">Open Alerts</span>
        <div class="mt-2 text-2xl font-bold text-red-600">
          {{ health.alerts?.open_total || 0 }}
        </div>
        <div class="text-xs text-gray-500 mt-1">{{ health.alerts?.open_critical || 0 }} Critical</div>
      </div>
    </div>

    <!-- Active Alerts Triage -->
    <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Active Alerts</h2>
      <div v-if="alerts.length === 0" class="text-sm text-gray-500 py-4 text-center">
        No open alerts. All systems operational.
      </div>
      <div v-else class="divide-y divide-gray-200">
        <div v-for="alert in alerts" :key="alert.id" class="py-4 flex items-center justify-between">
          <div>
            <div class="flex items-center gap-2">
              <span
                :class="alert.severity === 'CRITICAL' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'"
                class="px-2 py-0.5 rounded text-xs font-semibold"
              >
                {{ alert.severity }}
              </span>
              <span class="font-medium text-gray-900">{{ alert.title }}</span>
              <span class="text-xs text-gray-400">({{ alert.status }})</span>
            </div>
            <p class="text-sm text-gray-600 mt-1">{{ alert.message }}</p>
          </div>
          <div class="flex items-center gap-2">
            <button
              v-if="alert.status === 'OPEN'"
              @click="acknowledgeAlert(alert.id)"
              class="px-3 py-1 bg-yellow-50 text-yellow-700 hover:bg-yellow-100 rounded text-xs font-medium border border-yellow-200"
            >
              Acknowledge
            </button>
            <button
              v-if="alert.status !== 'RESOLVED'"
              @click="resolveAlert(alert.id)"
              class="px-3 py-1 bg-green-50 text-green-700 hover:bg-green-100 rounded text-xs font-medium border border-green-200"
            >
              Resolve
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
  await api.post(`/system/alerts/${id}/resolve`, { notes: 'Resolved by operator triage' })
  await loadAlerts()
  await loadHealth()
}

onMounted(() => {
  loadHealth()
  loadAlerts()
})
</script>
