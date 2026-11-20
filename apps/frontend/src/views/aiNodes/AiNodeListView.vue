<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import aiNodesService from '@/services/aiNodes'
import { apiErrorText, statusLabel } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const nodes = ref([])
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

const canView = computed(() => authStore.can('ai_node.view'))
const canManage = computed(() => authStore.can('ai_node.manage'))

async function fetchNodes() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''

  try {
    const res = await aiNodesService.listNodes({
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })

    nodes.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.last_page = res.meta.pagination.last_page
      pagination.total = res.meta.pagination.total
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายการหน่วยประมวลผลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function toggleStatus(node) {
  const nextStatus = node.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'
  try {
    await aiNodesService.updateStatus(node.uuid, nextStatus)
    await fetchNodes()
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'เปลี่ยนสถานะหน่วยประมวลผลไม่สำเร็จ')
  }
}

function statusBadgeClass(status) {
  return status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
}

function goToPage(page) {
  if (page < 1 || page > pagination.last_page) return
  filters.page = page
  fetchNodes()
}

onMounted(() => {
  fetchNodes()
})
</script>

<template>
  <div class="ai-node-list-view max-w-7xl mx-auto p-6 space-y-6">
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900">หน่วยประมวลผลปัญญาประดิษฐ์</h1>
        <p class="text-sm text-gray-500">
          จัดการหน่วยประมวลผลภาพจากกล้องและกุญแจเชื่อมต่อของแต่ละหน่วย
        </p>
      </div>
      <div class="flex space-x-3">
        <button
          v-if="canManage"
          @click="router.push({ name: 'ai-nodes.create' })"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
        >
          + เพิ่มหน่วยประมวลผล
        </button>
      </div>
    </div>

    <div class="bg-surface p-4 rounded-xl border border-gray-200 flex flex-wrap gap-4 items-center">
      <input
        v-model="filters.keyword"
        @keyup.enter="fetchNodes"
        type="text"
        placeholder="ค้นหารหัสหรือชื่อหน่วยประมวลผล..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm"
      />

      <select
        v-model="filters.status"
        @change="fetchNodes"
        class="px-3 py-2 border rounded-lg text-sm"
      >
        <option value="">ทุกสถานะ</option>
        <option value="ACTIVE">ใช้งาน</option>
        <option value="INACTIVE">ปิดใช้งาน</option>
      </select>

      <button
        @click="fetchNodes"
        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
      >
        ค้นหา
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div class="bg-surface rounded-xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="text-center py-12 text-gray-500">
        กำลังโหลดรายการหน่วยประมวลผล...
      </div>

      <div v-else-if="nodes.length === 0" class="text-center py-12 text-gray-400">
        ไม่พบหน่วยประมวลผลตามเงื่อนไข
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">หน่วยประมวลผล</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">พื้นที่</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ฮาร์ดแวร์</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สถานะ</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ติดต่อล่าสุด</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase">การดำเนินการ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
          <tr v-for="node in nodes" :key="node.uuid" class="hover:bg-gray-50">
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900">{{ node.name }}</div>
              <div class="text-xs text-gray-500 font-mono">{{ node.node_code }}</div>
            </td>
            <td class="px-6 py-4 text-xs text-gray-600">
              {{ node.site?.name || 'ยังไม่กำหนด' }}
            </td>
            <td class="px-6 py-4 text-xs text-gray-600">
              {{ node.hardware_type || '-' }}
              <span v-if="node.gpu_name" class="text-gray-400">/ {{ node.gpu_name }}</span>
            </td>
            <td class="px-6 py-4">
              <span
                class="px-2.5 py-1 rounded-full text-xs font-semibold"
                :class="statusBadgeClass(node.status)"
              >
                {{ statusLabel(node.status) }}
              </span>
            </td>
            <td class="px-6 py-4 text-xs text-gray-500">
              {{
                node.last_seen_at
                  ? new Date(node.last_seen_at).toLocaleString('th-TH')
                  : 'ยังไม่เคย'
              }}
            </td>
            <td class="px-6 py-4 text-right space-x-2">
              <button
                @click="router.push({ name: 'ai-nodes.show', params: { uuid: node.uuid } })"
                class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
              >
                ดูรายละเอียด
              </button>
              <button
                v-if="canManage"
                @click="
                  router.push({
                    name: 'ai-nodes.show',
                    params: { uuid: node.uuid },
                    query: { edit: '1' },
                  })
                "
                class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-xs"
              >
                แก้ไข
              </button>
              <button
                v-if="canManage"
                @click="toggleStatus(node)"
                class="px-2.5 py-1 rounded text-xs"
                :class="
                  node.status === 'ACTIVE'
                    ? 'bg-red-50 hover:bg-red-100 text-red-700'
                    : 'bg-green-50 hover:bg-green-100 text-green-700'
                "
              >
                {{ node.status === 'ACTIVE' ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>

      <div
        v-if="pagination.last_page > 1"
        class="flex justify-between items-center px-6 py-3 border-t border-gray-200 text-sm"
      >
        <span class="text-gray-500">ทั้งหมด {{ pagination.total }} หน่วย</span>
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
  </div>
</template>
