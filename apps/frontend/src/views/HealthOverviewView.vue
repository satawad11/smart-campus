<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { apiErrorText, statusLabel } from '@/constants/labels'

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
  if (status === 'ONLINE') return 'bg-green-100 text-green-800'
  if (status === 'DEGRADED') return 'bg-yellow-100 text-yellow-800'
  if (status === 'OFFLINE') return 'bg-red-100 text-red-800'
  return 'bg-gray-100 text-gray-500'
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
    errorMessage.value = apiErrorText(err, 'โหลดภาพรวมสุขภาพอุปกรณ์ไม่สำเร็จ')
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
      <h1 class="text-2xl font-bold text-gray-900">ภาพรวมสุขภาพอุปกรณ์</h1>
      <p class="text-sm text-gray-500">
        สถานะกล้องและหน่วยประมวลผลทั้งหมดในหน้าเดียว คลิกที่อุปกรณ์เพื่อดูประวัติทั้งหมด
      </p>
    </div>

    <div class="flex gap-2 border-b border-gray-200 text-sm">
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
        กล้อง
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
        หน่วยประมวลผล
      </button>
      <button @click="fetchAll" class="ml-auto px-3 py-2 text-xs text-gray-500 hover:text-gray-700">
        รีเฟรช
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดภาพรวม...</div>

    <template v-else>
      <div
        v-if="activeSection === 'cameras'"
        class="bg-surface rounded-xl border border-gray-200 overflow-hidden"
      >
        <div v-if="cameras.length === 0" class="text-center py-12 text-gray-400">ไม่พบกล้อง</div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">กล้อง</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">พื้นที่ / โซน</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สุขภาพ</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ติดต่อล่าสุด</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr
              v-for="camera in cameras"
              :key="camera.uuid"
              class="hover:bg-gray-50 cursor-pointer"
              @click="router.push({ name: 'cameras.show', params: { uuid: camera.uuid } })"
            >
              <td class="px-6 py-4">
                <div class="font-semibold text-gray-900">{{ camera.name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ camera.camera_code }}</div>
              </td>
              <td class="px-6 py-4 text-xs text-gray-600">
                {{ camera.site?.name || '-' }} / {{ camera.zone?.name || '-' }}
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(camera.health_status)"
                >
                  {{ camera.health_status ? statusLabel(camera.health_status) : 'ไม่มีข้อมูล' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{
                  camera.last_seen_at
                    ? new Date(camera.last_seen_at).toLocaleString('th-TH')
                    : 'ยังไม่เคย'
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div
        v-if="activeSection === 'nodes'"
        class="bg-surface rounded-xl border border-gray-200 overflow-hidden"
      >
        <div v-if="nodes.length === 0" class="text-center py-12 text-gray-400">
          ไม่พบหน่วยประมวลผล
        </div>
        <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">หน่วยประมวลผล</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">พื้นที่</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สุขภาพ</th>
              <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ติดต่อล่าสุด</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200">
            <tr
              v-for="node in nodes"
              :key="node.uuid"
              class="hover:bg-gray-50 cursor-pointer"
              @click="router.push({ name: 'ai-nodes.show', params: { uuid: node.uuid } })"
            >
              <td class="px-6 py-4">
                <div class="font-semibold text-gray-900">{{ node.name }}</div>
                <div class="text-xs text-gray-500 font-mono">{{ node.node_code }}</div>
              </td>
              <td class="px-6 py-4 text-xs text-gray-600">
                {{ node.site?.name || 'ยังไม่กำหนด' }}
              </td>
              <td class="px-6 py-4">
                <span
                  class="px-2.5 py-1 rounded-full text-xs font-semibold"
                  :class="healthBadgeClass(node.health_status)"
                >
                  {{ node.health_status ? statusLabel(node.health_status) : 'ไม่มีข้อมูล' }}
                </span>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{
                  node.last_seen_at
                    ? new Date(node.last_seen_at).toLocaleString('th-TH')
                    : 'ยังไม่เคย'
                }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
