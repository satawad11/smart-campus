<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const errorMessage = ref('')
const successMessage = ref('')
const fieldErrors = ref({})

const errorMap = {
  INVALID_CURRENT_PASSWORD: 'รหัสผ่านปัจจุบันไม่ถูกต้อง',
  PASSWORD_RECENTLY_USED: 'ไม่สามารถใช้รหัสผ่านเดิมที่เคยใช้งานล่าสุดได้',
  PASSWORD_TOO_COMMON: 'รหัสผ่านนี้ง่ายเกินไป กรุณาเลือกรหัสผ่านที่มีความปลอดภัยสูงขึ้น',
  VALIDATION_ERROR: 'ข้อมูลรหัสผ่านไม่ผ่านเกณฑ์ความปลอดภัย',
}

async function handleChangePassword() {
  errorMessage.value = ''
  successMessage.value = ''
  fieldErrors.value = {}

  if (!currentPassword.value) {
    fieldErrors.value.current_password = 'กรุณากรอกรหัสผ่านปัจจุบัน'
  }
  if (!password.value) {
    fieldErrors.value.password = 'กรุณากรอกรหัสผ่านใหม่'
  }
  if (!passwordConfirmation.value) {
    fieldErrors.value.password_confirmation = 'กรุณายืนยันรหัสผ่านใหม่'
  } else if (password.value !== passwordConfirmation.value) {
    fieldErrors.value.password_confirmation = 'การยืนยันรหัสผ่านใหม่ไม่ตรงกัน'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  try {
    await authStore.changePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    successMessage.value = 'เปลี่ยนรหัสผ่านสำเร็จ กำลังนำท่านเข้าสู่ระบบ...'
    setTimeout(() => {
      router.push({ name: 'dashboard' })
    }, 1200)
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    const mapped = errorMap[err.code]
    errorMessage.value =
      mapped || apiErrorText(err, 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน กรุณาลองใหม่อีกครั้ง')
  }
}

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="mb-6 text-center">
      <div
        class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
          />
        </svg>
      </div>
      <h1 class="mt-4 text-2xl font-bold text-gray-900">เปลี่ยนรหัสผ่าน</h1>
      <p class="mt-1 text-sm text-gray-500">
        {{
          authStore.mustChangePassword
            ? 'กรุณาตั้งรหัสผ่านใหม่เพื่อความปลอดภัยก่อนเริ่มต้นใช้งาน'
            : 'ตั้งค่ารหัสผ่านใหม่สำหรับบัญชีผู้ใช้ของคุณ'
        }}
      </p>
    </div>

    <!-- Mandatory Password Change Alert -->
    <div
      v-if="authStore.mustChangePassword"
      class="mb-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800 border border-amber-200"
      role="alert"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-4 w-4 shrink-0 text-amber-600"
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
        <span>ระบบกำหนดให้คุณต้องเปลี่ยนรหัสผ่านก่อนเข้าใช้งาน</span>
      </div>
    </div>

    <!-- Success Banner -->
    <div
      v-if="successMessage"
      class="mb-4 rounded-md bg-green-50 p-3 text-sm text-green-800 border border-green-200"
      role="status"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-4 w-4 shrink-0 text-green-600"
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
    </div>

    <!-- Error Banner -->
    <div
      v-if="errorMessage"
      class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-800 border border-red-200"
      role="alert"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-4 w-4 shrink-0 text-red-600"
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

    <!-- Password Requirements Hint -->
    <div
      class="mb-4 rounded-md bg-gray-50 p-3 text-xs text-gray-600 border border-gray-200 space-y-1"
    >
      <div class="font-medium text-gray-700">เกณฑ์ความปลอดภัยของรหัสผ่าน:</div>
      <ul class="list-disc list-inside space-y-0.5 text-gray-500">
        <li>ความยาวอย่างน้อย 8 ตัวอักษร</li>
        <li>มีทั้งตัวอักษรพิมพ์ใหญ่ (A-Z) และตัวอักษรพิมพ์เล็ก (a-z)</li>
        <li>มีตัวเลข (0-9) อย่างน้อย 1 ตัว</li>
        <li>มีอักขระพิเศษ (เช่น @, $, !, %, *, #, ?) อย่างน้อย 1 ตัว</li>
      </ul>
    </div>

    <!-- Change Password Form -->
    <form @submit.prevent="handleChangePassword" class="space-y-4" novalidate>
      <div>
        <label for="current_password" class="block text-sm font-medium text-gray-700"
          >รหัสผ่านปัจจุบัน</label
        >
        <div class="mt-1">
          <input
            id="current_password"
            v-model="currentPassword"
            type="password"
            autocomplete="current-password"
            required
            :disabled="authStore.loading"
            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50 disabled:text-gray-500"
            :class="{
              'border-red-300 focus:border-red-500 focus:ring-red-500':
                fieldErrors.current_password,
            }"
            placeholder="••••••••"
          />
        </div>
        <p v-if="fieldErrors.current_password" class="mt-1 text-xs text-red-600">
          {{
            Array.isArray(fieldErrors.current_password)
              ? fieldErrors.current_password[0]
              : fieldErrors.current_password
          }}
        </p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700">รหัสผ่านใหม่</label>
        <div class="mt-1">
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            required
            :disabled="authStore.loading"
            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50 disabled:text-gray-500"
            :class="{
              'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.password,
            }"
            placeholder="••••••••"
          />
        </div>
        <p v-if="fieldErrors.password" class="mt-1 text-xs text-red-600">
          {{ Array.isArray(fieldErrors.password) ? fieldErrors.password[0] : fieldErrors.password }}
        </p>
      </div>

      <div>
        <label for="password_confirmation" class="block text-sm font-medium text-gray-700"
          >ยืนยันรหัสผ่านใหม่</label
        >
        <div class="mt-1">
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            required
            :disabled="authStore.loading"
            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50 disabled:text-gray-500"
            :class="{
              'border-red-300 focus:border-red-500 focus:ring-red-500':
                fieldErrors.password_confirmation,
            }"
            placeholder="••••••••"
          />
        </div>
        <p v-if="fieldErrors.password_confirmation" class="mt-1 text-xs text-red-600">
          {{
            Array.isArray(fieldErrors.password_confirmation)
              ? fieldErrors.password_confirmation[0]
              : fieldErrors.password_confirmation
          }}
        </p>
      </div>

      <div class="pt-2 space-y-2">
        <button
          type="submit"
          :disabled="authStore.loading"
          class="flex w-full justify-center rounded-md bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition duration-150 ease-in-out"
        >
          <svg
            v-if="authStore.loading"
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
          <span>{{ authStore.loading ? 'กำลังบันทึกข้อมูล...' : 'บันทึกรหัสผ่านใหม่' }}</span>
        </button>

        <button
          type="button"
          @click="handleLogout"
          :disabled="authStore.loading"
          class="flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition duration-150 ease-in-out"
        >
          ออกจากระบบ
        </button>
      </div>
    </form>
  </div>
</template>
