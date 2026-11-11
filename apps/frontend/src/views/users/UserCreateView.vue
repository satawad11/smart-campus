<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { usersService } from '@/services/users'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()

const form = reactive({
  username: '',
  email: '',
  password: '',
  status: 'ACTIVE',
  must_change_password: true,
})

const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!form.username.trim()) {
    fieldErrors.value.username = 'กรุณากรอกชื่อผู้ใช้'
  }
  if (!form.password) {
    fieldErrors.value.password = 'กรุณากรอกรหัสผ่านเริ่มต้น'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  loading.value = true
  try {
    const payload = {
      username: form.username.trim(),
      password: form.password,
      status: form.status,
      must_change_password: Boolean(form.must_change_password),
    }

    if (form.email.trim()) {
      payload.email = form.email.trim()
    }

    const created = await usersService.create(payload)

    // Clear password from memory immediately
    form.password = ''

    router.push({
      path: `/users/${created.uuid}`,
      query: { created: 'true' },
    })
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = apiErrorText(
      err,
      'เกิดข้อผิดพลาดในการสร้างบัญชีผู้ใช้ กรุณาลองใหม่อีกครั้ง',
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
          <router-link to="/users" class="hover:text-blue-600">จัดการผู้ใช้งาน</router-link>
          <span>/</span>
          <span>เพิ่มผู้ใช้งานใหม่</span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">เพิ่มผู้ใช้งานใหม่</h1>
      </div>
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

    <!-- Create Form -->
    <div class="rounded-lg bg-surface p-6 shadow">
      <form @submit.prevent="handleSubmit" class="space-y-6" novalidate>
        <!-- Username -->
        <div>
          <label for="username" class="block text-sm font-medium text-gray-700">
            ชื่อผู้ใช้ (Username) <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="username"
              v-model="form.username"
              type="text"
              autocomplete="off"
              required
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.username,
              }"
              placeholder="เช่น s.somchai หรือ admin_sec"
            />
          </div>
          <p v-if="fieldErrors.username" class="mt-1 text-xs text-red-600">
            {{
              Array.isArray(fieldErrors.username) ? fieldErrors.username[0] : fieldErrors.username
            }}
          </p>
        </div>

        <!-- Email -->
        <div>
          <label for="email" class="block text-sm font-medium text-gray-700"> อีเมล (Email) </label>
          <div class="mt-1">
            <input
              id="email"
              v-model="form.email"
              type="email"
              autocomplete="off"
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.email,
              }"
              placeholder="user@campus.local"
            />
          </div>
          <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-600">
            {{ Array.isArray(fieldErrors.email) ? fieldErrors.email[0] : fieldErrors.email }}
          </p>
        </div>

        <!-- Initial Password -->
        <div>
          <label for="password" class="block text-sm font-medium text-gray-700">
            รหัสผ่านเริ่มต้น <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              required
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.password,
              }"
              placeholder="••••••••"
            />
          </div>
          <p v-if="fieldErrors.password" class="mt-1 text-xs text-red-600">
            {{
              Array.isArray(fieldErrors.password) ? fieldErrors.password[0] : fieldErrors.password
            }}
          </p>
          <p class="mt-1 text-xs text-gray-500">
            ความยาวอย่างน้อย 8 ตัวอักษร ประกอบด้วยพิมพ์ใหญ่ พิมพ์เล็ก ตัวเลข และอักขระพิเศษ
          </p>
        </div>

        <!-- Status Selection -->
        <div>
          <label for="status" class="block text-sm font-medium text-gray-700">
            สถานะเริ่มต้น
          </label>
          <div class="mt-1">
            <select
              id="status"
              v-model="form.status"
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 py-2 pl-3 pr-10 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50"
            >
              <option value="ACTIVE">ใช้งานปกติ (ACTIVE)</option>
              <option value="LOCKED">ถูกล็อก (LOCKED)</option>
              <option value="DISABLED">ปิดใช้งาน (DISABLED)</option>
              <option value="EXPIRED">หมดอายุ (EXPIRED)</option>
            </select>
          </div>
        </div>

        <!-- Must Change Password Checkbox -->
        <div class="relative flex items-start">
          <div class="flex h-5 items-center">
            <input
              id="must_change_password"
              v-model="form.must_change_password"
              type="checkbox"
              :disabled="loading"
              class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
          </div>
          <div class="ml-3 text-sm">
            <label for="must_change_password" class="font-medium text-gray-700">
              บังคับให้เปลี่ยนรหัสผ่านในการเข้าสู่ระบบครั้งแรก
            </label>
            <p class="text-xs text-gray-500">
              เมื่อเปิดใช้งาน ผู้ใช้จะต้องตั้งรหัสผ่านใหม่ทันทีก่อนเข้าถึงฟังก์ชันอื่นๆ ในระบบ
            </p>
          </div>
        </div>

        <!-- Submit & Cancel Buttons -->
        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
          <router-link
            to="/users"
            class="rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
          >
            ยกเลิก
          </router-link>
          <button
            type="submit"
            :disabled="loading"
            class="inline-flex justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-60 transition duration-150"
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
            <span>{{ loading ? 'กำลังบันทึก...' : 'บันทึกผู้ใช้งาน' }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
