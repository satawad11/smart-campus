<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { usersService } from '@/services/users'
import StatusBadge from '@/components/common/StatusBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import ResetPasswordModal from '@/components/users/ResetPasswordModal.vue'
import { apiErrorText } from '@/constants/labels'

const authStore = useAuthStore()

// State
const users = ref([])
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const filters = reactive({
  keyword: '',
  status: '',
  role: '',
  sort: 'created_at',
  order: 'desc',
  page: 1,
  per_page: 20,
})

const pagination = reactive({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

// Modals State
const confirmModal = reactive({
  show: false,
  title: '',
  message: '',
  warning: '',
  confirmLabel: 'ยืนยัน',
  isDanger: false,
  loading: false,
  user: null,
  targetStatus: null,
})

const resetModal = reactive({
  show: false,
  user: null,
})

const errorTranslations = {
  USER_SELF_DISABLE_FORBIDDEN: 'ไม่สามารถปิดใช้งาน ล็อก หรือระงับบัญชีของตนเองได้',
  LAST_SUPER_ADMIN_REQUIRED:
    'ไม่สามารถปิดใช้งานหรือล็อกผู้ดูแลระบบระดับสูงสุด (SUPER_ADMIN) บัญชีสุดท้ายได้',
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการดำเนินการนี้',
  USER_NOT_FOUND: 'ไม่พบข้อมูลผู้ใช้งาน',
}

let debounceTimer = null

async function fetchUsers() {
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
    if (filters.role) params.role = filters.role

    const res = await usersService.list(params)
    users.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.per_page = res.meta.pagination.per_page
      pagination.total = res.meta.pagination.total
      pagination.last_page = res.meta.pagination.last_page
    }
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'ไม่สามารถโหลดข้อมูลผู้ใช้งานได้')
  } finally {
    loading.value = false
  }
}

function handleSearchInput() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    filters.page = 1
    fetchUsers()
  }, 300)
}

function handleFilterChange() {
  filters.page = 1
  fetchUsers()
}

function handleSort(field) {
  if (filters.sort === field) {
    filters.order = filters.order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.sort = field
    filters.order = 'asc'
  }
  filters.page = 1
  fetchUsers()
}

function changePage(page) {
  if (page < 1 || page > pagination.last_page || page === filters.page) return
  filters.page = page
  fetchUsers()
}

// Status Action Flow
function openStatusModal(user, targetStatus) {
  const isDestructive = targetStatus !== 'ACTIVE'
  const isSelf = authStore.user?.uuid === user.uuid

  if (isDestructive && isSelf) {
    errorMessage.value = 'คุณไม่สามารถปิดใช้งานหรือล็อกบัญชีของตนเองได้'
    return
  }

  const statusLabels = {
    ACTIVE: 'เปิดใช้งาน (ACTIVE)',
    LOCKED: 'ล็อกบัญชี (LOCKED)',
    DISABLED: 'ปิดใช้งาน (DISABLED)',
    EXPIRED: 'กำหนดสถานะหมดอายุ (EXPIRED)',
  }

  confirmModal.user = user
  confirmModal.targetStatus = targetStatus
  confirmModal.title = `เปลี่ยนสถานะบัญชี: ${user.username}`
  confirmModal.message = `คุณต้องการเปลี่ยนสถานะบัญชีผู้ใช้นี้เป็น "${statusLabels[targetStatus]}" ใช่หรือไม่?`
  confirmModal.warning = isDestructive
    ? 'คำเตือน: การเปลี่ยนสถานะเป็น ไม่พร้อมใช้งาน จะมีผลให้เซสชันการเข้าสู่ระบบของผู้ใช้นี้ถูกยกเลิกทันที'
    : ''
  confirmModal.confirmLabel = isDestructive ? 'ยืนยันเปลี่ยนสถานะ' : 'เปิดใช้งานบัญชี'
  confirmModal.isDanger = isDestructive
  confirmModal.loading = false
  confirmModal.show = true
}

async function executeStatusChange() {
  if (!confirmModal.user || !confirmModal.targetStatus) return

  confirmModal.loading = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const updated = await usersService.changeStatus(
      confirmModal.user.uuid,
      confirmModal.targetStatus,
    )
    confirmModal.show = false
    successMessage.value = `อัปเดตสถานะผู้ใช้ ${updated.username} สำเร็จ`

    // Refresh list data
    await fetchUsers()
  } catch (err) {
    confirmModal.show = false
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะผู้ใช้')
  } finally {
    confirmModal.loading = false
  }
}

