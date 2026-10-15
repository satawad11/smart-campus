<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const code = ref('')
const errorMessage = ref('')
const fieldErrors = ref({})

const errorMap = {
  INVALID_MFA_CODE: 'รหัสความปลอดภัยไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง',
  MFA_CHALLENGE_EXPIRED: 'เซสชันการยืนยันตัวตนหมดอายุ กรุณาเข้าสู่ระบบใหม่',
  MFA_REQUIRED: 'จำเป็นต้องยืนยันตัวตนแบบ 2 ขั้นตอน (MFA)',
  TOO_MANY_ATTEMPTS: 'กรอกรหัสผิดพลาดหลายครั้งเกินไป กรุณารอสักครู่แล้วลองใหม่',
}

async function handleVerify() {
  errorMessage.value = ''
  fieldErrors.value = {}

  const cleanCode = code.value.trim()
  if (!cleanCode) {
    fieldErrors.value.code = 'กรุณากรอกรหัสความปลอดภัย 6 หลัก'
    return
  }

  if (cleanCode.length !== 6 || !/^\d{6}$/.test(cleanCode)) {
    fieldErrors.value.code = 'รหัสความปลอดภัยต้องเป็นตัวเลข 6 หลัก'
    return
  }

  try {
    await authStore.verifyMfa(cleanCode)

    if (authStore.mustChangePassword) {
      router.push({ name: 'change-password' })
      return
    }

    const redirectTarget = authStore.intendedRoute || { name: 'dashboard' }
    authStore.intendedRoute = null
    router.push(redirectTarget)
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }

    // If session expired or not authenticated during MFA verification, return to login
    if (err.code === 'MFA_CHALLENGE_EXPIRED' || err.status === 401) {
      authStore.clearAuth()
      router.push({ name: 'login' })
      return
    }

    const mapped = errorMap[err.code]
    errorMessage.value =
      mapped || apiErrorText(err, 'รหัสความปลอดภัยไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง')
  }
}

function handleCancel() {
  authStore.clearAuth()
  router.push({ name: 'login' })
}
</script>

<template>
  <div>
    <!-- MFA Header -->
    <div class="mb-6 text-center">
      <div
        class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
          />
        </svg>
      </div>
      <h1 class="mt-4 text-2xl font-bold text-gray-900">การยืนยันตัวตน 2 ขั้นตอน</h1>
      <p class="mt-1 text-sm text-gray-500">กรุณากรอกรหัส 6 หลักจากแอป Authenticator ของคุณ</p>
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

    <!-- MFA Form -->
    <form @submit.prevent="handleVerify" class="space-y-4" novalidate>
      <div>
        <label for="mfa-code" class="block text-center text-sm font-medium text-gray-700"
          >รหัสความปลอดภัย (6 หลัก)</label
        >
        <div class="mt-2">
          <input
            id="mfa-code"
            v-model="code"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="6"
            autocomplete="one-time-code"
            required
            autofocus
            :disabled="authStore.loading"
            class="block w-full text-center font-mono text-2xl tracking-[0.5em] rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-300 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-500"
            :class="{ 'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.code }"
            placeholder="000000"
          />
        </div>
        <p v-if="fieldErrors.code" class="mt-1 text-center text-xs text-red-600">
          {{ Array.isArray(fieldErrors.code) ? fieldErrors.code[0] : fieldErrors.code }}
        </p>
      </div>

      <div class="pt-2 space-y-2">
        <button
          type="submit"
          :disabled="authStore.loading"
          class="flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition duration-150 ease-in-out"
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
          <span>{{ authStore.loading ? 'กำลังตรวจสอบ...' : 'ยืนยันรหัส' }}</span>
        </button>

        <button
          type="button"
          @click="handleCancel"
          :disabled="authStore.loading"
          class="flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 transition duration-150 ease-in-out"
        >
          ยกเลิกและกลับไปหน้าเข้าสู่ระบบ
        </button>
      </div>
    </form>
  </div>
</template>
