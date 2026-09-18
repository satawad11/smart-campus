<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { rolesService } from '@/services/roles'
import RoleTypeBadge from '@/components/roles/RoleTypeBadge.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'

const route = useRoute()
const router = useRouter()

const role = ref(null)
const initialLoading = ref(true)
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const form = reactive({
  name: '',
  description: '',
  status: 'ACTIVE',
})

const confirmModal = reactive({
  show: false,
  loading: false,
})

const errorTranslations = {
  SYSTEM_ROLE_PROTECTED: 'ไม่สามารถปิดใช้งานบทบาทผู้ดูแลระบบสูงสุด (SUPER_ADMIN) ได้',
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการแก้ไขบทบาทนี้',
  ROLE_NOT_FOUND: 'ไม่พบข้อมูลบทบาท',
}

async function fetchRole() {
  initialLoading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await rolesService.get(uuid)
    role.value = data
    form.name = data.name || ''
    form.description = data.description || ''
    form.status = data.status || 'ACTIVE'
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถโหลดข้อมูลบทบาทได้'
  } finally {
    initialLoading.value = false
  }
}

async function executeSubmit() {
  loading.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    const payload = {
      name: form.name.trim(),
      status: form.status,
    }
    if (form.description.trim()) {
      payload.description = form.description.trim()
    } else {
      payload.description = null
    }

    await rolesService.update(route.params.uuid, payload)
    router.push(`/roles/${route.params.uuid}`)
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value =
      errorTranslations[err.code] || err.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูลบทบาท'
  } finally {
    loading.value = false
    confirmModal.show = false
  }
}

function handleFormSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!form.name.trim()) {
    fieldErrors.value.name = 'กรุณากรอกชื่อบทบาท'
    return
  }

  // If status is changed to DISABLED, show confirmation modal
  if (form.status === 'DISABLED' && role.value?.status !== 'DISABLED') {
    confirmModal.show = true
    return
  }

  executeSubmit()
}

onMounted(() => {
  fetchRole()
})
</script>

<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/roles" class="hover:text-blue-600"> จัดการบทบาท </router-link>
        <span>/</span>
        <router-link :to="`/roles/${route.params.uuid}`" class="hover:text-blue-600">
          {{ role?.name || route.params.uuid }}
        </router-link>
        <span>/</span>
        <span class="text-gray-900 font-medium">แก้ไข</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">แก้ไขข้อมูลบทบาท</h1>
    </div>

    <!-- Error Banner -->
    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-4 text-sm text-red-800 border border-red-200"
      role="alert"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-red-600 mr-2 shrink-0"
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
    </div>

    <!-- Loading State -->
    <div v-if="initialLoading" class="rounded-lg bg-white p-8 text-center text-gray-500 shadow">
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

    <!-- Edit Form Card -->
    <div v-else-if="role" class="rounded-lg bg-white p-6 shadow">
      <form @submit.prevent="handleFormSubmit" class="space-y-6" novalidate>
        <!-- Read-Only Role Code & Type Display -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-medium text-gray-500">
              รหัสบทบาท (Role Code - ไม่สามารถแก้ไขได้)
            </label>
            <div
              class="mt-1 font-mono text-sm font-semibold text-gray-700 bg-gray-50 p-2.5 rounded border border-gray-200"
            >
              {{ role.code }}
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500"> ประเภทบทบาท </label>
            <div class="mt-1 flex items-center bg-gray-50 p-2.5 rounded border border-gray-200">
              <RoleTypeBadge :system-role="role.system_role" />
            </div>
          </div>
        </div>

        <!-- Name -->
        <div>
          <label for="edit-name" class="block text-sm font-medium text-gray-700">
            ชื่อบทบาท (Role Name) <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="edit-name"
              v-model="form.name"
              type="text"
              required
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.name,
              }"
            />
          </div>
          <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">
            {{ Array.isArray(fieldErrors.name) ? fieldErrors.name[0] : fieldErrors.name }}
          </p>
        </div>

        <!-- Description -->
        <div>
          <label for="edit-description" class="block text-sm font-medium text-gray-700">
            คำอธิบายบทบาท
          </label>
          <div class="mt-1">
            <textarea
              id="edit-description"
              v-model="form.description"
              rows="3"
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
            ></textarea>
          </div>
        </div>

        <!-- Status -->
        <div>
          <label for="edit-status" class="block text-sm font-medium text-gray-700">
            สถานะการใช้งาน
          </label>
          <div class="mt-1">
            <select
              id="edit-status"
              v-model="form.status"
              :disabled="loading || role.code === 'SUPER_ADMIN'"
              class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-10 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50 disabled:cursor-not-allowed"
            >
              <option value="ACTIVE">ใช้งานปกติ (ACTIVE)</option>
              <option value="DISABLED" :disabled="role.code === 'SUPER_ADMIN'">
                ปิดใช้งาน (DISABLED)
              </option>
            </select>
          </div>
          <p v-if="role.code === 'SUPER_ADMIN'" class="mt-1 text-xs text-indigo-600">
            บทบาท SUPER_ADMIN เป็นบทบาทหลักของระบบ ไม่สามารถปิดใช้งานได้
          </p>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
          <router-link
            :to="`/roles/${route.params.uuid}`"
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            ยกเลิก
          </router-link>
          <button
            type="submit"
            :disabled="loading"
            class="inline-flex justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-60 transition duration-150"
          >
            <svg
              v-if="loading"
              class="mr-2 h-4 w-4 animate-spin text-white"
              xmlns="http://www.w3.org/2000/svg"
              fill="none"
              viewBox="0 0 24 24"
            >
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
            <span>{{ loading ? 'กำลังบันทึก...' : 'บันทึกการเปลี่ยนแปลง' }}</span>
          </button>
        </div>
      </form>
    </div>

    <!-- Confirm Disable Role Modal -->
    <ConfirmModal
      :show="confirmModal.show"
      title="ยืนยันการปิดใช้งานบทบาท"
      :message="`คุณต้องการปิดใช้งานบทบาท '${role?.name}' ใช่หรือไม่?`"
      warning="คำเตือน: ผู้ใช้งานทุกคนที่ได้รับมอบหมายบทบาทนี้จะไม่สามารถใช้งานสิทธิ์ใดๆ ภายใต้บทบาทนี้ได้ทันทีจนกว่าจะเปิดใช้งานอีกครั้ง"
      confirm-label="ยืนยันปิดใช้งาน"
      :is-danger="true"
      :loading="loading"
      @confirm="executeSubmit"
      @cancel="confirmModal.show = false"
    />
  </div>
</template>
