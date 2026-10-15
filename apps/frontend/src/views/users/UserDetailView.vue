<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { usersService } from '@/services/users'
import StatusBadge from '@/components/common/StatusBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import ResetPasswordModal from '@/components/users/ResetPasswordModal.vue'
import { apiErrorText } from '@/constants/labels'

const route = useRoute()
const authStore = useAuthStore()

const user = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

const confirmModal = reactive({
  show: false,
  title: '',
  message: '',
  warning: '',
  confirmLabel: 'ยืนยัน',
  isDanger: false,
  loading: false,
  targetStatus: null,
})

const resetModal = reactive({
  show: false,
})

const errorTranslations = {
  USER_SELF_DISABLE_FORBIDDEN: 'ไม่สามารถปิดใช้งาน ล็อก หรือระงับบัญชีของตนเองได้',
  LAST_SUPER_ADMIN_REQUIRED:
    'ไม่สามารถปิดใช้งานหรือล็อกผู้ดูแลระบบระดับสูงสุด (SUPER_ADMIN) บัญชีสุดท้ายได้',
  USER_NOT_FOUND: 'ไม่พบข้อมูลผู้ใช้งานที่ระบุในระบบ',
}

async function fetchUserDetail() {
  loading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await usersService.get(uuid)
    user.value = data
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'ไม่สามารถโหลดข้อมูลผู้ใช้งานได้')
  } finally {
    loading.value = false
  }
}

function openStatusModal(targetStatus) {
  if (!user.value) return

  const isDestructive = targetStatus !== 'ACTIVE'
  const isSelf = authStore.user?.uuid === user.value.uuid

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

  confirmModal.targetStatus = targetStatus
  confirmModal.title = `เปลี่ยนสถานะบัญชี: ${user.value.username}`
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
  if (!user.value || !confirmModal.targetStatus) return

  confirmModal.loading = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const updated = await usersService.changeStatus(user.value.uuid, confirmModal.targetStatus)
    user.value = updated
    confirmModal.show = false
    successMessage.value = `อัปเดตสถานะผู้ใช้ ${updated.username} สำเร็จ`
  } catch (err) {
    confirmModal.show = false
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะผู้ใช้')
  } finally {
    confirmModal.loading = false
  }
}

