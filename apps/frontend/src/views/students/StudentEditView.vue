<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { studentsService } from '@/services/students'
import AcademicStatusBadge from '@/components/students/AcademicStatusBadge.vue'
import EducationLevelBadge from '@/components/students/EducationLevelBadge.vue'
import { apiErrorText } from '@/constants/labels'

const route = useRoute()
const router = useRouter()

const student = ref(null)
const loadingStudent = ref(true)
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const form = reactive({
  person: {
    first_name: '',
    last_name: '',
    display_name: '',
    gender: '',
  },
  year_level: 1,
  admission_year: '',
  graduation_year: '',
})

const errorTranslations = {
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการแก้ไขข้อมูลนักเรียนนี้',
  STUDENT_NOT_FOUND: 'ไม่พบข้อมูลนักเรียนที่ระบุในระบบ',
}

async function fetchStudent() {
  loadingStudent.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await studentsService.get(uuid)
    student.value = data

    // Populate editable fields
    form.person.first_name = data.person?.first_name || ''
    form.person.last_name = data.person?.last_name || ''
    form.person.display_name = data.person?.display_name || ''
    form.person.gender = data.person?.gender || ''
    form.year_level = data.year_level || 1
    form.admission_year = data.admission_year || ''
    form.graduation_year = data.graduation_year || ''
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'ไม่สามารถโหลดข้อมูลนักเรียนได้')
  } finally {
    loadingStudent.value = false
  }
}

