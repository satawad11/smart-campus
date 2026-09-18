<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const username = ref('')
const password = ref('')
const errorMessage = ref('')
const fieldErrors = ref({})

const errorMap = {
  INVALID_CREDENTIALS: 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
  ACCOUNT_LOCKED:
    'บัญชีผู้ใช้ถูกระงับชั่วคราวเนื่องจากเข้าสู่ระบบผิดพลาดหลายครั้ง กรุณาลองใหม่อีกครั้งภายหลัง',
  ACCOUNT_DISABLED: 'บัญชีผู้ใช้นี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
  TOO_MANY_ATTEMPTS: 'คำขอเข้าสู่ระบบมากเกินไป กรุณารอสักครู่แล้วลองใหม่',
  MFA_REQUIRED: 'จำเป็นต้องยืนยันตัวตนแบบ 2 ขั้นตอน (MFA)',
}

async function handleLogin() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!username.value.trim()) {
    fieldErrors.value.username = 'กรุณากรอกชื่อผู้ใช้'
  }
  if (!password.value) {
    fieldErrors.value.password = 'กรุณากรอกรหัสผ่าน'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  try {
    const result = await authStore.login({
      username: username.value,
      password: password.value,
    })

    if (result.status === 'MFA_REQUIRED') {
      router.push({ name: 'mfa' })
      return
    }

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
    const mapped = errorMap[err.code]
    errorMessage.value =
      mapped || err.message || 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง'
  }
}
</script>

<template>
  <div>
    <!-- Logo / Brand Header -->
    <div class="mb-6 text-center">
      <div
        class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
          />
        </svg>
      </div>
      <h1 class="mt-4 text-2xl font-bold text-gray-900">เข้าสู่ระบบ</h1>
      <p class="mt-1 text-sm text-gray-500">ระบบรักษาความปลอดภัยและควบคุมการเข้าออกอัจฉริยะ</p>
    </div>

    <!-- Session Expired Notification -->
    <div
      v-if="authStore.sessionExpired"
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
        <span>เซสชันของคุณหมดอายุหรือถูกยกเลิก กรุณาเข้าสู่ระบบใหม่</span>
      </div>
    </div>

    <!-- General Error Banner -->
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

    <!-- Login Form -->
    <form @submit.prevent="handleLogin" class="space-y-4" novalidate>
      <div>
        <label for="username" class="block text-sm font-medium text-gray-700">ชื่อผู้ใช้</label>
        <div class="mt-1">
          <input
            id="username"
            v-model="username"
            type="text"
            autocomplete="username"
            required
            :disabled="authStore.loading"
            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50 disabled:text-gray-500"
            :class="{
              'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.username,
            }"
            placeholder="เช่น admin หรือ user@campus.local"
          />
        </div>
        <p v-if="fieldErrors.username" class="mt-1 text-xs text-red-600">
          {{ Array.isArray(fieldErrors.username) ? fieldErrors.username[0] : fieldErrors.username }}
        </p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700">รหัสผ่าน</label>
        <div class="mt-1">
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
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
          <span>{{ authStore.loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}</span>
        </button>
      </div>
    </form>
  </div>
</template>
