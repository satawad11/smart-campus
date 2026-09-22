<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'

const router = useRouter()
const authStore = useAuthStore()

const cameras = ref([])
const loading = ref(false)
const errorMessage = ref('')

const filters = reactive({
  keyword: '',
  status: '',
  page: 1,
  per_page: 15,
})

const pagination = reactive({
  current_page: 1,
  per_page: 15,
  total: 0,
  last_page: 1,
})

const canView = computed(() => authStore.can('camera.view'))
const canCreate = computed(() => authStore.can('camera.create'))
const canEnable = computed(() => authStore.can('camera.enable'))

async function fetchCameras() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''

  try {
    const res = await camerasService.listCameras({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })

    cameras.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.last_page = res.meta.pagination.last_page
      pagination.total = res.meta.pagination.total
    }
  } catch (err) {
    errorMessage.value = err.message || 'Failed to load cameras.'
  } finally {
    loading.value = false
  }
}

async function toggleStatus(camera) {
  const nextStatus = camera.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  try {
    await camerasService.updateStatus(camera.uuid, nextStatus)
    await fetchCameras()
  } catch (err) {
    errorMessage.value = err.message || 'Failed to change camera status.'
  }
}

function statusBadgeClass(status) {
  return status === 'ACTIVE'
    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchCameras()
}

onMounted(() => {
  fetchCameras()
})
</script>

<template>
  <div class="camera-list-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Cameras</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Manage camera devices, streaming, and AI settings (CAM-017).
        </p>
      </div>
      <div class="flex space-x-3">
        <button
          v-if="canCreate"
          @click="router.push({ name: 'cameras.create' })"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
        >
          + Add Camera
        </button>
      </div>
    </div>

    <div
      class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-wrap gap-4 items-center"
    >
      <input
        v-model="filters.keyword"
        @keyup.enter="fetchCameras"
        type="text"
        placeholder="Search camera code or name..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      />

      <select
        v-model="filters.status"
        @change="fetchCameras"
        class="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      >
        <option value="">All Statuses</option>
        <option value="ACTIVE">ACTIVE</option>
        <option value="INACTIVE">INACTIVE</option>
      </select>

      <button
        @click="fetchCameras"
        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
      >
        Search
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div
      class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
    >
      <div v-if="loading" class="text-center py-12 text-gray-500">Loading cameras...</div>

      <div v-else-if="cameras.length === 0" class="text-center py-12 text-gray-400">
        No cameras found matching criteria.
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
        <thead class="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Camera</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Site / Zone</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">AI Node</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Status</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Last Seen</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr
            v-for="camera in cameras"
            :key="camera.uuid"
            class="hover:bg-gray-50 dark:hover:bg-gray-700/30"
          >
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900 dark:text-white">{{ camera.name }}</div>
              <div class="text-xs text-gray-500 font-mono">{{ camera.camera_code }}</div>
            </td>
            <td class="px-6 py-4 text-xs">
              <div class="text-gray-800 dark:text-gray-200">{{ camera.site?.name || '-' }}</div>
              <div class="text-gray-400">{{ camera.zone?.name || '-' }}</div>
            </td>
            <td class="px-6 py-4 text-xs text-gray-600 dark:text-gray-300">
              {{ camera.ai_node?.name || 'Unassigned' }}
            </td>
            <td class="px-6 py-4">
              <span
                class="px-2.5 py-1 rounded-full text-xs font-semibold"
                :class="statusBadgeClass(camera.status)"
              >
                {{ camera.status }}
              </span>
            </td>
            <td class="px-6 py-4 text-xs text-gray-500">
              {{ camera.last_seen_at ? new Date(camera.last_seen_at).toLocaleString() : 'Never' }}
            </td>
            <td class="px-6 py-4 text-right space-x-2">
              <button
                @click="router.push({ name: 'cameras.show', params: { uuid: camera.uuid } })"
                class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
              >
                Detail
              </button>
              <button
                v-if="canEnable"
                @click="toggleStatus(camera)"
                class="px-2.5 py-1 rounded text-xs"
                :class="
                  camera.status === 'ACTIVE'
                    ? 'bg-red-50 hover:bg-red-100 text-red-700'
                    : 'bg-green-50 hover:bg-green-100 text-green-700'
                "
              >
                {{ camera.status === 'ACTIVE' ? 'Disable' : 'Enable' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div
        v-if="pagination.last_page > 1"
        class="flex justify-between items-center px-6 py-3 border-t border-gray-200 dark:border-gray-700 text-sm"
      >
        <span class="text-gray-500">{{ pagination.total }} total cameras</span>
        <div class="space-x-2">
          <button
            @click="goToPage(pagination.current_page - 1)"
            :disabled="pagination.current_page <= 1"
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
          >
            Prev
          </button>
          <span>{{ pagination.current_page }} / {{ pagination.last_page }}</span>
          <button
            @click="goToPage(pagination.current_page + 1)"
            :disabled="pagination.current_page >= pagination.last_page"
            class="px-3 py-1 bg-gray-100 rounded disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
