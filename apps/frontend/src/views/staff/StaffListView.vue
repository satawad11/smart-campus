<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import staffService from '@/services/staff'
import academicsService from '@/services/academics'
import { apiErrorText } from '@/constants/labels'
import { STAFF_TYPES, EMPLOYMENT_STATUSES, employmentBadge, fullName } from '@/constants/staff'

/** จัดการบุคลากร: teachers and staff (Module 09, PPL-010). */
const authStore = useAuthStore()
const canCreate = computed(() => authStore.can('staff.create'))
const canImport = computed(() => authStore.can('staff.import'))

const filters = ref({ keyword: '', person_type: '', employment_status: '', department_uuid: '' })
const rows = ref([])
const pagination = ref(null)
const page = ref(1)
const loading = ref(false)
const error = ref('')
const departments = ref([])

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = { page: page.value, per_page: 20, sort: 'staff_code', order: 'asc' }
    for (const [k, v] of Object.entries(filters.value)) if (v) params[k] = v
    const res = await staffService.list(params)
    rows.value = res.data || []
    pagination.value = res.meta?.pagination || null
  } catch (err) {
    rows.value = []
    error.value = apiErrorText(err, 'โหลดรายชื่อบุคลากรไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function search() {
  page.value = 1
  load()
}

function goPage(next) {
  page.value = next
  load()
}

onMounted(async () => {
  load()
  try {
    departments.value = await academicsService.listDepartments({ per_page: 100, status: 'ACTIVE' })
  } catch {
    departments.value = []
  }
})
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">จัดการข้อมูลบุคลากร</h1>
        <p class="text-sm text-gray-500">ครูและบุคลากร ลงทะเบียนใบหน้าได้เช่นเดียวกับผู้เรียน</p>
      </div>
      <div class="flex gap-2">
        <router-link
          v-if="canImport"
          :to="{ name: 'staff.import' }"
          class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
          >นำเข้า CSV</router-link
        >
        <router-link
          v-if="canCreate"
          :to="{ name: 'staff.create' }"
          data-test="staff-add"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
          >+ เพิ่มบุคลากร</router-link
        >
      </div>
    </div>

    <form
      class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-surface p-4"
      @submit.prevent="search"
    >
      <input
        v-model="filters.keyword"
        type="text"
        aria-label="ค้นหาบุคลากร"
        placeholder="ค้นหารหัส ชื่อ หรือตำแหน่ง"
        class="min-w-[220px] flex-1 rounded-lg border px-3 py-2 text-sm"
      />
      <select v-model="filters.person_type" class="rounded-lg border px-3 py-2 text-sm">
        <option value="">ทุกประเภท</option>
        <option v-for="(label, value) in STAFF_TYPES" :key="value" :value="value">
          {{ label }}
        </option>
      </select>
      <select v-model="filters.employment_status" class="rounded-lg border px-3 py-2 text-sm">
        <option value="">ทุกสถานะ</option>
        <option v-for="(label, value) in EMPLOYMENT_STATUSES" :key="value" :value="value">
          {{ label }}
        </option>
      </select>
      <select v-model="filters.department_uuid" class="rounded-lg border px-3 py-2 text-sm">
        <option value="">ทุกแผนก</option>
        <option v-for="d in departments" :key="d.uuid" :value="d.uuid">{{ d.name }}</option>
      </select>
      <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">
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
            <th class="px-4 py-3">ประเภท</th>
            <th class="px-4 py-3">ตำแหน่ง / แผนก</th>
            <th class="px-4 py-3">สถานะ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in rows" :key="row.uuid" data-test="staff-row" class="hover:bg-gray-50">
            <td class="px-4 py-3 font-mono text-xs">{{ row.staff_code }}</td>
            <td class="px-4 py-3">
              <router-link
                :to="{ name: 'staff.show', params: { uuid: row.uuid } }"
                class="text-blue-700 hover:underline"
                >{{ fullName(row.person) }}</router-link
              >
            </td>
            <td class="px-4 py-3">{{ STAFF_TYPES[row.person?.person_type] || '-' }}</td>
            <td class="px-4 py-3">
              {{ row.position || '-' }}
              <span class="block text-xs text-gray-400">{{ row.department?.name }}</span>
            </td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="employmentBadge(row.employment_status)"
                >{{ EMPLOYMENT_STATUSES[row.employment_status] || row.employment_status }}</span
              >
            </td>
          </tr>
          <tr v-if="!loading && rows.length === 0">
            <td colspan="5" class="px-4 py-10 text-center text-gray-400">ไม่พบข้อมูลบุคลากร</td>
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
      <span
        >หน้า {{ pagination.current_page }} / {{ pagination.last_page }} ({{
          pagination.total
        }}
        คน)</span
      >
      <button
        type="button"
        class="rounded border px-3 py-1 disabled:opacity-40"
        :disabled="page >= pagination.last_page"
        @click="goPage(page + 1)"
      >
        ถัดไป
      </button>
    </div>
  </div>
</template>
