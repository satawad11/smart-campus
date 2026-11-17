<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import academicsService from '@/services/academics'
import { apiErrorText, statusLabel } from '@/constants/labels'

/**
 * ข้อมูลการศึกษา: departments, programs and classrooms (STD master data).
 * Everyone with academic.view can browse; academic.manage can add and edit.
 * Codes, the classroom's academic year and a record's parent department are fixed
 * after creation (the API rejects them); records are deactivated, never deleted.
 */
const authStore = useAuthStore()
const canManage = computed(() => authStore.can('academic.manage'))

const LEVELS = {
  VOC_CERT: 'ปวช.',
  HIGH_VOC_CERT: 'ปวส.',
  SHORT_COURSE: 'หลักสูตรระยะสั้น',
  OTHER: 'อื่นๆ',
}

const TABS = [
  { key: 'departments', label: 'แผนกวิชา' },
  { key: 'programs', label: 'สาขาวิชา' },
  { key: 'classrooms', label: 'ห้องเรียน' },
]

const tab = ref('departments')
const keyword = ref('')
const status = ref('')
const rows = ref([])
const pagination = ref(null)
const page = ref(1)
const loading = ref(false)
const error = ref('')

// Options for the parent pickers in the form.
const departments = ref([])
const programs = ref([])

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await academicsService.page(tab.value, {
      page: page.value,
      per_page: 20,
      keyword: keyword.value || undefined,
      status: status.value || undefined,
      sort: 'code',
      order: 'asc',
    })
    rows.value = res.data || []
    pagination.value = res.meta?.pagination || null
  } catch (err) {
    rows.value = []
    error.value = apiErrorText(err, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  try {
    departments.value = await academicsService.listDepartments({ per_page: 100, status: 'ACTIVE' })
    programs.value = await academicsService.listPrograms({ per_page: 100, status: 'ACTIVE' })
  } catch {
    // Pickers stay empty; the API still validates.
  }
}

watch(tab, () => {
  page.value = 1
  keyword.value = ''
  status.value = ''
  load()
})

function search() {
  page.value = 1
  load()
}

function goPage(next) {
  page.value = next
  load()
}

// ---- Add / edit form ----
const formOpen = ref(false)
const editing = ref(null) // the row being edited, or null when adding
const form = ref({})
const formError = ref('')
const fieldErrors = ref({})
const saving = ref(false)

const programsForDepartment = computed(() =>
  programs.value.filter(
    (p) => !form.value.department_uuid || p.department?.uuid === form.value.department_uuid,
  ),
)

function openCreate() {
  editing.value = null
  form.value = {
    code: '',
    name: '',
    description: '',
    department_uuid: departments.value[0]?.uuid || '',
    program_uuid: '',
    education_level: 'VOC_CERT',
    academic_year: String(new Date().getFullYear() + 543),
    year_level: 1,
    status: 'ACTIVE',
  }
  formError.value = ''
  fieldErrors.value = {}
  formOpen.value = true
}

function openEdit(row) {
  editing.value = row
  form.value = {
    code: row.code,
    name: row.name,
    description: row.description || '',
    department_uuid: row.department?.uuid || '',
    program_uuid: row.program?.uuid || '',
    education_level: row.education_level || 'VOC_CERT',
    academic_year: row.academic_year || '',
    year_level: row.year_level || 1,
    status: row.status,
  }
  formError.value = ''
  fieldErrors.value = {}
  formOpen.value = true
}

/** Only the fields each resource accepts, and on edit only the changeable ones. */
function payload() {
  const f = form.value
  const isEdit = editing.value !== null
  if (tab.value === 'departments') {
    return isEdit
      ? { name: f.name, description: f.description || null, status: f.status }
      : { code: f.code, name: f.name, description: f.description || null, status: f.status }
  }
  if (tab.value === 'programs') {
    const base = { name: f.name, education_level: f.education_level, status: f.status }
    return isEdit ? base : { ...base, code: f.code, department_uuid: f.department_uuid }
  }
  const base = {
    name: f.name,
    year_level: Number(f.year_level),
    status: f.status,
    program_uuid: f.program_uuid || null,
  }
  return isEdit
    ? base
    : { ...base, code: f.code, department_uuid: f.department_uuid, academic_year: f.academic_year }
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    if (editing.value) {
      await academicsService.update(tab.value, editing.value.uuid, payload())
    } else {
      await academicsService.create(tab.value, payload())
    }
    formOpen.value = false
    await Promise.all([load(), loadOptions()])
  } catch (err) {
    fieldErrors.value = err?.fields || {}
    formError.value = apiErrorText(err, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function fieldError(name) {
  const value = fieldErrors.value[name]
  return Array.isArray(value) ? value[0] : value
}

const tabLabel = computed(() => TABS.find((t) => t.key === tab.value)?.label)

onMounted(() => {
  load()
  loadOptions()
})
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ข้อมูลการศึกษา</h1>
        <p class="text-sm text-gray-500">แผนกวิชา สาขาวิชา และห้องเรียน ที่ใช้กับข้อมูลผู้เรียน</p>
      </div>
      <button
        v-if="canManage"
        type="button"
        data-test="academic-add"
        class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
        @click="openCreate"
      >
        + เพิ่ม{{ tabLabel }}
      </button>
    </div>

    <div class="flex gap-2 border-b" role="tablist">
      <button
        v-for="t in TABS"
        :key="t.key"
        type="button"
        role="tab"
        :data-test="`tab-${t.key}`"
        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium"
        :class="
          tab === t.key
            ? 'border-blue-600 text-blue-700'
            : 'border-transparent text-gray-500 hover:text-gray-800'
        "
        @click="tab = t.key"
      >
        {{ t.label }}
      </button>
    </div>

    <form class="flex flex-wrap items-end gap-3" @submit.prevent="search">
      <input
        v-model="keyword"
        type="text"
        placeholder="ค้นหารหัสหรือชื่อ"
        class="min-w-[220px] flex-1 rounded-lg border px-3 py-2 text-sm"
      />
      <select v-model="status" class="rounded-lg border px-3 py-2 text-sm" @change="search">
        <option value="">ทุกสถานะ</option>
        <option value="ACTIVE">ใช้งาน</option>
        <option value="INACTIVE">ปิดใช้งาน</option>
      </select>
      <button
        type="submit"
        class="rounded-lg border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
      >
        ค้นหา
      </button>
    </form>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-surface">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3">รหัส</th>
            <th class="px-4 py-3">ชื่อ</th>
            <th v-if="tab !== 'departments'" class="px-4 py-3">แผนก</th>
            <th v-if="tab === 'programs'" class="px-4 py-3">ระดับ</th>
            <th v-if="tab === 'classrooms'" class="px-4 py-3">สาขา</th>
            <th v-if="tab === 'classrooms'" class="px-4 py-3">ปีการศึกษา / ชั้นปี</th>
            <th class="px-4 py-3">สถานะ</th>
            <th v-if="canManage" class="px-4 py-3 text-right"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in rows" :key="row.uuid" data-test="academic-row">
            <td class="px-4 py-3 font-mono text-xs">{{ row.code }}</td>
            <td class="px-4 py-3">
              {{ row.name }}
              <span v-if="row.description" class="block text-xs text-gray-400">{{
                row.description
              }}</span>
            </td>
            <td v-if="tab !== 'departments'" class="px-4 py-3">
              {{ row.department?.name || '-' }}
            </td>
            <td v-if="tab === 'programs'" class="px-4 py-3">
              {{ LEVELS[row.education_level] || row.education_level }}
            </td>
            <td v-if="tab === 'classrooms'" class="px-4 py-3">{{ row.program?.name || '-' }}</td>
            <td v-if="tab === 'classrooms'" class="px-4 py-3">
              {{ row.academic_year }} / ปี {{ row.year_level }}
            </td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="
                  row.status === 'ACTIVE'
                    ? 'bg-green-100 text-green-800'
                    : 'bg-gray-100 text-gray-600'
                "
                >{{ statusLabel(row.status) }}</span
              >
            </td>
            <td v-if="canManage" class="px-4 py-3 text-right">
              <button
                type="button"
                data-test="academic-edit"
                class="text-sm text-blue-600 hover:text-blue-800"
                @click="openEdit(row)"
              >
                แก้ไข
              </button>
            </td>
          </tr>
          <tr v-if="!loading && rows.length === 0">
            <td colspan="8" class="px-4 py-10 text-center text-gray-400">ไม่พบข้อมูล</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="pagination && pagination.last_page > 1"
      class="flex items-center justify-end gap-2 text-sm"
    >
      <button
        type="button"
        class="rounded border px-3 py-1 disabled:opacity-40"
        :disabled="page <= 1"
        @click="goPage(page - 1)"
      >
        ก่อนหน้า
      </button>
      <span>หน้า {{ pagination.current_page }} / {{ pagination.last_page }}</span>
      <button
        type="button"
        class="rounded border px-3 py-1 disabled:opacity-40"
        :disabled="page >= pagination.last_page"
        @click="goPage(page + 1)"
      >
        ถัดไป
      </button>
    </div>

    <!-- Add / edit dialog -->
    <div
      v-if="formOpen"
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="formOpen = false"
    >
      <form
        data-test="academic-form"
        class="w-full max-w-lg space-y-4 rounded-xl bg-surface p-6 shadow-xl"
        @submit.prevent="save"
      >
        <h2 class="text-lg font-semibold text-gray-900">
          {{ editing ? 'แก้ไข' : 'เพิ่ม' }}{{ tabLabel }}
        </h2>

        <label class="block text-sm text-gray-700">
          รหัส
          <input
            v-model="form.code"
            data-test="field-code"
            type="text"
            :disabled="!!editing"
            required
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
          />
          <span v-if="editing" class="text-xs text-gray-400">รหัสแก้ไขไม่ได้หลังสร้าง</span>
          <span v-if="fieldError('code')" class="block text-xs text-red-600">{{
            fieldError('code')
          }}</span>
        </label>

        <label class="block text-sm text-gray-700">
          ชื่อ
          <input
            v-model="form.name"
            data-test="field-name"
            type="text"
            required
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          />
          <span v-if="fieldError('name')" class="block text-xs text-red-600">{{
            fieldError('name')
          }}</span>
        </label>

        <label v-if="tab === 'departments'" class="block text-sm text-gray-700">
          รายละเอียด
          <textarea
            v-model="form.description"
            rows="2"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          />
        </label>

        <label v-if="tab !== 'departments'" class="block text-sm text-gray-700">
          แผนกวิชา
          <select
            v-model="form.department_uuid"
            data-test="field-department"
            :disabled="!!editing"
            required
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
          >
            <option v-if="editing && form.department_uuid" :value="form.department_uuid">
              {{ editing.department?.name }}
            </option>
            <option v-for="d in departments" :key="d.uuid" :value="d.uuid">{{ d.name }}</option>
          </select>
          <span v-if="fieldError('department_uuid')" class="block text-xs text-red-600">{{
            fieldError('department_uuid')
          }}</span>
        </label>

        <label v-if="tab === 'programs'" class="block text-sm text-gray-700">
          ระดับการศึกษา
          <select
            v-model="form.education_level"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          >
            <option v-for="(label, value) in LEVELS" :key="value" :value="value">
              {{ label }}
            </option>
          </select>
        </label>

        <template v-if="tab === 'classrooms'">
          <label class="block text-sm text-gray-700">
            สาขาวิชา (ไม่บังคับ)
            <select
              v-model="form.program_uuid"
              class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
            >
              <option value="">- ไม่ระบุ -</option>
              <option v-for="p in programsForDepartment" :key="p.uuid" :value="p.uuid">
                {{ p.name }}
              </option>
            </select>
          </label>
          <div class="grid grid-cols-2 gap-3">
            <label class="block text-sm text-gray-700">
              ปีการศึกษา
              <input
                v-model="form.academic_year"
                type="text"
                :disabled="!!editing"
                required
                class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
              />
            </label>
            <label class="block text-sm text-gray-700">
              ชั้นปี
              <input
                v-model="form.year_level"
                type="number"
                min="1"
                required
                class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
              />
            </label>
          </div>
        </template>

        <label class="block text-sm text-gray-700">
          สถานะ
          <select
            v-model="form.status"
            data-test="field-status"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          >
            <option value="ACTIVE">ใช้งาน</option>
            <option value="INACTIVE">ปิดใช้งาน (ไม่แสดงในตัวเลือกใหม่ ข้อมูลเดิมยังอยู่)</option>
          </select>
        </label>

        <p v-if="formError" data-test="form-error" class="text-sm text-red-600">{{ formError }}</p>

        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="rounded-lg border px-4 py-2 text-sm"
            @click="formOpen = false"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            data-test="academic-save"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
            :disabled="saving"
          >
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