function handleResetSuccess(data) {
  successMessage.value = data.message || 'รีเซ็ตรหัสผ่านสำเร็จ'
  fetchUserDetail()
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
    successMessage.value = 'สร้างบัญชีผู้ใช้ใหม่เรียบร้อยแล้ว'
  }
  fetchUserDetail()
})
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Breadcrumb & Actions Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
          <router-link to="/users" class="hover:text-blue-600">จัดการผู้ใช้งาน</router-link>
          <span>/</span>
          <span class="text-gray-900 font-medium">{{ user?.username || 'รายละเอียดผู้ใช้' }}</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">
          {{ user ? user.username : 'รายละเอียดผู้ใช้งาน' }}
        </h1>
      </div>

      <!-- Action Buttons -->
      <div v-if="user" class="mt-4 sm:mt-0 flex flex-wrap gap-2">
        <router-link
          v-if="authStore.can('user.update')"
          :to="`/users/${user.uuid}/edit`"
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
          แก้ไขข้อมูล
        </router-link>

        <button
          v-if="authStore.can('user.update')"
          type="button"
          @click="resetModal.show = true"
          class="inline-flex items-center rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800 shadow-sm hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-500"
        >
          <svg
            class="-ml-0.5 mr-1.5 h-4 w-4 text-amber-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
            />
          </svg>
          รีเซ็ตรหัสผ่าน
        </button>
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
      <p class="mt-2 text-sm">กำลังโหลดข้อมูล...</p>
    </div>

    <!-- Detail Card -->
    <div v-else-if="user" class="space-y-6">
      <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
          <h2 class="text-lg font-medium text-gray-900">ข้อมูลบัญชีผู้ใช้</h2>
          <StatusBadge :status="user.status" size="lg" />
        </div>

        <dl class="divide-y divide-gray-200">
          <!-- UUID -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">รหัสประจำตัวสาธารณะ (UUID)</dt>
            <dd class="mt-1 text-sm font-mono text-gray-900 sm:col-span-2 sm:mt-0">
              {{ user.uuid }}
            </dd>
          </div>

          <!-- Username -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">ชื่อผู้ใช้ (Username)</dt>
            <dd class="mt-1 text-sm font-semibold text-gray-900 sm:col-span-2 sm:mt-0">
              {{ user.username }}
            </dd>
          </div>

          <!-- Email -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">อีเมล (Email)</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ user.email || '-' }}
            </dd>
          </div>

          <!-- Roles (Read-Only) -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">บทบาทที่ได้รับมอบหมาย</dt>
            <dd class="mt-1 sm:col-span-2 sm:mt-0">
              <div class="flex flex-wrap gap-1.5">
                <span
                  v-for="r in user.roles"
                  :key="r.uuid || r.code"
                  class="inline-flex items-center rounded-md bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10"
                >
                  {{ r.name || r.code }}
                  <span v-if="r.code" class="ml-1 text-[10px] text-blue-500">({{ r.code }})</span>
                </span>
                <span v-if="!user.roles || user.roles.length === 0" class="text-sm text-gray-400">
                  ไม่มีบทบาท
                </span>
              </div>
            </dd>
          </div>

          <!-- MFA Status -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">การยืนยันตัวตนแบบ 2 ขั้นตอน (MFA)</dt>
            <dd class="mt-1 sm:col-span-2 sm:mt-0">
              <span
                v-if="user.mfa_enabled"
                class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-700/10"
              >
                เปิดใช้งานแล้ว (TOTP)
              </span>
              <span
                v-else
                class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10"
              >
                ยังไม่เปิดใช้งาน
              </span>
            </dd>
          </div>

          <!-- Must Change Password -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">สถานะการบังคับเปลี่ยนรหัสผ่าน</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              <span v-if="user.must_change_password" class="text-amber-600 font-medium">
                ต้องเปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบ
              </span>
              <span v-else class="text-gray-500"> ปกติ (ไม่ต้องเปลี่ยน) </span>
            </dd>
          </div>

          <!-- Last Login -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">เข้าสู่ระบบล่าสุด</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ formatDate(user.last_login_at) }}
            </dd>
          </div>

          <!-- Created At -->
          <div class="px-6 py-4 sm:grid sm:grid-cols-3 sm:gap-4">
            <dt class="text-sm font-medium text-gray-500">วันที่สร้างบัญชี</dt>
            <dd class="mt-1 text-sm text-gray-900 sm:col-span-2 sm:mt-0">
              {{ formatDate(user.created_at) }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- Account Status Lifecycle Controls (user.disable permission) -->
      <div v-if="authStore.can('user.disable')" class="rounded-lg bg-white p-6 shadow space-y-4">
        <h2 class="text-base font-semibold text-gray-900">การจัดการสถานะบัญชี</h2>
        <p class="text-xs text-gray-500">
          การเปลี่ยนสถานะบัญชีเป็น LOCKED, DISABLED หรือ EXPIRED
          จะทำการยกเลิกเซสชันที่กำลังใช้งานอยู่ทั้งหมดทันที
        </p>

        <!-- Self-Protection Notice -->
        <div
          v-if="authStore.user?.uuid === user.uuid"
          class="rounded-md bg-blue-50 p-3 text-xs text-blue-700 border border-blue-200"
        >
          บัญชีนี้คือบัญชีของคุณเอง ระบบจะไม่อนุญาตให้ปิดใช้งานหรือล็อกบัญชีของตนเอง
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
          <!-- Activate -->
          <button
            v-if="user.status !== 'ACTIVE'"
            type="button"
            @click="openStatusModal('ACTIVE')"
            class="rounded-md bg-green-600 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          >
            เปิดใช้งาน (ACTIVE)
          </button>

          <!-- Lock -->
          <button
            v-if="user.status !== 'LOCKED'"
            type="button"
            :disabled="authStore.user?.uuid === user.uuid"
            @click="openStatusModal('LOCKED')"
            class="rounded-md border border-amber-300 bg-white px-3 py-2 text-xs font-semibold text-amber-700 shadow-sm hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            ล็อกบัญชี (LOCKED)
          </button>

          <!-- Disable -->
          <button
            v-if="user.status !== 'DISABLED'"
            type="button"
            :disabled="authStore.user?.uuid === user.uuid"
            @click="openStatusModal('DISABLED')"
            class="rounded-md bg-red-600 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            ปิดใช้งาน (DISABLED)
          </button>

          <!-- Expire -->
          <button
            v-if="user.status !== 'EXPIRED'"
            type="button"
            :disabled="authStore.user?.uuid === user.uuid"
            @click="openStatusModal('EXPIRED')"
            class="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            กำหนดหมดอายุ (EXPIRED)
          </button>
        </div>
      </div>
    </div>

    <!-- Status Change Modal -->
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
      :user="user"
      @success="handleResetSuccess"
      @close="resetModal.show = false"
    />
  </div>
</template>
