<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'

const router = useRouter()
const authStore = useAuthStore()

const activeSection = ref('cameras')
const cameras = ref([])
const nodes = ref([])
const loading = ref(false)
const errorMessage = ref('')

const canViewCameras = computed(() => authStore.can('camera.view'))
const canViewNodes = computed(() => authStore.can('ai_node.view'))

function healthBadgeClass(status) {
  if (status === 'ONLINE')
    return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
  if (status === 'DEGRADED')
    return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
  if (status === 'OFFLINE') return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
  return 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'
}

async function latestHealthStatus(fetchFn, uuid) {
  try {
    const res = await fetchFn(uuid, { per_page: 1 })
    return res.data?.[0]?.status || null
  } catch {
    return null
  }
}

async function fetchCameraHealth() {
  const res = await camerasService.listCameras({ per_page: 50 })
  const list = res.data || []
  const statuses = await Promise.all(
    list.map((c) => latestHealthStatus(camerasService.getHealth, c.uuid)),
  )
  cameras.value = list.map((c, i) => ({ ...c, health_status: statuses[i] }))
}

async function fetchNodeHealth() {
  const res = await aiNodesService.listNodes({ per_page: 50 })
  const list = res.data || []
  const statuses = await Promise.all(
    list.map((n) => latestHealthStatus(aiNodesService.getHealth, n.uuid)),
  )
  nodes.value = list.map((n, i) => ({ ...n, health_status: statuses[i] }))
}

async function fetchAll() {
  loading.value = true
  errorMessage.value = ''
  try {
    const tasks = []
    if (canViewCameras.value) tasks.push(fetchCameraHealth())
    if (canViewNodes.value) tasks.push(fetchNodeHealth())
    await Promise.all(tasks)
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load health overview.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (!canViewCameras.value) activeSection.value = 'nodes'
  fetchAll()
})
</script>

<template>
  <div class="health-overview-view max-w-7xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Health Overview</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        Fleet-wide camera and AI node health at a glance (CAM-019). Click through to a device for
        full history.
      </p>
    </div>

    <div class="flex gap-2 border-b border-gray-200 dark:border-gray-700 text-sm">
      <button
        v-if="canViewCameras"
        @click="activeSection = 'cameras'"
        class="px-4 py-2 font-medium"
        :class="
          activeSection === 'cameras'
            ? 'border-b-2 border-blue-600 text-blue-600'
            : 'text-gray-500 hover:text-gray-700'
        "
      >
        Cameras
      </button>
      <button
        v-if="canViewNodes"
        @click="activeSection = 'nodes'"
        class="px-4 py-2 font-medium"
        :class="
          activeSection === 'nodes'
            ? 'border-b-2 border-blue-600 text-blue-600'
            : 'text-gray-500 hover:text-gray-700'
        "
      >
        AI Nodes
      </button>
      <button @click="fetchAll" class="ml-auto px-3 py-2 text-xs text-gray-500 hover:text-gray-700">
        Refresh
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">Loading health overview...</div>

    <template v-else>
      <div
        v-if="activeSection === 'cameras'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
      >
        <div v-if="cameras.length === 0" class="text-center py-12 text-gray-400">
          No cameras found.
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Camera</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Site / Zone</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Health</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Last Seen</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr
              v-for="camera in cameras"
              :key="camera.uuid"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer"
              @click="router.push({ name: 'cameras.show', params: { uuid: camera.uuid } })"
            >
              <td class="px-6 py-4">
                <div class="font-semibold text-gray-900 dark:text-white">{{ camera.name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ camera.camera_code }}</div>
              </td>
              <td class="px-6 py-4 text-xs text-gray-600 dark:text-gray-300">
                {{ camera.site?.name || '-' }} / {{ camera.zone?.name || '-' }}
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(camera.health_status)"
                >
                  {{ camera.health_status || 'No Data' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{ camera.last_seen_at ? new Date(camera.last_seen_at).toLocaleString() : 'Never' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="activeSection === 'nodes'"
        class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
      >
        <div v-if="nodes.length === 0" class="text-center py-12 text-gray-400">
          No AI nodes found.
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
          <thead class="bg-gray-50 dark:bg-gray-700/50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Node</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Site</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Health</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Last Seen</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            <tr
              v-for="node in nodes"
              :key="node.uuid"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer"
              @click="router.push({ name: 'ai-nodes.show', params: { uuid: node.uuid } })"
            >
              <td class="px-6 py-4">
                <div class="font-semibold text-gray-900 dark:text-white">{{ node.name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ node.node_code }}</div>
              </td>
              <td class="px-6 py-4 text-xs text-gray-600 dark:text-gray-300">
                {{ node.site?.name || 'Unassigned' }}
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(node.health_status)"
                >
                  {{ node.health_status || 'No Data' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{ node.last_seen_at ? new Date(node.last_seen_at).toLocaleString() : 'Never' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
