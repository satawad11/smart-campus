<script setup>
import { ref, onMounted, watch } from 'vue'
import { studentImportService } from '@/services/students'
import { apiErrorText } from '@/constants/labels'

const props = defineProps({
  jobUuid: {
    type: String,
    required: true,
  },
})

defineEmits(['back', 'proceed'])

const errorItems = ref([])
const loading = ref(true)
const errorMessage = ref('')
const pagination = ref({
  current_page: 1,
  last_page: 1,
  per_page: 20,
  total: 0,
})

const errorCodeMap = {
  MISSING_REQUIRED_HEADER: 'ขาดคอลัมน์ที่จำเป็น',
  ROW_FIELD_COUNT_MISMATCH: 'จำนวนคอลัมน์ในแถวไม่ถูกต้อง',
  INVALID_STUDENT_CODE: 'รหัสนักเรียนไม่ถูกต้อง',
  INVALID_EDUCATION_LEVEL: 'ระดับการศึกษาไม่ถูกต้อง',
  INVALID_ACADEMIC_STATUS: 'สถานะการศึกษาไม่ถูกต้อง',
  DEPARTMENT_NOT_FOUND: 'ไม่พบแผนกวิชาในระบบ',
  PROGRAM_NOT_FOUND: 'ไม่พบสาขาวิชาในระบบ',
  CLASSROOM_NOT_FOUND: 'ไม่พบห้องเรียนในระบบ',
  PROGRAM_DEPARTMENT_MISMATCH: 'สาขาวิชาไม่อยู่ในแผนกวิชาที่ระบุ',
  CLASSROOM_PROGRAM_MISMATCH: 'ห้องเรียนไม่อยู่ในสาขาวิชาที่ระบุ',
  IDENTITY_MISMATCH: 'ข้อมูลตัวบุคคลไม่ตรงกับรหัสเดิม (Identity Mismatch)',
  PERSON_CODE_CONFLICT: 'เลขประจำตัวบุคคลถูกใช้งานกับบุคคลอื่นแล้ว',
  DUPLICATE_STUDENT_CODE: 'รหัสนักเรียนซ้ำในระบบ',
  DUPLICATE_STUDENT_CODE_IN_FILE: 'รหัสนักเรียนซ้ำในไฟล์เดียวกัน',
  STUDENT_PROFILE_EXISTS: 'มีรหัสนักเรียนนี้ในระบบแล้ว (โหมดสร้างใหม่)',
}

async function fetchErrors(page = 1) {
  if (!props.jobUuid) return
  loading.value = true
  errorMessage.value = ''

  try {
    const res = await studentImportService.errors(props.jobUuid, {
      page,
      per_page: pagination.value.per_page,
    })
    errorItems.value = res.data || []
    if (res.meta && res.meta.pagination) {
      pagination.value = {
        current_page: res.meta.pagination.current_page || page,
        last_page: res.meta.pagination.last_page || 1,
        per_page: res.meta.pagination.per_page || 20,
        total: res.meta.pagination.total || 0,
      }
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ไม่สามารถโหลดรายการข้อผิดพลาดได้')
  } finally {
    loading.value = false
  }
}

function getErrorLabel(code) {
  return errorCodeMap[code] || code || 'ข้อผิดพลาด'
}

watch(
  () => props.jobUuid,
  (newUuid) => {
    if (newUuid) {
      fetchErrors(1)
    }
  },
)

onMounted(() => {
  fetchErrors(1)
})
</script>

<template>
  <div class="space-y-6">
    <div class="rounded-lg bg-white p-6 shadow">
      <div class="flex items-center justify-between border-b border-gray-200 pb-3 mb-4">
        <div>
          <h3 class="text-base font-semibold text-gray-900">
            รายการข้อผิดพลาดจากการตรวจสอบ (Validation Errors)
          </h3>
          <p class="text-xs text-gray-500 mt-0.5">
            แถวที่ไม่ผ่านการตรวจสอบจะไม่ถูกนำเข้าสู่ระบบ
            สามารถดาวน์โหลดไฟล์เดิมเพื่อแก้ไขและนำเข้าใหม่ได้
          </p>
        </div>
        <div class="text-xs text-gray-500">
          พบทั้งหมด
          <span class="font-medium font-mono text-red-600">{{ pagination.total }}</span> ข้อผิดพลาด
        </div>
      </div>

      <!-- Error Alert -->
      <div
        v-if="errorMessage"
        class="mb-4 rounded-md bg-red-50 p-3 text-sm text-red-800 border border-red-200"
        role="alert"
      >
        {{ errorMessage }}
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="py-12 text-center">
        <svg class="mx-auto h-8 w-8 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
          />
        </svg>
        <p class="mt-2 text-xs text-gray-500">กำลังโหลดรายการข้อผิดพลาด...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="errorItems.length === 0" class="py-12 text-center">
        <svg
          class="mx-auto h-12 w-12 text-green-500"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <p class="mt-2 text-sm font-medium text-gray-900">ไม่พบข้อผิดพลาด</p>
        <p class="text-xs text-gray-500 mt-1">ข้อมูลทุกแถวผ่านการตรวจสอบความถูกต้อง</p>
      </div>

      <!-- Errors Table -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
          <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
            <tr>
              <th scope="col" class="px-4 py-3">แถวที่</th>
              <th scope="col" class="px-4 py-3">คอลัมน์/ฟิลด์</th>
              <th scope="col" class="px-4 py-3">ค่าที่พบในไฟล์</th>
              <th scope="col" class="px-4 py-3">ประเภทข้อผิดพลาด</th>
              <th scope="col" class="px-4 py-3">รายละเอียด</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-white">
            <tr v-for="(err, idx) in errorItems" :key="idx" class="hover:bg-red-50/30">
              <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-medium text-gray-900">
                {{ err.row_number }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-600">
                {{ err.field || '-' }}
              </td>
              <td
                class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-800 max-w-xs truncate"
              >
                {{ err.value !== null && err.value !== undefined ? String(err.value) : '-' }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs">
                <span
                  class="inline-flex items-center rounded-md bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-600/20"
                >
                  {{ getErrorLabel(err.error_code) }}
                </span>
              </td>
              <td class="px-4 py-3 text-xs text-red-700">
                {{ err.error_message }}
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination Controls -->
        <div
          v-if="pagination.last_page > 1"
          class="flex items-center justify-between border-t border-gray-200 pt-4 mt-4"
        >
          <div class="text-xs text-gray-500">
            แสดงหน้า
            <span class="font-medium text-gray-900">{{ pagination.current_page }}</span> จาก
            <span class="font-medium text-gray-900">{{ pagination.last_page }}</span>
            (ทั้งหมด {{ pagination.total }} รายการ)
          </div>
          <div class="flex space-x-2">
            <button
              type="button"
              :disabled="pagination.current_page <= 1 || loading"
              class="inline-flex items-center rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
              @click="fetchErrors(pagination.current_page - 1)"
            >
              ย้อนกลับ
            </button>
            <button
              type="button"
              :disabled="pagination.current_page >= pagination.last_page || loading"
              class="inline-flex items-center rounded border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
              @click="fetchErrors(pagination.current_page + 1)"
            >
              ถัดไป
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="flex items-center justify-between">
      <button
        type="button"
        class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        @click="$emit('back')"
      >
        &larr; กลับไปหน้าตรวจสอบ (Back to Preview)
      </button>

      <button
        type="button"
        class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
        @click="$emit('proceed')"
      >
        ดำเนินการต่อไปยังขั้นตอนประมวลผล &rarr;
      </button>
    </div>
  </div>
</template>
