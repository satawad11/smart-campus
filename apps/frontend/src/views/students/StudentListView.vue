<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { studentsService } from '@/services/students'
import { academicsService } from '@/services/academics'
import AcademicStatusBadge from '@/components/students/AcademicStatusBadge.vue'
import EducationLevelBadge from '@/components/students/EducationLevelBadge.vue'
import { apiErrorText } from '@/constants/labels'

const authStore = useAuthStore()

// State
const students = ref([])
const loading = ref(false)
const errorMessage = ref('')

// Master Data Lookup State
const departments = ref([])
const programs = ref([])
const classrooms = ref([])
const loadingMasterData = ref(false)

const filters = reactive({
  keyword: '',
  academic_status: '',
  department_uuid: '',
  program_uuid: '',
  classroom_uuid: '',
  education_level: '',
  year_level: '',
  sort: 'created_at',
  order: 'desc',
  page: 1,
  per_page: 20,
})

const pagination = reactive({
  current_page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

const errorTranslations = {
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการเข้าถึงข้อมูลนักเรียน',
  UNAUTHENTICATED: 'กรุณาเข้าสู่ระบบก่อนดำเนินการ',
  STUDENT_NOT_FOUND: 'ไม่พบข้อมูลนักเรียน',
}

let debounceTimer = null

async function loadMasterData() {
  loadingMasterData.value = true
  try {
    const depts = await academicsService.listDepartments({ per_page: 100, status: 'ACTIVE' })
    departments.value = depts || []
  } catch {
    // If master data fails to load, gracefully continue with empty dropdowns
  } finally {
    loadingMasterData.value = false
  }
}

async function handleDepartmentChange() {
  filters.program_uuid = ''
  filters.classroom_uuid = ''
  programs.value = []
  classrooms.value = []

  if (filters.department_uuid) {
    try {
      const [progs, classes] = await Promise.all([
        academicsService.listPrograms({
          department_uuid: filters.department_uuid,
          per_page: 100,
          status: 'ACTIVE',
        }),
        academicsService.listClassrooms({
          department_uuid: filters.department_uuid,
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

  handleFilterChange()
}

async function handleProgramChange() {
  filters.classroom_uuid = ''
  if (filters.program_uuid) {
    try {
      const classes = await academicsService.listClassrooms({
        program_uuid: filters.program_uuid,
        department_uuid: filters.department_uuid || undefined,
        per_page: 100,
        status: 'ACTIVE',
      })
      classrooms.value = classes || []
    } catch {
      // ignore
    }
  } else if (filters.department_uuid) {
    try {
      const classes = await academicsService.listClassrooms({
        department_uuid: filters.department_uuid,
        per_page: 100,
        status: 'ACTIVE',
      })
      classrooms.value = classes || []
    } catch {
      // ignore
    }
  }
  handleFilterChange()
}

async function fetchStudents() {
  loading.value = true
  errorMessage.value = ''

  try {
    const params = {
      page: filters.page,
      per_page: filters.per_page,
      sort: filters.sort,
      order: filters.order,
    }
    if (filters.keyword.trim()) params.keyword = filters.keyword.trim()
    if (filters.academic_status) params.academic_status = filters.academic_status
    if (filters.department_uuid) params.department_uuid = filters.department_uuid
    if (filters.program_uuid) params.program_uuid = filters.program_uuid
    if (filters.classroom_uuid) params.classroom_uuid = filters.classroom_uuid
    if (filters.education_level) params.education_level = filters.education_level
    if (filters.year_level) params.year_level = parseInt(filters.year_level, 10)

    const res = await studentsService.list(params)
    students.value = res.data || []
    if (res.meta?.pagination) {
      pagination.current_page = res.meta.pagination.current_page
      pagination.per_page = res.meta.pagination.per_page
      pagination.total = res.meta.pagination.total
      pagination.last_page = res.meta.pagination.last_page
    }
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || apiErrorText(err, 'ไม่สามารถโหลดข้อมูลนักเรียนได้')
  } finally {
    loading.value = false
  }
}

function handleSearchInput() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(() => {
    filters.page = 1
    fetchStudents()
  }, 300)
}

function handleFilterChange() {
  filters.page = 1
  fetchStudents()
}

function handleSort(field) {
  if (filters.sort === field) {
    filters.order = filters.order === 'asc' ? 'desc' : 'asc'
  } else {
    filters.sort = field
    filters.order = 'asc'
  }
  filters.page = 1
  fetchStudents()
}

function changePage(page) {
  if (page < 1 || page > pagination.last_page || page === filters.page) return
  filters.page = page
  fetchStudents()
}

function resetFilters() {
  filters.keyword = ''
  filters.academic_status = ''
  filters.department_uuid = ''
  filters.program_uuid = ''
  filters.classroom_uuid = ''
  filters.education_level = ''
  filters.year_level = ''
  filters.sort = 'created_at'
  filters.order = 'desc'
  filters.page = 1
  programs.value = []
  classrooms.value = []
  fetchStudents()
}

function getInitials(person) {
  if (!person) return 'ST'
  const first = person.first_name ? person.first_name.charAt(0) : ''
  const last = person.last_name ? person.last_name.charAt(0) : ''
  return (first + last).toUpperCase() || 'ST'
}

onMounted(() => {
  loadMasterData()
  fetchStudents()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการข้อมูลนักเรียน</h1>
        <p class="mt-1 text-sm text-gray-500">ค้นหา ตรวจสอบ และจัดการข้อมูลนักเรียนในระบบ</p>
      </div>
      <div class="mt-4 sm:mt-0 flex flex-wrap items-center gap-3">
        <router-link
          v-if="authStore.can('student.sync')"
          to="/students/rms-sync"
          class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          ซิงก์จาก RMS
        </router-link>

        <router-link
          v-if="authStore.can('student.import')"
          to="/students/import"
          class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <svg
            class="-ml-1 mr-2 h-5 w-5 text-gray-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
            />
          </svg>
          นำเข้าข้อมูล (CSV)
        </router-link>

        <router-link
          v-if="authStore.can('student.create')"
          to="/students/new"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
        >
          <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 4v16m8-8H4"
            />
          </svg>
          เพิ่มนักเรียนใหม่
        </router-link>
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

    <!-- Filter & Search Card -->
    <div class="rounded-lg bg-surface p-4 shadow">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Search Keyword -->
        <div class="lg:col-span-2">
          <label for="student-search" class="block text-xs font-medium text-gray-700"
            >ค้นหานักเรียน</label
          >
          <div class="relative mt-1">
            <input
              id="student-search"
              v-model="filters.keyword"
              @input="handleSearchInput"
              type="text"
              placeholder="ค้นหาด้วยรหัสนักเรียน ชื่อ นามสกุล หรือชื่อที่แสดง..."
              class="block w-full rounded-md border border-gray-300 px-3 py-2 pl-9 text-sm placeholder-gray-400 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
              <svg
                class="h-4 w-4 text-gray-400"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                />
              </svg>
            </div>
          </div>
        </div>

        <!-- Academic Status Filter -->
        <div>
          <label for="status-filter" class="block text-xs font-medium text-gray-700"
            >สถานะการศึกษา</label
          >
          <select
            id="status-filter"
            v-model="filters.academic_status"
            @change="handleFilterChange"
            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">สถานะทั้งหมด</option>
            <option value="ACTIVE">กำลังศึกษา (ACTIVE)</option>
            <option value="LEAVE">ลาพักการเรียน (LEAVE)</option>
            <option value="SUSPENDED">พักการเรียน (SUSPENDED)</option>
            <option value="WITHDRAWN">ลาออก (WITHDRAWN)</option>
            <option value="GRADUATED">สำเร็จการศึกษา (GRADUATED)</option>
            <option value="EXPELLED">พ้นสภาพ (EXPELLED)</option>
            <option value="UNKNOWN">ไม่ทราบสถานะ (UNKNOWN)</option>
          </select>
        </div>

        <!-- Education Level Filter -->
        <div>
          <label for="level-filter" class="block text-xs font-medium text-gray-700"
            >ระดับการศึกษา</label
          >
          <select
            id="level-filter"
            v-model="filters.education_level"
            @change="handleFilterChange"
            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">ทุกระดับการศึกษา</option>
            <option value="VOC_CERT">ปวช.</option>
            <option value="HIGH_VOC_CERT">ปวส.</option>
            <option value="SHORT_COURSE">หลักสูตรระยะสั้น</option>
            <option value="OTHER">อื่นๆ</option>
          </select>
        </div>

        <!-- Department Filter -->
        <div>
          <label for="dept-filter" class="block text-xs font-medium text-gray-700">แผนกวิชา</label>
          <select
            id="dept-filter"
            v-model="filters.department_uuid"
            @change="handleDepartmentChange"
            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          >
            <option value="">ทุกแผนกวิชา</option>
            <option v-for="d in departments" :key="d.uuid" :value="d.uuid">
              {{ d.name }} ({{ d.code }})
            </option>
          </select>
        </div>

        <!-- Program Filter -->
        <div>
          <label for="prog-filter" class="block text-xs font-medium text-gray-700">สาขาวิชา</label>
          <select
            id="prog-filter"
            v-model="filters.program_uuid"
            @change="handleProgramChange"
            :disabled="!filters.department_uuid && programs.length === 0"
            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
          >
            <option value="">ทุกสาขาวิชา</option>
            <option v-for="p in programs" :key="p.uuid" :value="p.uuid">
              {{ p.name }} ({{ p.code }})
            </option>
          </select>
        </div>

        <!-- Classroom Filter -->
        <div>
          <label for="class-filter" class="block text-xs font-medium text-gray-700"
            >ห้องเรียน</label
          >
          <select
            id="class-filter"
            v-model="filters.classroom_uuid"
            @change="handleFilterChange"
            :disabled="!filters.department_uuid && classrooms.length === 0"
            class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
          >
            <option value="">ทุกห้องเรียน</option>
            <option v-for="c in classrooms" :key="c.uuid" :value="c.uuid">
              {{ c.name }} ({{ c.code }})
            </option>
          </select>
        </div>

        <!-- Year Level Filter & Reset Action -->
        <div class="flex items-end space-x-2">
          <div class="flex-1">
            <label for="year-level-filter" class="block text-xs font-medium text-gray-700"
              >ชั้นปี</label
            >
            <select
              id="year-level-filter"
              v-model="filters.year_level"
              @change="handleFilterChange"
              class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            >
              <option value="">ทุกชั้นปี</option>
              <option value="1">ชั้นปีที่ 1</option>
              <option value="2">ชั้นปีที่ 2</option>
              <option value="3">ชั้นปีที่ 3</option>
              <option value="4">ชั้นปีที่ 4</option>
            </select>
          </div>
          <button
            @click="resetFilters"
            type="button"
            class="rounded-md border border-gray-300 bg-surface px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            title="ล้างตัวกรองทั้งหมด"
          >
            ล้างตัวกรอง
          </button>
        </div>
      </div>
    </div>

    <!-- Student Table Card -->
    <div class="rounded-lg bg-surface shadow overflow-hidden">
      <!-- Loading State -->
      <div v-if="loading" class="p-12 text-center">
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
        <p class="mt-3 text-sm text-gray-500">กำลังโหลดข้อมูลนักเรียน...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="students.length === 0" class="p-12 text-center">
        <svg
          class="mx-auto h-12 w-12 text-gray-400"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"
          />
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">ไม่พบข้อมูลนักเรียน</h3>
        <p class="mt-1 text-sm text-gray-500">
          ไม่พบข้อมูลที่ตรงกับเงื่อนไขการค้นหาหรือตัวกรองที่เลือก
        </p>
        <div v-if="authStore.can('student.create')" class="mt-6">
          <router-link
            to="/students/new"
            class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700"
          >
            เพิ่มนักเรียนคนแรก
          </router-link>
        </div>
      </div>

      <!-- Data Table -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th
                scope="col"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
              >
                นักเรียน
              </th>
              <th
                scope="col"
                @click="handleSort('student_code')"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
              >
                <div class="flex items-center space-x-1">
                  <span>รหัสนักเรียน</span>
                  <span v-if="filters.sort === 'student_code'" class="text-blue-600">
                    {{ filters.order === 'asc' ? '▲' : '▼' }}
                  </span>
                </div>
              </th>
              <th
                scope="col"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
              >
                แผนก / สาขาวิชา
              </th>
              <th
                scope="col"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
              >
                ห้องเรียน / ชั้นปี
              </th>
              <th
                scope="col"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
              >
                ระดับการศึกษา
              </th>
              <th
                scope="col"
                @click="handleSort('academic_status')"
                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
              >
                <div class="flex items-center space-x-1">
                  <span>สถานะการศึกษา</span>
                  <span v-if="filters.sort === 'academic_status'" class="text-blue-600">
                    {{ filters.order === 'asc' ? '▲' : '▼' }}
                  </span>
                </div>
              </th>
              <th
                scope="col"
                class="relative px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"
              >
                จัดการ
              </th>
            </tr>
          </thead>
          <tbody class="bg-surface divide-y divide-gray-200">
            <tr v-for="s in students" :key="s.uuid" class="hover:bg-gray-50 transition-colors">
              <!-- Student Avatar & Name -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div
                    class="h-10 w-10 shrink-0 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-semibold text-sm border border-blue-200"
                  >
                    {{ getInitials(s.person) }}
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900">
                      {{ s.person?.first_name }} {{ s.person?.last_name }}
                    </div>
                    <div v-if="s.person?.display_name" class="text-xs text-gray-500">
                      ชื่อเรียก: {{ s.person.display_name }}
                    </div>
                  </div>
                </div>
              </td>

              <!-- Student Code -->
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="font-mono text-sm font-semibold text-gray-900">
                  {{ s.student_code }}
                </span>
              </td>

              <!-- Department / Program -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ s.department?.name || '-' }}</div>
                <div class="text-xs text-gray-500">{{ s.program?.name || '-' }}</div>
              </td>

              <!-- Classroom / Year Level -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ s.classroom?.name || '-' }}</div>
                <div class="text-xs text-gray-500">ชั้นปีที่ {{ s.year_level ?? '-' }}</div>
              </td>

              <!-- Education Level -->
              <td class="px-6 py-4 whitespace-nowrap">
                <EducationLevelBadge :level="s.education_level" />
              </td>

              <!-- Academic Status -->
              <td class="px-6 py-4 whitespace-nowrap">
                <AcademicStatusBadge :status="s.academic_status" />
              </td>

              <!-- Actions -->
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                <router-link
                  v-if="authStore.can('student.view')"
                  :to="`/students/${s.uuid}`"
                  class="text-blue-600 hover:text-blue-900 font-medium"
                >
                  ดูรายละเอียด
                </router-link>
                <router-link
                  v-if="authStore.can('student.update')"
                  :to="`/students/${s.uuid}/edit`"
                  class="text-gray-600 hover:text-gray-900 font-medium"
                >
                  แก้ไข
                </router-link>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="pagination.total > 0"
        class="flex items-center justify-between border-t border-gray-200 bg-surface px-4 py-3 sm:px-6"
      >
        <div class="flex flex-1 justify-between sm:hidden">
          <button
            @click="changePage(pagination.current_page - 1)"
            :disabled="pagination.current_page <= 1"
            class="relative inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            ก่อนหน้า
          </button>
          <button
            @click="changePage(pagination.current_page + 1)"
            :disabled="pagination.current_page >= pagination.last_page"
            class="relative ml-3 inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
          >
            ถัดไป
          </button>
        </div>
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
          <div>
            <p class="text-sm text-gray-700">
              แสดงรายการที่
              <span class="font-medium">{{
                (pagination.current_page - 1) * pagination.per_page + 1
              }}</span>
              ถึง
              <span class="font-medium">
                {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }}
              </span>
              จากทั้งหมด
              <span class="font-medium">{{ pagination.total }}</span>
              รายการ
            </p>
          </div>
          <div>
            <nav
              class="isolate inline-flex -space-x-px rounded-md shadow-sm"
              aria-label="Pagination"
            >
              <button
                @click="changePage(pagination.current_page - 1)"
                :disabled="pagination.current_page <= 1"
                class="relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-40"
              >
                <span class="sr-only">ก่อนหน้า</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path
                    fill-rule="evenodd"
                    d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z"
                    clip-rule="evenodd"
                  />
                </svg>
              </button>
              <button
                v-for="p in Math.min(pagination.last_page, 7)"
                :key="p"
                @click="changePage(p)"
                :class="[
                  p === pagination.current_page
                    ? 'relative z-10 inline-flex items-center bg-blue-600 px-4 py-2 text-sm font-semibold text-white focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600'
                    : 'relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0',
                ]"
              >
                {{ p }}
              </button>
              <button
                @click="changePage(pagination.current_page + 1)"
                :disabled="pagination.current_page >= pagination.last_page"
                class="relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0 disabled:opacity-40"
              >
                <span class="sr-only">ถัดไป</span>
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path
                    fill-rule="evenodd"
                    d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                    clip-rule="evenodd"
                  />
                </svg>
              </button>
            </nav>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
