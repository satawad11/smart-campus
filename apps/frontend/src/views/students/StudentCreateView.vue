<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { studentsService } from '@/services/students'
import { academicsService } from '@/services/academics'
import { apiErrorText } from '@/constants/labels'

const router = useRouter()

// Form Data
const form = reactive({
  person: {
    first_name: '',
    last_name: '',
    display_name: '',
    person_code: '',
    gender: '',
  },
  student_code: '',
  department_uuid: '',
  program_uuid: '',
  classroom_uuid: '',
  education_level: 'VOC_CERT',
  year_level: 1,
  admission_year: new Date().getFullYear() + 543, // Default to current Buddhist era year
  graduation_year: '',
})

// Lookup State
const departments = ref([])
const programs = ref([])
const classrooms = ref([])
const loadingMasters = ref(false)

const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

async function loadDepartments() {
  loadingMasters.value = true
  try {
    const depts = await academicsService.listDepartments({ per_page: 100, status: 'ACTIVE' })
    departments.value = depts || []
  } catch {
    // Graceful error if master data is empty
  } finally {
    loadingMasters.value = false
  }
}

async function handleDepartmentChange() {
  form.program_uuid = ''
  form.classroom_uuid = ''
  programs.value = []
  classrooms.value = []

  if (form.department_uuid) {
    try {
      const [progs, classes] = await Promise.all([
        academicsService.listPrograms({
          department_uuid: form.department_uuid,
          per_page: 100,
          status: 'ACTIVE',
        }),
        academicsService.listClassrooms({
          department_uuid: form.department_uuid,
          per_page: 100,
          status: 'ACTIVE',
        }),
      ])
      programs.value = progs || []
      classrooms.value = classes || []
    } catch {
      // ignore
    }
  }
}

async function handleProgramChange() {
  form.classroom_uuid = ''
  if (form.program_uuid) {
    try {
      const classes = await academicsService.listClassrooms({
        program_uuid: form.program_uuid,
        department_uuid: form.department_uuid || undefined,
        per_page: 100,
        status: 'ACTIVE',
      })
      classrooms.value = classes || []
    } catch {
      // ignore
    }
  } else if (form.department_uuid) {
    try {
      const classes = await academicsService.listClassrooms({
        department_uuid: form.department_uuid,
        per_page: 100,
        status: 'ACTIVE',
      })
      classrooms.value = classes || []
    } catch {
      // ignore
    }
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
  if (!form.student_code.trim()) {
    errors.student_code = 'กรุณากรอกรหัสนักเรียน'
  }
  if (!form.department_uuid) {
    errors.department_uuid = 'กรุณาเลือกแผนกวิชา'
  }
  if (!form.education_level) {
    errors.education_level = 'กรุณาเลือกระดับการศึกษา'
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
      },
      student_code: form.student_code.trim(),
      department_uuid: form.department_uuid,
      education_level: form.education_level,
      year_level: parseInt(form.year_level, 10),
    }

    if (form.person.display_name.trim()) {
      payload.person.display_name = form.person.display_name.trim()
    }
    if (form.person.person_code.trim()) {
      payload.person.person_code = form.person.person_code.trim()
    }
    if (form.person.gender) {
      payload.person.gender = form.person.gender
    }
    if (form.program_uuid) {
      payload.program_uuid = form.program_uuid
    }
    if (form.classroom_uuid) {
      payload.classroom_uuid = form.classroom_uuid
    }
    if (form.admission_year) {
      payload.admission_year = parseInt(form.admission_year, 10)
    }
    if (form.graduation_year) {
      payload.graduation_year = parseInt(form.graduation_year, 10)
    }

    const created = await studentsService.create(payload)

    router.push({
      path: `/students/${created.uuid}`,
      query: { created: 'true' },
    })
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = apiErrorText(err, 'เกิดข้อผิดพลาดในการบันทึกข้อมูลนักเรียน')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadDepartments()
})
</script>

