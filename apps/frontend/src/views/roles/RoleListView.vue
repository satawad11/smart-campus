<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { rolesService } from '@/services/roles'
import StatusBadge from '@/components/common/StatusBadge.vue'
import RoleTypeBadge from '@/components/roles/RoleTypeBadge.vue'
import { apiErrorText } from '@/constants/labels'

const authStore = useAuthStore()

const roles = ref([])
const loading = ref(false)
const errorMessage = ref('')

const filters = reactive({
  keyword: '',
  status: '',
  system_role: '',
  sort: 'code',
  order: 'asc',
  page: 1,
  per_page: 20,
})

const pagination = reactive({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

let debounceTimer = null

async function fetchRoles() {
  loading.value = true
  errorMessage.value = ''

  try {
    const params = {
      page: filters.page,
      per_page: filters.per_page,
      sort: filters.sort,
      order: filters.order,
    }
    if (filters.keyword.trim()) params.keyword = filters.keyword.trim()
    if (filters.status) params.status = filters.status
    if (filters.system_role !== '') params.system_role = filters.system_role === 'true'

    const res = await rolesService.list(params)
    roles.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.per_page = res.meta.pagination.per_page
      pagination.total = res.meta.pagination.total
      pagination.last_page = res.meta.pagination.last_page
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ไม่สามารถโหลดข้อมูลบทบาทได้')
  } finally {
    loading.value = false
  }
}

function handleSearchInput() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    filters.page = 1
    fetchRoles()
  }, 300)
}

function handleFilterChange() {
  filters.page = 1
  fetchRoles()
}

function handleSort(field) {
  if (filters.sort === field) {
    filters.order = filters.order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.sort = field
    filters.order = 'asc'
  }
  filters.page = 1
  fetchRoles()
}

function changePage(page) {
  if (page < 1 || page > pagination.last_page || page === filters.page) return
  filters.page = page
  fetchRoles()
}

function formatDate(isoStr) {
  if (!isoStr) return '-'
  try {
    const d = new Date(isoStr)
    return d.toLocaleString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return isoStr
  }
}

