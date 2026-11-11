<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const username = ref('')
const password = ref('')
const errorMessage = ref('')
const fieldErrors = ref({})
const showPassword = ref(false)
const capsLock = ref(false)

function trackCapsLock(event) {
  capsLock.value = !!event.getModifierState?.('CapsLock')
}

function firstError(value) {
  return Array.isArray(value) ? value[0] : value
}

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
      mapped || apiErrorText(err, 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง')
  }
}
</script>

<template>
  <div>
    <!-- Heading -->
    <div class="mb-7">
      <div
        class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-600/30 ring-1 ring-white/20 ring-inset"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.8"
            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
          />
        </svg>
      </div>
      <h1 class="mt-5 text-[28px] leading-tight font-semibold tracking-tight text-gray-900">
        เข้าสู่ระบบ
      </h1>
      <p class="mt-1.5 text-sm text-gray-500">ระบบรักษาความปลอดภัยและควบคุมการเข้าออกอัจฉริยะ</p>
    </div>

    <!-- Session Expired Notification -->
    <div
      v-if="authStore.sessionExpired"
      class="mb-5 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-sm text-amber-800"
      role="alert"
    >
      <svg
        class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"
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

    <!-- General Error Banner -->
    <div
      v-if="errorMessage"
      class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-sm text-red-800"
      role="alert"
    >
      <svg
        class="mt-0.5 h-4 w-4 shrink-0 text-red-600"
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

    <!-- Login Form -->
    <form @submit.prevent="handleLogin" class="space-y-5" novalidate>
      <div>
        <label for="username" class="mb-1.5 block text-sm font-medium text-gray-700">
          ชื่อผู้ใช้
        </label>
        <div class="relative">
          <span
            class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
              />
            </svg>
          </span>
          <input
            id="username"
            v-model="username"
            type="text"
            autocomplete="username"
            autofocus
            required
            :disabled="authStore.loading"
            :aria-invalid="!!fieldErrors.username"
            class="login-input"
            :class="{ 'login-input--error': fieldErrors.username }"
            placeholder="เช่น admin หรือ user@campus.local"
          />
        </div>
        <p v-if="fieldErrors.username" class="mt-1.5 text-xs font-medium text-red-600">
          {{ firstError(fieldErrors.username) }}
        </p>
      </div>

      <div>
        <label for="password" class="mb-1.5 block text-sm font-medium text-gray-700">
          รหัสผ่าน
        </label>
        <div class="relative">
          <span
            class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-gray-400"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
              />
            </svg>
          </span>
          <input
            id="password"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            autocomplete="current-password"
            required
            :disabled="authStore.loading"
            :aria-invalid="!!fieldErrors.password"
            class="login-input login-input--trailing"
            :class="{ 'login-input--error': fieldErrors.password }"
            placeholder="••••••••"
            @keydown="trackCapsLock"
            @keyup="trackCapsLock"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center rounded-r-xl px-3.5 text-gray-400 hover:text-gray-700 focus:outline-none focus-visible:text-blue-600"
            :aria-label="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
            :aria-pressed="showPassword"
            @click="showPassword = !showPassword"
          >
            <svg
              v-if="showPassword"
              class="h-5 w-5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
              />
            </svg>
            <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
              />
            </svg>
          </button>
        </div>
        <p v-if="fieldErrors.password" class="mt-1.5 text-xs font-medium text-red-600">
          {{ firstError(fieldErrors.password) }}
        </p>
        <p
          v-else-if="capsLock"
          class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-amber-700"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M5 15l7-7 7 7"
            />
          </svg>
          ปุ่ม Caps Lock เปิดอยู่
        </p>
      </div>

      <button type="submit" :disabled="authStore.loading" class="login-submit group">
        <svg
          v-if="authStore.loading"
          class="h-4 w-4 animate-spin text-white"
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
        <svg
          v-if="!authStore.loading"
          class="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M13 7l5 5-5 5M6 12h12"
          />
        </svg>
      </button>
    </form>

    <div class="mt-7 flex items-start gap-2 border-t border-gray-100 pt-5 text-xs text-gray-500">
      <svg
        class="h-4 w-4 shrink-0 text-emerald-600"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.8"
          d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
        />
      </svg>
      <span>ทุกการเข้าสู่ระบบจะถูกบันทึกไว้เพื่อการตรวจสอบย้อนหลัง</span>
    </div>
  </div>
</template>

<style scoped>
.login-input {
  display: block;
  width: 100%;
  height: 3rem;
  border-radius: 0.875rem;
  border: 1px solid var(--color-gray-200);
  background-color: var(--color-surface);
  padding-left: 2.75rem;
  padding-right: 1rem;
  font-size: 0.9375rem;
  color: var(--color-gray-900);
  box-shadow: var(--shadow-sm);
  outline: none;
}
.login-input--trailing {
  padding-right: 2.75rem;
}
.login-input:hover:not(:disabled) {
  border-color: var(--color-gray-300);
}
.login-input:focus {
  border-color: var(--color-blue-500);
  box-shadow: 0 0 0 4px color-mix(in oklab, var(--color-blue-500) 18%, transparent);
}
.login-input--error,
.login-input--error:hover:not(:disabled) {
  border-color: var(--color-red-300);
}
.login-input--error:focus {
  border-color: var(--color-red-500);
  box-shadow: 0 0 0 4px color-mix(in oklab, var(--color-red-500) 16%, transparent);
}
.login-input:disabled {
  background-color: var(--color-gray-50);
  color: var(--color-gray-500);
}

.login-submit {
  position: relative;
  display: flex;
  width: 100%;
  height: 3rem;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  overflow: hidden;
  border-radius: 0.875rem;
  background: linear-gradient(135deg, var(--color-blue-600), var(--color-indigo-600));
  font-size: 0.9375rem;
  font-weight: 600;
  color: #fff;
  box-shadow:
    0 1px 0 0 rgb(255 255 255 / 0.18) inset,
    0 10px 24px -10px color-mix(in oklab, var(--color-blue-600) 80%, transparent);
  transition:
    transform 160ms var(--ease-premium),
    box-shadow 200ms var(--ease-premium),
    filter 160ms ease;
}
.login-submit:hover:not(:disabled) {
  filter: brightness(1.07);
  box-shadow:
    0 1px 0 0 rgb(255 255 255 / 0.18) inset,
    0 14px 30px -10px color-mix(in oklab, var(--color-blue-600) 90%, transparent);
}
.login-submit:active:not(:disabled) {
  transform: translateY(1px);
}
.login-submit:focus-visible {
  outline: none;
  box-shadow:
    0 0 0 3px var(--color-surface),
    0 0 0 5px var(--color-blue-500);
}
.login-submit:disabled {
  cursor: not-allowed;
  opacity: 0.65;
}
</style>