// Password Reset Flow
function openResetPasswordModal(user) {
  resetModal.user = user
  resetModal.show = true
}

function handleResetSuccess(data) {
  successMessage.value = data.message || 'รีเซ็ตรหัสผ่านสำเร็จ'
  fetchUsers()
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
  fetchUsers()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการผู้ใช้งาน</h1>
        <p class="mt-1 text-sm text-gray-500">
          รายการบัญชีผู้ใช้และการกำหนดสถานะในระบบ Smart Campus
        </p>
      </div>
      <div class="mt-4 sm:mt-0" v-if="authStore.can('user.create')">
        <router-link
          to="/users/new"
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
          เพิ่มผู้ใช้งาน
        </router-link>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <div
      v-if="successMessage"
      class="rounded-md bg-green-50 p-4 text-sm text-green-800 border border-green-200 flex items-center justify-between"
      role="status"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-5 w-5 text-green-600"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"
          />
        </svg>
        <span>{{ successMessage }}</span>
      </div>
      <button @click="successMessage = ''" class="text-green-600 hover:text-green-800">
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
      class="rounded-lg bg-white p-4 shadow space-y-3 sm:space-y-0 sm:flex sm:items-center sm:gap-4"
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
          id="user-search"
          v-model="filters.keyword"
          @input="handleSearchInput"
          type="text"
          placeholder="ค้นหาด้วยชื่อผู้ใช้ หรืออีเมล..."
          class="block w-full rounded-md border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        />
      </div>

      <!-- Status Filter -->
      <div class="w-full sm:w-48">
        <select
          id="status-filter"
          v-model="filters.status"
          @change="handleFilterChange"
          class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-8 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
        >
          <option value="">ทุกสถานะ</option>
          <option value="ACTIVE">ใช้งานปกติ (Active)</option>
          <option value="LOCKED">ถูกล็อก (Locked)</option>
          <option value="DISABLED">ปิดใช้งาน (Disabled)</option>
          <option value="EXPIRED">หมดอายุ (Expired)</option>
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

    <!-- Users Table (Desktop & Tablet) -->
    <div class="overflow-hidden rounded-lg bg-white shadow">
      <!-- Loading Skeleton / Spinner -->
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
        <p class="mt-2 text-sm">กำลังโหลดข้อมูลผู้ใช้งาน...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="users.length === 0" class="p-12 text-center text-gray-500">
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
            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
          />
        </svg>
        <h3 class="mt-2 text-sm font-semibold text-gray-900">ไม่พบข้อมูลผู้ใช้งาน</h3>
        <p class="mt-1 text-sm text-gray-500">ลองปรับเปลี่ยนคำค้นหาหรือตัวกรองสถานะ</p>
      </div>

      <!-- Table View -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
          <thead class="bg-gray-50 text-xs font-semibold uppercase text-gray-700">
            <tr>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('username')"
              >
                <div class="flex items-center gap-1">
                  <span>ชื่อผู้ใช้</span>
                  <span v-if="filters.sort === 'username'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('email')"
              >
                <div class="flex items-center gap-1">
                  <span>อีเมล</span>
                  <span v-if="filters.sort === 'email'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
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
              <th scope="col" class="px-4 py-3">บทบาท</th>
              <th scope="col" class="px-4 py-3 text-center">MFA</th>
              <th
                scope="col"
                class="cursor-pointer px-4 py-3 hover:bg-gray-100"
                @click="handleSort('last_login_at')"
              >
                <div class="flex items-center gap-1">
                  <span>เข้าสู่ระบบล่าสุด</span>
                  <span v-if="filters.sort === 'last_login_at'">{{
                    filters.order === 'asc' ? '↑' : '↓'
                  }}</span>
                </div>
              </th>
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
          <tbody class="divide-y divide-gray-200 bg-white">
            <tr v-for="u in users" :key="u.uuid" class="hover:bg-gray-50 transition">
              <!-- Username -->
              <td class="px-4 py-3 font-medium text-gray-900">
                <router-link
                  :to="`/users/${u.uuid}`"
                  class="text-blue-600 hover:text-blue-800 hover:underline"
                >
                  {{ u.username }}
                </router-link>
              </td>

              <!-- Email -->
              <td class="px-4 py-3 text-gray-500">
                {{ u.email || '-' }}
              </td>

              <!-- Status -->
              <td class="px-4 py-3">
                <StatusBadge :status="u.status" />
              </td>

              <!-- Roles (Read-Only) -->
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1">
                  <span
                    v-for="r in u.roles"
                    :key="r.uuid || r.code"
                    class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800"
                  >
                    {{ r.name || r.code }}
                  </span>
                  <span v-if="!u.roles || u.roles.length === 0" class="text-xs text-gray-400">
                    -
                  </span>
                </div>
              </td>

              <!-- MFA Status -->
              <td class="px-4 py-3 text-center">
                <span
                  v-if="u.mfa_enabled"
                  class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700"
                >
                  เปิดใช้งาน
                </span>
                <span v-else class="text-xs text-gray-400"> ปิด </span>
              </td>

              <!-- Last Login -->
              <td class="px-4 py-3 text-xs text-gray-500">
                {{ formatDate(u.last_login_at) }}
              </td>

              <!-- Created At -->
              <td class="px-4 py-3 text-xs text-gray-500">
                {{ formatDate(u.created_at) }}
              </td>

              <!-- Actions -->
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <!-- View Details -->
                  <router-link
                    :to="`/users/${u.uuid}`"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                    title="ดูรายละเอียด"
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

                  <!-- Edit -->
                  <router-link
                    v-if="authStore.can('user.update')"
                    :to="`/users/${u.uuid}/edit`"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-blue-600"
                    title="แก้ไขข้อมูล"
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

                  <!-- Reset Password Trigger -->
                  <button
                    v-if="authStore.can('user.update')"
                    type="button"
                    @click="openResetPasswordModal(u)"
                    class="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-amber-600"
                    title="รีเซ็ตรหัสผ่าน"
                  >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                      />
                    </svg>
                  </button>

                  <!-- Status Change Actions (user.disable permission) -->
                  <template v-if="authStore.can('user.disable')">
                    <!-- Activate if not active -->
                    <button
                      v-if="u.status !== 'ACTIVE'"
                      type="button"
                      @click="openStatusModal(u, 'ACTIVE')"
                      class="rounded p-1 text-green-600 hover:bg-green-50 hover:text-green-700"
                      title="เปิดใช้งานบัญชี"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                        />
                      </svg>
                    </button>

                    <!-- Disable if active and not self -->
                    <button
                      v-if="u.status === 'ACTIVE' && authStore.user?.uuid !== u.uuid"
                      type="button"
                      @click="openStatusModal(u, 'DISABLED')"
                      class="rounded p-1 text-red-500 hover:bg-red-50 hover:text-red-700"
                      title="ปิดใช้งานบัญชี"
                    >
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
                        />
                      </svg>
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <div
        v-if="users.length > 0"
        class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6"
      >
        <div class="text-xs text-gray-700">
          แสดงหน้าที่ <span class="font-medium">{{ pagination.current_page }}</span> จาก
          <span class="font-medium">{{ pagination.last_page }}</span> (ทั้งหมด
          <span class="font-medium">{{ pagination.total }}</span> รายการ)
        </div>

        <div class="flex items-center space-x-2">
          <button
            type="button"
            :disabled="pagination.current_page <= 1"
            @click="changePage(pagination.current_page - 1)"
            class="rounded border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            ก่อนหน้า
          </button>
          <button
            type="button"
            :disabled="pagination.current_page >= pagination.last_page"
            @click="changePage(pagination.current_page + 1)"
            class="rounded border border-gray-300 bg-white px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
          >
            ถัดไป
          </button>
        </div>
      </div>
    </div>

    <!-- Status Change Confirmation Modal -->
    <ConfirmModal
      :show="confirmModal.show"
      :title="confirmModal.title"
      :message="confirmModal.message"
      :warning="confirmModal.warning"
      :confirm-label="confirmModal.confirmLabel"
      :is-danger="confirmModal.isDanger"
      :loading="confirmModal.loading"
      @confirm="executeStatusChange"
      @cancel="confirmModal.show = false"
    />

    <!-- Reset Password Modal -->
    <ResetPasswordModal
      :show="resetModal.show"
      :user="resetModal.user"
      @success="handleResetSuccess"
      @close="resetModal.show = false"
    />
  </div>
</template>