<template>
  <div class="max-w-4xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/students" class="hover:text-blue-600">จัดการข้อมูลนักเรียน</router-link>
        <span>/</span>
        <span>เพิ่มนักเรียนใหม่</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">เพิ่มนักเรียนใหม่</h1>
      <p class="mt-1 text-sm text-gray-500">
        กรอกข้อมูลส่วนบุคคลและข้อมูลการศึกษาเพื่อสร้างระเบียนนักเรียนในระบบ
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

    <form @submit.prevent="handleSubmit" class="space-y-6" novalidate>
      <!-- Section 1: ข้อมูลส่วนบุคคล (Identity) -->
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="border-b border-gray-200 pb-4 mb-6">
          <h2 class="text-base font-semibold text-gray-900">1. ข้อมูลส่วนบุคคล (Identity)</h2>
          <p class="mt-1 text-xs text-gray-500">
            ข้อมูลประจำตัวของบุคคล (Person) ที่ผูกกับระเบียนนักเรียน
          </p>
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
                placeholder="เช่น สมชาย"
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
                placeholder="เช่น สุขใจ"
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
                placeholder="เช่น ชาย หรือ Somchai S."
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
              />
            </div>
          </div>

          <!-- National ID / Person Code -->
          <div>
            <label for="person_code" class="block text-sm font-medium text-gray-700">
              เลขประจำตัวประชาชน (National ID)
            </label>
            <div class="mt-1">
              <input
                id="person_code"
                v-model="form.person.person_code"
                type="text"
                :disabled="loading"
                placeholder="เลขบัตรประจำตัวประชาชน 13 หลัก"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors['person.person_code'],
                }"
              />
            </div>
            <p v-if="fieldErrors['person.person_code']" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors['person.person_code'])
                  ? fieldErrors['person.person_code'][0]
                  : fieldErrors['person.person_code']
              }}
            </p>
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
        </div>
      </div>

      <!-- Section 2: ข้อมูลการศึกษา (Academic Profile) -->
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="border-b border-gray-200 pb-4 mb-6">
          <h2 class="text-base font-semibold text-gray-900">
            2. ข้อมูลการศึกษา (Academic Profile)
          </h2>
          <p class="mt-1 text-xs text-gray-500">รหัสนักเรียน โครงสร้างการศึกษา และชั้นปี</p>
        </div>

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
          <!-- Student Code -->
          <div>
            <label for="student_code" class="block text-sm font-medium text-gray-700">
              รหัสนักเรียน (Student Code) <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <input
                id="student_code"
                v-model="form.student_code"
                type="text"
                required
                :disabled="loading"
                placeholder="เช่น 66209010001"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm font-mono disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors.student_code,
                }"
              />
            </div>
            <p v-if="fieldErrors.student_code" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.student_code)
                  ? fieldErrors.student_code[0]
                  : fieldErrors.student_code
              }}
            </p>
          </div>

          <!-- Education Level -->
          <div>
            <label for="education_level" class="block text-sm font-medium text-gray-700">
              ระดับการศึกษา <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <select
                id="education_level"
                v-model="form.education_level"
                required
                :disabled="loading"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors.education_level,
                }"
              >
                <option value="VOC_CERT">ประกาศนียบัตรวิชาชีพ (ปวช.)</option>
                <option value="HIGH_VOC_CERT">ประกาศนียบัตรวิชาชีพชั้นสูง (ปวส.)</option>
                <option value="SHORT_COURSE">หลักสูตรระยะสั้น</option>
                <option value="OTHER">อื่นๆ</option>
              </select>
            </div>
            <p v-if="fieldErrors.education_level" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.education_level)
                  ? fieldErrors.education_level[0]
                  : fieldErrors.education_level
              }}
            </p>
          </div>

          <!-- Department -->
          <div>
            <label for="department_uuid" class="block text-sm font-medium text-gray-700">
              แผนกวิชา <span class="text-red-500">*</span>
            </label>
            <div class="mt-1">
              <select
                id="department_uuid"
                v-model="form.department_uuid"
                @change="handleDepartmentChange"
                required
                :disabled="loading || loadingMasters"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-50"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors.department_uuid,
                }"
              >
                <option value="">-- เลือกแผนกวิชา --</option>
                <option v-for="d in departments" :key="d.uuid" :value="d.uuid">
                  {{ d.name }} ({{ d.code }})
                </option>
              </select>
            </div>
            <p v-if="fieldErrors.department_uuid" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.department_uuid)
                  ? fieldErrors.department_uuid[0]
                  : fieldErrors.department_uuid
              }}
            </p>
          </div>

          <!-- Program -->
          <div>
            <label for="program_uuid" class="block text-sm font-medium text-gray-700">
              สาขาวิชา (Program)
            </label>
            <div class="mt-1">
              <select
                id="program_uuid"
                v-model="form.program_uuid"
                @change="handleProgramChange"
                :disabled="loading || !form.department_uuid"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-100 disabled:text-gray-400"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors.program_uuid,
                }"
              >
                <option value="">-- ไม่ระบุสาขาวิชา --</option>
                <option v-for="p in programs" :key="p.uuid" :value="p.uuid">
                  {{ p.name }} ({{ p.code }})
                </option>
              </select>
            </div>
            <p v-if="fieldErrors.program_uuid" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.program_uuid)
                  ? fieldErrors.program_uuid[0]
                  : fieldErrors.program_uuid
              }}
            </p>
          </div>

          <!-- Classroom -->
          <div>
            <label for="classroom_uuid" class="block text-sm font-medium text-gray-700">
              ห้องเรียน (Classroom)
            </label>
            <div class="mt-1">
              <select
                id="classroom_uuid"
                v-model="form.classroom_uuid"
                :disabled="loading || !form.department_uuid"
                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:text-sm disabled:bg-gray-100 disabled:text-gray-400"
                :class="{
                  'border-red-300 focus:border-red-500 focus:ring-red-500':
                    fieldErrors.classroom_uuid,
                }"
              >
                <option value="">-- ไม่ระบุห้องเรียน --</option>
                <option v-for="c in classrooms" :key="c.uuid" :value="c.uuid">
                  {{ c.name }} ({{ c.code }})
                </option>
              </select>
            </div>
            <p v-if="fieldErrors.classroom_uuid" class="mt-1 text-xs text-red-600">
              {{
                Array.isArray(fieldErrors.classroom_uuid)
                  ? fieldErrors.classroom_uuid[0]
                  : fieldErrors.classroom_uuid
              }}
            </p>
          </div>

          <!-- Year Level -->
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

          <!-- Admission Year -->
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

          <!-- Expected Graduation Year -->
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

        <!-- Academic Status Notice -->
        <div class="mt-6 rounded-md bg-blue-50 p-4 border border-blue-100">
          <div class="flex">
            <svg
              class="h-5 w-5 text-blue-500 mr-2 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            <div class="text-xs text-blue-700 leading-relaxed">
              <strong>ข้อสังเกต:</strong> ระเบียนนักเรียนที่สร้างใหม่จะมีสถานะการศึกษาเริ่มต้นเป็น
              <strong>"กำลังศึกษา (ACTIVE)"</strong> โดยอัตโนมัติ การปรับเปลี่ยนสถานะการศึกษา (เช่น
              ลาพัก พักการเรียน ลาออก) จะดำเนินการผ่านขั้นตอนบันทึกประวัติสถานะโดยเฉพาะ
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex items-center justify-end space-x-3">
        <router-link
          to="/students"
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
          {{ loading ? 'กำลังบันทึก...' : 'บันทึกข้อมูลนักเรียน' }}
        </button>
      </div>
    </form>
  </div>
</template>
