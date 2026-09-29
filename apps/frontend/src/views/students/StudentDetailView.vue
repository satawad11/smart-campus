<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { studentsService } from '@/services/students'
import AcademicStatusBadge from '@/components/students/AcademicStatusBadge.vue'
import EducationLevelBadge from '@/components/students/EducationLevelBadge.vue'
import ChangeStatusModal from '@/components/students/ChangeStatusModal.vue'
import StatusHistoryTimeline from '@/components/students/StatusHistoryTimeline.vue'
import PersonEventTimeline from '@/components/students/PersonEventTimeline.vue'

const route = useRoute()
const authStore = useAuthStore()

const student = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')
const showStatusModal = ref(false)
const historyTimelineRef = ref(null)

const errorTranslations = {
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการเข้าถึงข้อมูลนักเรียนนี้',
  STUDENT_NOT_FOUND: 'ไม่พบข้อมูลนักเรียนที่ระบุในระบบ',
  UNAUTHENTICATED: 'กรุณาเข้าสู่ระบบก่อนดำเนินการ',
}

async function fetchStudentDetail() {
  loading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const data = await studentsService.get(uuid)
    student.value = data
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || err.message || 'ไม่สามารถโหลดข้อมูลนักเรียนได้'
  } finally {
    loading.value = false
  }
}

function handleStatusSuccess(updatedStudent) {
  if (student.value && updatedStudent) {
    student.value.academic_status = updatedStudent.academic_status
    if (updatedStudent.status_start_date !== undefined) {
      student.value.status_start_date = updatedStudent.status_start_date
    }
    if (updatedStudent.status_end_date !== undefined) {
      student.value.status_end_date = updatedStudent.status_end_date
    }
    if (updatedStudent.updated_at !== undefined) {
      student.value.updated_at = updatedStudent.updated_at
    }
  }
  successMessage.value = 'เปลี่ยนสถานะการศึกษาเรียบร้อยแล้ว'
  if (historyTimelineRef.value) {
    historyTimelineRef.value.refresh()
  }
}

function formatDate(isoStr) {
  if (!isoStr) return '-'
  try {
    const d = new Date(isoStr)
    return d.toLocaleString('th-TH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return isoStr
  }
}

function formatDateShort(dateStr) {
  if (!dateStr) return '-'
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    })
  } catch {
    return dateStr
  }
}

function getInitials(person) {
  if (!person) return 'ST'
  const first = person.first_name ? person.first_name.charAt(0) : ''
  const last = person.last_name ? person.last_name.charAt(0) : ''
  return (first + last).toUpperCase() || 'ST'
}

function getPersonStatusLabel(status) {
  const map = {
    ACTIVE: 'ใช้งานปกติ (ACTIVE)',
    INACTIVE: 'ไม่ใช้งาน (INACTIVE)',
    ARCHIVED: 'บันทึกประวัติ (ARCHIVED)',
  }
  return map[status] || status || 'ไม่ระบุ'
}