function validateClient() {
  const errors = {}

  if (!form.person.first_name.trim()) {
    errors['person.first_name'] = 'กรุณากรอกชื่อ'
  }
  if (!form.person.last_name.trim()) {
    errors['person.last_name'] = 'กรุณากรอกนามสกุล'
  }
  if (!form.year_level || form.year_level < 1) {
    errors.year_level = 'กรุณาระบุชั้นปีที่ถูกต้อง (ตั้งแต่ 1 ขึ้นไป)'
  }

  return errors
}

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  const errors = validateClient()
  if (Object.keys(errors).length > 0) {
    fieldErrors.value = errors
    return
  }

  loading.value = true
  try {
    const payload = {
      person: {
        first_name: form.person.first_name.trim(),
        last_name: form.person.last_name.trim(),
        display_name: form.person.display_name.trim() || null,
        gender: form.person.gender || null,
      },
      year_level: parseInt(form.year_level, 10),
      admission_year: form.admission_year ? parseInt(form.admission_year, 10) : null,
      graduation_year: form.graduation_year ? parseInt(form.graduation_year, 10) : null,
    }

    await studentsService.update(student.value.uuid, payload)

    router.push({
      path: `/students/${student.value.uuid}`,
      query: { updated: 'true' },
    })
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = apiErrorText(err, 'เกิดข้อผิดพลาดในการอัปเดตข้อมูลนักเรียน')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchStudent()
})
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/students" class="hover:text-blue-600">จัดการข้อมูลนักเรียน</router-link>
        <span>/</span>
        <router-link v-if="student" :to="`/students/${student.uuid}`" class="hover:text-blue-600">
          {{ student.person?.first_name }} {{ student.person?.last_name }}
        </router-link>
        <span>/</span>
        <span>แก้ไขข้อมูล</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">
        แก้ไขข้อมูลนักเรียน:
        {{ student ? `${student.person?.first_name} ${student.person?.last_name}` : '' }}
      </h1>
      <p class="mt-1 text-sm text-gray-500">แก้ไขข้อมูลส่วนบุคคลและข้อมูลชั้นปีที่ได้รับอนุญาต</p>
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
    <div v-if="loadingStudent" class="rounded-lg bg-white p-12 text-center shadow">
      <svg class="mx-auto h-8 w-8 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        />
      </svg>
      <p class="mt-3 text-sm text-gray-500">กำลังโหลดข้อมูลนักเรียน...</p>
    </div>

    <!-- Edit Form -->
    <form v-else-if="student" @submit.prevent="handleSubmit" class="space-y-6" novalidate>
      <!-- Section 1: ข้อมูลส่วนบุคคล (Editable) -->
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="border-b border-gray-200 pb-4 mb-6">
          <h2 class="text-base font-semibold text-gray-900">1. ข้อมูลส่วนบุคคล</h2>
          <p class="mt-1 text-xs text-gray-500">ชื่อ นามสกุล และข้อมูลการแสดงผลของนักเรียน</p>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <!-- First Name -->
          <div>
            <label for="first_name" class="block text-sm font-medium text-gray-700">
              ชื่อ <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <input
                id="first_name"
                v-model="form.person.first_name"
                type="text"
                required
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors['person.first_name'],
                }"
              />
            </div>
            <p v-if="fieldErrors['person.first_name']" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors['person.first_name'])
                  ? fieldErrors['person.first_name'][0]
                  : fieldErrors['person.first_name']
              }}
            </p>
          </div>

          <!-- Last Name -->
          <div>
            <label for="last_name" class="block text-sm font-medium text-gray-700">
              นามสกุล <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <input
                id="last_name"
                v-model="form.person.last_name"
                type="text"
                required
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors['person.last_name'],
                }"
              />
            </div>
            <p v-if="fieldErrors['person.last_name']" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors['person.last_name'])
                  ? fieldErrors['person.last_name'][0]
                  : fieldErrors['person.last_name']
              }}
            </p>
          </div>

          <!-- Display Name -->
          <div>
            <label for="display_name" class="block text-sm font-medium text-gray-700">
              ชื่อที่แสดง / ชื่อเล่น
            </label>
            <div class="mt-1">
              <input
                id="display_name"
                v-model="form.person.display_name"
                type="text"
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              />
            </div>
          </div>

          <!-- Gender -->
          <div>
            <label for="gender" class="block text-sm font-medium text-gray-700"> เพศ </label>
            <div class="mt-1">
              <select
                id="gender"
                v-model="form.person.gender"
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              >
                <option value="">ไม่ระบุ</option>
                <option value="MALE">ชาย (Male)</option>
                <option value="FEMALE">หญิง (Female)</option>
                <option value="OTHER">อื่นๆ (Other)</option>
              </select>
            </div>
          </div>

          <!-- Read-only Identity Attributes -->
          <div>
            <label class="block text-sm font-medium text-gray-500">
              เลขประจำตัวประชาชน (National ID)
            </label>
            <div class="mt-1">
              <input
                :value="student.person?.person_code || '-'"
                type="text"
                disabled
                class="block w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm font-mono cursor-not-allowed"
              />
            </div>
            <p class="mt-1 text-xs text-gray-400">เลขประจำตัวประชาชนไม่สามารถแก้ไขผ่านหน้านี้ได้</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-500">
              สถานะบุคคล (Person Status)
            </label>
            <div class="mt-1">
              <input
                :value="student.person?.status || 'ACTIVE'"
                type="text"
                disabled
                class="block w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm cursor-not-allowed"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Section 2: ข้อมูลการศึกษา (Editable & Read-Only Context) -->
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="border-b border-gray-200 pb-4 mb-6">
          <h2 class="text-base font-semibold text-gray-900">2. ข้อมูลการศึกษา</h2>
          <p class="mt-1 text-xs text-gray-500">
            ชั้นปีและปีการศึกษา (รหัสนักเรียน โครงสร้างแผนก และสถานะการศึกษาเป็นข้อมูลที่ถูกควบคุม)
          </p>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <!-- Student Code (Read-Only) -->
          <div>
            <label class="block text-sm font-medium text-gray-500">
              รหัสนักเรียน (Student Code)
            </label>
            <div class="mt-1">
              <input
                :value="student.student_code"
                type="text"
                disabled
                class="block w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm font-mono cursor-not-allowed"
              />
            </div>
            <p class="mt-1 text-xs text-gray-400">
              รหัสนักเรียนเป็นคีย์ระบุตัวตนที่ไม่สามารถแก้ไขได้
            </p>
          </div>

          <!-- Academic Status (Read-Only / Badge) -->
          <div>
            <label class="block text-sm font-medium text-gray-500"> สถานะการศึกษาปัจจุบัน </label>
            <div class="mt-2">
              <AcademicStatusBadge :status="student.academic_status" />
            </div>
            <p class="mt-1 text-xs text-gray-400">
              การเปลี่ยนสถานะการศึกษาดำเนินการผ่านขั้นตอนบันทึกประวัติสถานะ
            </p>
          </div>

          <!-- Department / Program (Read-Only) -->
          <div>
            <label class="block text-sm font-medium text-gray-500"> แผนกวิชา / สาขาวิชา </label>
            <div class="mt-1">
              <input
                :value="`${student.department?.name || '-'} / ${student.program?.name || '-'}`"
                type="text"
                disabled
                class="block w-full rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm cursor-not-allowed"
              />
            </div>
          </div>

          <!-- Classroom (Read-Only) -->
          <div>
            <label class="block text-sm font-medium text-gray-500">
              ห้องเรียน / ระดับการศึกษา
            </label>
            <div class="mt-1 flex items-center space-x-2">
              <input
                :value="student.classroom?.name || '-'"
                type="text"
                disabled
                class="block flex-1 rounded-md border border-gray-200 bg-gray-100 px-3 py-2 text-gray-500 sm:text-sm cursor-not-allowed"
              />
              <EducationLevelBadge :level="student.education_level" />
            </div>
          </div>

          <!-- Year Level (Editable) -->
          <div>
            <label for="year_level" class="block text-sm font-medium text-gray-700">
              ชั้นปี (Year Level) <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <select
                id="year_level"
                v-model="form.year_level"
                required
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500': fieldErrors.year_level,
                }"
              >
                <option :value="1">ชั้นปีที่ 1</option>
                <option :value="2">ชั้นปีที่ 2</option>
                <option :value="3">ชั้นปีที่ 3</option>
                <option :value="4">ชั้นปีที่ 4</option>
              </select>
            </div>
            <p v-if="fieldErrors.year_level" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.year_level)
                  ? fieldErrors.year_level[0]
                  : fieldErrors.year_level
              }}
            </p>
          </div>

          <!-- Admission Year (Editable) -->
          <div>
            <label for="admission_year" class="block text-sm font-medium text-gray-700">
              ปีที่เข้าศึกษา (พ.ศ.)
            </label>
            <div class="mt-1">
              <input
                id="admission_year"
                v-model="form.admission_year"
                type="number"
                :disabled="loading"
                placeholder="เช่น 2567"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              />
            </div>
          </div>

          <!-- Graduation Year (Editable) -->
          <div>
            <label for="graduation_year" class="block text-sm font-medium text-gray-700">
              ปีที่คาดว่าจะจบ (พ.ศ.)
            </label>
            <div class="mt-1">
              <input
                id="graduation_year"
                v-model="form.graduation_year"
                type="number"
                :disabled="loading"
                placeholder="เช่น 2570"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-end space-x-3">
        <router-link
          :to="`/students/${student.uuid}`"
          class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          ยกเลิก
        </router-link>
        <button
          type="submit"
          :disabled="loading"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
        >
          <svg
            v-if="loading"
            class="-ml-1 mr-2 h-4 w-4 animate-spin text-white"
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
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
          {{ loading ? 'กำลังบันทึก...' : 'บันทึกการแก้ไข' }}
        </button>
      </div>
    </form>
  </div>
</template>
