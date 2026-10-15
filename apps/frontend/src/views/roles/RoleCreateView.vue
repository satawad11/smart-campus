<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { rolesService } from '@/services/roles'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()

const form = reactive({
  code: '',
  name: '',
  description: '',
  status: 'ACTIVE',
})

const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

function handleCodeInput(e) {
  // Normalize code to uppercase as the user types
  form.code = e.target.value.toUpperCase().replace(/\s+/g, '_')
}

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!form.code.trim()) {
    fieldErrors.value.code = 'กรุณากรอกรหัสบทบาท (Code)'
  } else if (!/^[A-Z][A-Z0-9_]*$/.test(form.code.trim())) {
    fieldErrors.value.code =
      'รหัสบทบาทต้องขึ้นต้นด้วยตัวอักษรภาษาอังกฤษพิมพ์ใหญ่ และประกอบด้วย A-Z, 0-9 หรือ _ เท่านั้น'
  }

  if (!form.name.trim()) {
    fieldErrors.value.name = 'กรุณากรอกชื่อบทบาท'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  loading.value = true
  try {
    const payload = {
      code: form.code.trim(),
      name: form.name.trim(),
      status: form.status,
    }
    if (form.description.trim()) {
      payload.description = form.description.trim()
    }

    const created = await rolesService.create(payload)

    router.push({
      path: `/roles/${created.uuid}/permissions`,
      query: { created: 'true' },
    })
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = apiErrorText(err, 'เกิดข้อผิดพลาดในการสร้างบทบาท กรุณาลองใหม่อีกครั้ง')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/roles" class="hover:text-blue-600"> จัดการบทบาท </router-link>
        <span>/</span>
        <span class="text-gray-900 font-medium">เพิ่มบทบาทใหม่</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">เพิ่มบทบาทใหม่</h1>
      <p class="mt-1 text-sm text-gray-500">
        สร้างบทบาทกำหนดเอง (Custom Role) เพื่อกำหนดกลุ่มสิทธิ์การใช้งานสำหรับบุคลากร
      </p>
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

    <!-- Form Card -->
    <div class="rounded-lg bg-white p-6 shadow">
      <form @submit.prevent="handleSubmit" class="space-y-6" novalidate>
        <!-- Code -->
        <div>
          <label for="code" class="block text-sm font-medium text-gray-700">
            รหัสบทบาท (Role Code) <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="code"
              v-model="form.code"
              @input="handleCodeInput"
              type="text"
              autocomplete="off"
              required
              :disabled="loading"
              class="block w-full font-mono rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50 uppercase"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.code,
              }"
              placeholder="เช่น LAB_INSPECTOR หรือ DORM_STAFF"
            />
          </div>
          <p v-if="fieldErrors.code" class="mt-1 text-xs text-red-600">
            {{ Array.isArray(fieldErrors.code) ? fieldErrors.code[0] : fieldErrors.code }}
          </p>
          <p class="mt-1 text-xs text-gray-500">
            ใช้ตัวอักษรภาษาอังกฤษพิมพ์ใหญ่ (A-Z), ตัวเลข (0-9) และขีดล่าง (_) เท่านั้น
            และไม่สามารถแก้ไขได้ในภายหลัง
          </p>
        </div>

        <!-- Name -->
        <div>
          <label for="name" class="block text-sm font-medium text-gray-700">
            ชื่อบทบาท (Role Name) <span class="text-red-500">*</span>
          </label>
          <div class="mt-1">
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              :class="{
                'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.name,
              }"
              placeholder="เช่น เจ้าหน้าที่ตรวจสอบห้องปฏิบัติการ"
            />
          </div>
          <p v-if="fieldErrors.name" class="mt-1 text-xs text-red-600">
            {{ Array.isArray(fieldErrors.name) ? fieldErrors.name[0] : fieldErrors.name }}
          </p>
        </div>

        <!-- Description -->
        <div>
          <label for="description" class="block text-sm font-medium text-gray-700">
            คำอธิบายบทบาท
          </label>
          <div class="mt-1">
            <textarea
              id="description"
              v-model="form.description"
              rows="3"
              :disabled="loading"
              class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              placeholder="ระบุหน้าที่ความรับผิดชอบหรือขอบเขตสิทธิ์ของบทบาทนี้..."
            ></textarea>
          </div>
        </div>

        <!-- Status -->
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
              <option value="DISABLED">ปิดใช้งาน (DISABLED)</option>
            </select>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end space-x-3 pt-4 border-t border-gray-200">
          <router-link
            to="/roles"
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
            <span>{{ loading ? 'กำลังบันทึก...' : 'สร้างและกำหนดสิทธิ์' }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
