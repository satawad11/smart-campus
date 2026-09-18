<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { rolesService } from '@/services/roles'
import StatusBadge from '@/components/common/StatusBadge.vue'
import RoleTypeBadge from '@/components/roles/RoleTypeBadge.vue'

const route = useRoute()
const authStore = useAuthStore()

const role = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

async function fetchRoleDetail() {
  loading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await rolesService.get(uuid)
    role.value = data
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถโหลดข้อมูลบทบาทได้'
  } finally {
    loading.value = false
  }
}

function formatDate(isoStr) {
  if (!isoStr) return '-'
  try {
    const d = new Date(isoStr)
    return d.toLocaleString('th-TH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return isoStr
  }
}

onMounted(() => {
  if (route.query.created === 'true') {
    successMessage.value = 'สร้างบทบาทใหม่สำเร็จ'
  }
  fetchRoleDetail()
})
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Breadcrumb & Actions Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
          <router-link to="/roles" class="hover:text-blue-600">จัดการบทบาท</router-link>
          <span>/</span>
          <span class="text-gray-900 font-medium">{{ role?.name || 'รายละเอียดบทบาท' }}</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ role ? role.name : 'รายละเอียดบทบาท' }}
        </h1>
      </div>

      <!-- Action Buttons -->
      <div v-if="role" class="mt-4 sm:mt-0 flex flex-wrap gap-2">
        <router-link
          :to="`/roles/${role.uuid}/permissions`"
          class="inline-flex items-center rounded-md border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 shadow-sm hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <svg
            class="-ml-0.5 mr-1.5 h-4 w-4 text-indigo-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
            />
          </svg>
          {{ authStore.can('role.manage') ? 'จัดการตารางสิทธิ์' : 'ดูตารางสิทธิ์' }}
        </router-link>

        <router-link
          v-if="authStore.can('role.manage')"
          :to="`/roles/${role.uuid}/edit`"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <svg
            class="-ml-0.5 mr-1.5 h-4 w-4 text-gray-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
            />
          </svg>
          แก้ไขบทบาท
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

    <!-- Disabled Role Callout -->
    <div
      v-if="role?.status === 'DISABLED'"
      class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 border border-amber-200"
      role="alert"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-amber-600 mr-2 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
        <span>
          <strong>บทบาทนี้ถูกปิดใช้งาน:</strong>
          สิทธิ์การใช้งานที่กำหนดไว้ในตารางสิทธิ์จะยังคงถูกบันทึกไว้
          แต่จะไม่ส่งผลต่อการเข้าถึงระบบของผู้ใช้งานในขณะนี้
        </span>
      </div>
    </div>

    <!-- SUPER_ADMIN Info Callout -->
    <div
      v-if="role?.code === 'SUPER_ADMIN'"
      class="rounded-md bg-indigo-50 p-4 text-sm text-indigo-800 border border-indigo-200"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-indigo-600 mr-2 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
        <span>
          <strong>บทบาทผู้ดูแลระบบสูงสุด (SUPER_ADMIN):</strong>
          เป็นบทบาทระบบหลักที่ไม่สามารถปิดใช้งานได้
          และจำเป็นต้องมีสิทธิ์ครบถ้วนตามแคตตาล็อกระบบทั้งหมด
        </span>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="rounded-lg bg-white p-8 text-center text-gray-500 shadow">
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

    <!-- Detail Card -->
    <div v-else-if="role" class="space-y-6">
      <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <h2 class="text-lg font-medium text-gray-900">ข้อมูลบทบาท</h2>
            <RoleTypeBadge :system-role="role.system_role" />
          </div>
          <StatusBadge :status="role.status" size="lg" />
        </div>

        <dl class="divide-y divide-gray-200">
          <!-- Code -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">รหัสบทบาท (Role Code)</dt>
            <dd class="mt-1 text-sm font-mono font-semibold text-gray-900 sm:col-span-2 sm:mt-0">
              {{ role.code }}
            </dd>
          </div>

          <!-- Name -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">ชื่อบทบาท (Name)</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">{{ role.name }}</dd>
          </div>

          <!-- Description -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">คำอธิบาย</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ role.description || '-' }}
            </dd>
          </div>

          <!-- Users Count -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">จำนวนผู้ใช้งานที่ได้รับมอบหมาย</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ role.users_count ?? '-' }} คน
            </dd>
          </div>

          <!-- Permissions Count -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">จำนวนสิทธิ์ที่กำหนดในบทบาทนี้</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              <span
                class="inline-flex items-center rounded-md bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700"
              >
                {{ role.permissions_count ?? role.permissions?.length ?? 0 }} สิทธิ์
              </span>
            </dd>
          </div>

          <!-- Created At -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">วันที่สร้าง</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ formatDate(role.created_at) }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- Assigned Permissions Preview -->
      <div class="rounded-lg bg-white p-6 shadow space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-base font-semibold text-gray-900">สิทธิ์ที่ได้รับอนุญาตในบทบาทนี้</h2>
            <p class="text-xs text-gray-500">
              รายการสิทธิ์การใช้งานระบบที่กำหนดไว้สำหรับบทบาท {{ role.name }}
            </p>
          </div>
          <router-link
            :to="`/roles/${role.uuid}/permissions`"
            class="text-xs font-medium text-blue-600 hover:text-blue-800"
          >
            เปิดตารางสิทธิ์ฉบับเต็ม →
          </router-link>
        </div>

        <div
          v-if="role.permissions && role.permissions.length > 0"
          class="flex flex-wrap gap-1.5 pt-2"
        >
          <span
            v-for="code in role.permissions"
            :key="code"
            class="inline-flex items-center font-mono rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700 ring-1 ring-inset ring-slate-600/10"
          >
            {{ code }}
          </span>
        </div>
        <div v-else class="text-xs text-gray-400 py-4 text-center">
          ยังไม่ได้กำหนดสิทธิ์ในบทบาทนี้
        </div>
      </div>
    </div>
  </div>
</template>