onMounted(() => {
  if (route.query.created === 'true') {
    successMessage.value = 'สร้างระเบียนนักเรียนใหม่เรียบร้อยแล้ว'
  } else if (route.query.updated === 'true') {
    successMessage.value = 'อัปเดตข้อมูลนักเรียนเรียบร้อยแล้ว'
  }
  fetchStudentDetail()
})
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
          <router-link to="/students" class="hover:text-blue-600">จัดการข้อมูลนักเรียน</router-link>
          <span>/</span>
          <span class="text-gray-900 font-medium">
            {{
              student
                ? `${student.person?.first_name} ${student.person?.last_name}`
                : 'รายละเอียดนักเรียน'
            }}
          </span>
        </div>
        <h1 class="text-2xl font-bold text-gray-900">
          {{
            student
              ? `${student.person?.first_name} ${student.person?.last_name}`
              : 'รายละเอียดนักเรียน'
          }}
        </h1>
      </div>

      <!-- Action Buttons -->
      <div v-if="student" class="mt-4 sm:mt-0 flex flex-wrap items-center gap-3">
        <!-- Change Academic Status Button -->
        <button
          v-if="authStore.can('student.status.change')"
          type="button"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          @click="showStatusModal = true"
        >
          <svg
            class="-ml-1 mr-2 h-4 w-4 text-blue-600"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
            />
          </svg>
          เปลี่ยนสถานะการศึกษา
        </button>

        <!-- Edit Profile Button -->
        <router-link
          v-if="authStore.can('student.update')"
          :to="`/students/${student.uuid}/edit`"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <svg
            class="-ml-1 mr-2 h-4 w-4 text-gray-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
            />
          </svg>
          แก้ไขข้อมูล
        </router-link>
      </div>
    </div>

    <!-- Success Feedback Banner -->
    <div
      v-if="successMessage"
      class="rounded-md bg-green-50 p-4 text-sm text-green-800 border border-green-200"
      role="alert"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-green-500 mr-2 shrink-0"
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
        <span>{{ successMessage }}</span>
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

    <!-- Loading State -->
    <div v-if="loading" class="rounded-lg bg-white p-12 text-center shadow">
      <svg class="mx-auto h-8 w-8 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        />
      </svg>
      <p class="mt-3 text-sm text-gray-500">กำลังโหลดรายละเอียดนักเรียน...</p>
    </div>

    <!-- Detail Content -->
    <div v-else-if="student" class="space-y-6">
      <!-- Profile Header Summary Card -->
      <div class="rounded-lg bg-white p-6 shadow">
        <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-6">
          <div
            class="h-20 w-20 shrink-0 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-2xl border-2 border-blue-200 shadow-sm mb-4 sm:mb-0"
          >
            {{ getInitials(student.person) }}
          </div>
          <div class="flex-1">
            <div class="flex flex-wrap items-center gap-3">
              <h2 class="text-xl font-bold text-gray-900">
                {{ student.person?.first_name }} {{ student.person?.last_name }}
              </h2>
              <AcademicStatusBadge :status="student.academic_status" size="lg" />
              <EducationLevelBadge :level="student.education_level" />
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
              <div>
                รหัสนักเรียน:
                <span class="font-mono font-semibold text-gray-900">{{
                  student.student_code
                }}</span>
              </div>
              <div v-if="student.person?.display_name">
                ชื่อที่แสดง: <span class="text-gray-900">{{ student.person.display_name }}</span>
              </div>
              <div>
                แผนกวิชา: <span class="text-gray-900">{{ student.department?.name || '-' }}</span>
              </div>
              <div>
                ชั้นปี: <span class="text-gray-900">ปีที่ {{ student.year_level ?? '-' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Card 1: ข้อมูลส่วนบุคคล (Identity) -->
        <div class="rounded-lg bg-white p-6 shadow">
          <h3 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
            ข้อมูลส่วนบุคคล (Identity)
          </h3>
          <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2 text-sm">
            <div>
              <dt class="text-xs font-medium text-gray-500">ชื่อ</dt>
              <dd class="mt-1 text-gray-900 font-medium">
                {{ student.person?.first_name || '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">นามสกุล</dt>
              <dd class="mt-1 text-gray-900 font-medium">{{ student.person?.last_name || '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ชื่อที่แสดง / ชื่อเล่น</dt>
              <dd class="mt-1 text-gray-900">{{ student.person?.display_name || '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">เลขประจำตัวประชาชน</dt>
              <dd class="mt-1 font-mono text-gray-900">{{ student.person?.person_code || '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ประเภทบุคคล (Person Type)</dt>
              <dd class="mt-1 text-gray-900">{{ student.person?.person_type || 'STUDENT' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">สถานะบุคคล (Person Status)</dt>
              <dd class="mt-1 text-gray-900">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"
                >
                  {{ getPersonStatusLabel(student.person?.status) }}
                </span>
              </dd>
            </div>
          </dl>
        </div>

        <!-- Card 2: ข้อมูลการศึกษา (Academic Information) -->
        <div class="rounded-lg bg-white p-6 shadow">
          <h3 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
            ข้อมูลการศึกษา (Academic Profile)
          </h3>
          <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2 text-sm">
            <div>
              <dt class="text-xs font-medium text-gray-500">รหัสนักเรียน</dt>
              <dd class="mt-1 font-mono font-semibold text-gray-900">{{ student.student_code }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ระดับการศึกษา</dt>
              <dd class="mt-1"><EducationLevelBadge :level="student.education_level" /></dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">แผนกวิชา</dt>
              <dd class="mt-1 text-gray-900 font-medium">
                {{ student.department?.name || '-' }} ({{ student.department?.code || '-' }})
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">สาขาวิชา</dt>
              <dd class="mt-1 text-gray-900">
                {{ student.program ? `${student.program.name} (${student.program.code})` : '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ห้องเรียน</dt>
              <dd class="mt-1 text-gray-900">
                {{
                  student.classroom ? `${student.classroom.name} (${student.classroom.code})` : '-'
                }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ชั้นปี</dt>
              <dd class="mt-1 text-gray-900 font-medium">
                ชั้นปีที่ {{ student.year_level ?? '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ปีที่เข้าศึกษา (พ.ศ.)</dt>
              <dd class="mt-1 text-gray-900">{{ student.admission_year || '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ปีที่คาดว่าจะจบ (พ.ศ.)</dt>
              <dd class="mt-1 text-gray-900">{{ student.graduation_year || '-' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">สถานะการศึกษาปัจจุบัน</dt>
              <dd class="mt-1"><AcademicStatusBadge :status="student.academic_status" /></dd>
            </div>
            <div>
              <dt class="text-xs font-medium text-gray-500">ช่วงเวลาสถานะ</dt>
              <dd class="mt-1 text-gray-700 text-xs">
                {{ formatDateShort(student.status_start_date) }} -
                {{
                  student.status_end_date ? formatDateShort(student.status_end_date) : 'ปัจจุบัน'
                }}
              </dd>
            </div>
          </dl>
        </div>
      </div>

      <!-- Card 3: ข้อมูลระบบ (System Metadata) -->
      <div class="rounded-lg bg-white p-6 shadow">
        <h3 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
          ข้อมูลระบบ (System Info)
        </h3>
        <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-3 text-sm">
          <div>
            <dt class="text-xs font-medium text-gray-500">รหัสอ้างอิงสาธารณะ (UUID)</dt>
            <dd class="mt-1 font-mono text-xs text-gray-600 break-all">{{ student.uuid }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500">วันที่บันทึกข้อมูล</dt>
            <dd class="mt-1 text-xs text-gray-700">{{ formatDate(student.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500">วันที่แก้ไขล่าสุด</dt>
            <dd class="mt-1 text-xs text-gray-700">{{ formatDate(student.updated_at) }}</dd>
          </div>
        </dl>
      </div>

      <!-- Card 4: ประวัติการเปลี่ยนสถานะการศึกษา (Academic Status History) -->
      <StatusHistoryTimeline ref="historyTimelineRef" :student-uuid="student.uuid" />

      <!-- Card 5: ประวัติการรับรู้ใบหน้า (Event Timeline, EVT-019) -->
      <PersonEventTimeline v-if="student.person?.uuid" :person-uuid="student.person.uuid" />

      <!-- Change Status Modal -->
      <ChangeStatusModal
        :show="showStatusModal"
        :student="student"
        @close="showStatusModal = false"
        @success="handleStatusSuccess"
      />
    </div>
  </div>
</template>