onMounted(() => {
  fetchRoles()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการบทบาทและสิทธิ์</h1>
        <p class="mt-1 text-sm text-gray-500">
          กำหนดและจัดการบทบาทผู้ใช้งาน พร้อมตารางการอนุญาตสิทธิ์เข้าถึงระบบ Smart Campus
        </p>
      </div>
      <div v-if="authStore.can('role.manage')" class="mt-4 sm:mt-0">
        <router-link
          to="/roles/new"
          class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition"
        >
          <svg class="-ml-0.5 mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 4v16m8-8H4"
            />
          </svg>
          เพิ่มบทบาท
        </router-link>
      </div>
    </div>

    <!-- Error Banner -->
    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-4 text-sm text-red-800 border border-red-200 flex items-center justify-between"
      role="alert"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-5 w-5 text-red-600"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <span>{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-red-600 hover:text-red-800">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M6 18L18 6M6 6l12 12"
          />
        </svg>
      </button>
    </div>

    <!-- Filters & Search Toolbar -->
    <div
      class="rounded-lg bg-surface p-4 shadow space-y-3 sm:space-y-0 sm:flex sm:items-center sm:gap-4"
    >
      <!-- Search Keyword -->
      <div class="flex-1 relative">
        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
          <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
        </div>
        <input
          id="role-search"
          v-model="filters.keyword"
          @input="handleSearchInput"
          type="text"
          placeholder="ค้นหาด้วยรหัสบทบาท หรือชื่อบทบาท..."
          class="block w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </div>

      <!-- Type Filter -->
      <div class="w-full sm:w-44">
        <select
          id="system-role-filter"
          v-model="filters.system_role"
          @change="handleFilterChange"
          class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="">ทุกประเภท</option>
          <option value="true">บทบาทระบบ</option>
          <option value="false">บทบาทกำหนดเอง</option>
        </select>
      </div>

      <!-- Status Filter -->
      <div class="w-full sm:w-40">
        <select
          id="status-filter"
          v-model="filters.status"
          @change="handleFilterChange"
          class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="">ทุกสถานะ</option>
          <option value="ACTIVE">ใช้งานปกติ (Active)</option>
          <option value="DISABLED">ปิดใช้งาน (Disabled)</option>
        </select>
      </div>

      <!-- Per Page Select -->
      <div class="w-full sm:w-32">
        <select
          id="per-page-select"
          v-model="filters.per_page"
          @change="handleFilterChange"
          class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option :value="10">10 แถว</option>
          <option :value="20">20 แถว</option>
          <option :value="50">50 แถว</option>
        </select>
      </div>
    </div>

    <!-- Roles Table -->
    <div class="overflow-hidden rounded-lg bg-surface shadow">
      <!-- Loading Skeleton -->
      <div v-if="loading" class="p-8 text-center text-gray-500">
        <svg class="mx-auto h-8 w-8 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          ></circle>
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          ></path>
        </svg>
        <p class="mt-2 text-sm">กำลังโหลดข้อมูลบทบาท...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="roles.length === 0" class="p-12 text-center text-gray-500">
        <svg
          class="mx-auto h-12 w-12 text-gray-400"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
        <h3 class="mt-2 text-sm font-semibold text-gray-900">ไม่พบข้อมูลบทบาท</h3>
        <p class="mt-1 text-sm text-gray-500">ลองปรับเปลี่ยนคำค้นหาหรือตัวกรอง</p>
      </div>

      <!-- Table Content -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
          <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-700">
            <tr>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('code')"
              >
                <div class="flex items-center gap-1">
                  <span>รหัสบทบาท</span>
                  <span v-if="filters.sort === 'code'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('name')"
              >
                <div class="flex items-center gap-1">
                  <span>ชื่อบทบาท</span>
                  <span v-if="filters.sort === 'name'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
              <th scope="col" class="px-4 py-3">ประเภท</th>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('status')"
              >
                <div class="flex items-center gap-1">
                  <span>สถานะ</span>
                  <span v-if="filters.sort === 'status'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
              <th scope="col" class="px-4 py-3 text-center">สิทธิ์</th>
              <th scope="col" class="px-4 py-3 text-center">ผู้ใช้งาน</th>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('created_at')"
              >
                <div class="flex items-center gap-1">
                  <span>วันที่สร้าง</span>
                  <span v-if="filters.sort === 'created_at'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
              <th scope="col" class="px-4 py-3 text-right">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-surface">
            <tr v-for="r in roles" :key="r.uuid" class="hover:bg-gray-50 transition">
              <!-- Code -->
              <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-900">
                <router-link
                  :to="`/roles/${r.uuid}`"
                  class="text-blue-600 hover:text-blue-800 hover:underline"
                >
                  {{ r.code }}
                </router-link>
              </td>

              <!-- Name & Description -->
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900">{{ r.name }}</div>
                <div v-if="r.description" class="text-xs text-gray-500 truncate max-w-xs">
                  {{ r.description }}
                </div>
              </td>

              <!-- Role Type -->
              <td class="px-4 py-3">
                <RoleTypeBadge :system-role="r.system_role" />
              </td>

              <!-- Status -->
              <td class="px-4 py-3">
                <StatusBadge :status="r.status" />
              </td>

              <!-- Permissions Count -->
              <td class="px-4 py-3 text-center">
                <span
                  class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700"
                >
                  {{ r.permissions_count ?? '-' }}
                </span>
              </td>

              <!-- Users Count -->
              <td class="px-4 py-3 text-center text-xs text-gray-600">
                {{ r.users_count ?? '-' }}
              </td>

              <!-- Created At -->
              <td class="px-4 py-3 text-xs text-gray-500">
                {{ formatDate(r.created_at) }}
              </td>

              <!-- Actions -->
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <!-- View Details -->
                  <router-link
                    :to="`/roles/${r.uuid}`"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                    title="ดูรายละเอียดบทบาท"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                      />
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                      />
                    </svg>
                  </router-link>

                  <!-- Manage Permissions Matrix -->
                  <router-link
                    :to="`/roles/${r.uuid}/permissions`"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-indigo-600"
                    :title="authStore.can('role.manage') ? 'จัดการตารางสิทธิ์' : 'ดูตารางสิทธิ์'"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                      />
                    </svg>
                  </router-link>

                  <!-- Edit Role (if role.manage) -->
                  <router-link
                    v-if="authStore.can('role.manage')"
                    :to="`/roles/${r.uuid}/edit`"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-blue-600"
                    title="แก้ไขข้อมูลบทบาท"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                      />
                    </svg>
                  </router-link>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <div
        v-if="roles.length > 0"
        class="flex items-center justify-between border-t border-gray-200 bg-surface px-4 py-3 sm:px-6"
      >
        <div class="text-xs text-gray-700">
          แสดงหน้าที่ <span class="font-medium">{{ pagination.current_page }}</span> จาก
          <span class="font-medium">{{ pagination.last_page }}</span> (ทั้งหมด
          <span class="font-medium">{{ pagination.total }}</span> บทบาท)
        </div>

        <div class="flex items-center space-x-2">
          <button
            type="button"
            :disabled="pagination.current_page <= 1"
            @click="changePage(pagination.current_page - 1)"
            class="rounded border border-gray-300 bg-surface px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            ก่อนหน้า
          </button>
          <button
            type="button"
            :disabled="pagination.current_page >= pagination.last_page"
            @click="changePage(pagination.current_page + 1)"
            class="rounded border border-gray-300 bg-surface px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
