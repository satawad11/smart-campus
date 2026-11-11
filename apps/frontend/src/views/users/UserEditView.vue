<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usersService } from '@/services/users'
import { apiErrorText } from '@/constants/labels'

const route = useRoute()
const router = useRouter()

const user = ref(null)
const initialLoading = ref(true)
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const form = reactive({
  username: '',
  email: '',
})

async function fetchUser() {
  initialLoading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await usersService.get(uuid)
    user.value = data
    form.username = data.username || ''
    form.email = data.email || ''
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ไม่สามารถโหลดข้อมูลผู้ใช้งานได้')
  } finally {
    initialLoading.value = false
  }
}

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!form.username.trim()) {
    fieldErrors.value.username = 'กรุณากรอกชื่อผู้ใช้'
    return
  }

  loading.value = true
  try {
    const payload = {
      username: form.username.trim(),
    }
    if (form.email.trim()) {
      payload.email = form.email.trim()
    } else {
      payload.email = null
    }

    await usersService.update(route.params.uuid, payload)
    router.push({
      path: `/users/${route.params.uuid}`,
    })
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = apiErrorText(err, 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchUser()
})
</script>

<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/users" class="hover:text-blue-600">จัดการผู้ใช้งาน</router-link>
        <span>/</span>
        <router-link :to="`/users/${route.params.uuid}`" class="hover:text-blue-600">
          {{ user?.username || route.params.uuid }}
        </router-link>
        <span>/</span>
        <span class="text-gray-900 font-medium">แก้ไข</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">แก้ไขข้อมูลผู้ใช้งาน</h1>
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
    <div v-if="initialLoading" class="rounded-lg bg-surface p-8 text-center text-gray-500 shadow">
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

    <!-- Edit Form Card -->
    <div v-else-if="user" class="rounded-lg bg-surface p-6 shadow">
      <form @submit.prevent="handleSubmit" class="space-y-6" novalidate>
        <!-- UUID (Read-only reference) -->
        <div>
          <label class="block text-xs font-medium text-gray-500">
            รหัสประจำตัวสาธารณะ (UUID)
          </label>
          <div
            class="mt-1 font-mono text-sm text-gray-700 bg-gray-50 p-2 rounded border border-gray-200"
          >
            {{ user.uuid }}
          </div>
        </div>

        <!-- Username -->
        <div>
          <label for="edit-username" class="block text-sm font-medium text-gray-700">
            ชื่อผู้ใช้ (Username) <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="edit-username"
              v-model="form.username"
              type="text"
              required
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.username,
              }"
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
          <label for="edit-email" class="block text-sm font-medium text-gray-700">
            อีเมล (Email)
          </label>
          <div class="mt-1">
            <input
              id="edit-email"
              v-model="form.email"
              type="email"
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.email,
              }"
            />
          </div>
          <p v-if="fieldErrors.email" class="mt-1 text-xs text-red-600">
            {{ Array.isArray(fieldErrors.email) ? fieldErrors.email[0] : fieldErrors.email }}
          </p>
        </div>

        <!-- Non-editable Security Notice -->
        <div class="rounded-md bg-gray-50 p-4 text-xs text-gray-500 border border-gray-200">
          <p class="font-medium text-gray-700">ข้อจำกัดความปลอดภัยในการแก้ไข:</p>
          <p class="mt-0.5">
            รหัสผ่าน สถานะบัญชี และการกำหนดสิทธิ์/บทบาท ไม่สามารถแก้ไขได้ผ่านฟอร์มนี้
            (โปรดใช้เมนูเปลี่ยนสถานะ หรือรีเซ็ตรหัสผ่านเฉพาะด้าน)
          </p>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
          <router-link
            :to="`/users/${route.params.uuid}`"
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
            <span>{{ loading ? 'กำลังบันทึก...' : 'บันทึกการเปลี่ยนแปลง' }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
