<script setup>
import { onMounted, ref, watch } from 'vue'
import { staffService } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const rows = ref([])
const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
const filters = ref({ keyword: '', person_type: '', employment_status: '' })
const loading = ref(false)
const error = ref('')
let debounce

const statusLabels = {
  ACTIVE: 'ปฏิบัติงาน',
  LEAVE: 'ลา',
  SUSPENDED: 'พักงาน',
  RESIGNED: 'ลาออก',
  RETIRED: 'เกษียณ',
}
const typeLabels = { TEACHER: 'ครู', STAFF: 'บุคลากร' }

async function load(page = 1) {
  loading.value = true
  error.value = ''
  try {
    const params = Object.fromEntries(
      Object.entries({ ...filters.value, page, per_page: 20 }).filter(([, value]) => value !== ''),
    )
    const result = await staffService.list(params)
    rows.value = result.data || []
    pagination.value = result.meta?.pagination || pagination.value
  } catch (err) {
    error.value = err.response?.data?.message || 'โหลดรายชื่อบุคลากรไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
watch(
  filters,
  () => {
    clearTimeout(debounce)
    debounce = setTimeout(() => load(1), 250)
  },
  { deep: true },
)
onMounted(() => load())
</script>

<template>
  <main class="mx-auto max-w-7xl p-6">
    <header class="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">จัดการข้อมูลบุคลากร</h1>
        <p class="mt-1 text-sm text-gray-500">ข้อมูลครูและบุคลากรแยกจากทะเบียนนักเรียน</p>
      </div>
      <div class="flex gap-2">
        <RouterLink
          v-if="auth.can('staff.import')"
          to="/staff/import"
          class="rounded-lg border px-4 py-2 text-sm"
          >นำเข้า CSV</RouterLink
        >
        <RouterLink
          v-if="auth.can('staff.create')"
          to="/staff/new"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white"
          >เพิ่มบุคลากร</RouterLink
        >
      </div>
    </header>
    <section class="mb-4 grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-3">
      <input
        v-model="filters.keyword"
        class="rounded-lg border px-3 py-2"
        placeholder="ค้นหารหัส ชื่อ หรือตำแหน่ง"
        aria-label="ค้นหาบุคลากร"
      />
      <select
        v-model="filters.person_type"
        class="rounded-lg border px-3 py-2"
        aria-label="ประเภทบุคลากร"
      >
        <option value="">ทุกประเภท</option>
        <option value="TEACHER">ครู</option>
        <option value="STAFF">บุคลากร</option>
      </select>
      <select
        v-model="filters.employment_status"
        class="rounded-lg border px-3 py-2"
        aria-label="สถานะการทำงาน"
      >
        <option value="">ทุกสถานะ</option>
        <option v-for="(label, value) in statusLabels" :key="value" :value="value">
          {{ label }}
        </option>
      </select>
    </section>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
    <div class="overflow-x-auto rounded-xl border bg-white">
      <table class="w-full text-left text-sm">
        <thead class="bg-gray-50 text-gray-600">
          <tr>
            <th class="p-3">รหัส</th>
            <th class="p-3">ชื่อบุคลากร</th>
            <th class="p-3">ประเภท / ตำแหน่ง</th>
            <th class="p-3">หน่วยงาน</th>
            <th class="p-3">สถานะ</th>
            <th class="p-3">ข้อมูล</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="p-8 text-center text-gray-500">กำลังโหลด…</td>
          </tr>
          <tr v-else-if="!rows.length">
            <td colspan="6" class="p-8 text-center text-gray-500">ไม่พบข้อมูลบุคลากร</td>
          </tr>
          <tr v-for="row in rows" :key="row.uuid" class="border-t hover:bg-gray-50">
            <td class="p-3 font-medium">{{ row.staff_code }}</td>
            <td class="p-3">
              {{
                row.person?.display_name ||
                `${row.person?.first_name || ''} ${row.person?.last_name || ''}`
              }}
            </td>
            <td class="p-3">
              {{ typeLabels[row.person?.person_type] || row.person?.person_type
              }}<span v-if="row.position" class="block text-xs text-gray-500">{{
                row.position
              }}</span>
            </td>
            <td class="p-3">{{ row.department?.name || '—' }}</td>
            <td class="p-3">{{ statusLabels[row.employment_status] || row.employment_status }}</td>
            <td class="p-3">
              <RouterLink :to="`/staff/${row.uuid}`" class="text-blue-700 hover:underline"
                >ดูรายละเอียด</RouterLink
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <footer class="mt-4 flex items-center justify-between text-sm">
      <span>ทั้งหมด {{ pagination.total || 0 }} รายการ</span>
      <div class="flex gap-2">
        <button
          class="rounded border px-3 py-1 disabled:opacity-40"
          :disabled="loading || pagination.current_page <= 1"
          @click="load(pagination.current_page - 1)"
        >
          ก่อนหน้า</button
        ><span class="px-2 py-1"
          >{{ pagination.current_page || 1 }} / {{ pagination.last_page || 1 }}</span
        ><button
          class="rounded border px-3 py-1 disabled:opacity-40"
          :disabled="loading || pagination.current_page >= pagination.last_page"
          @click="load(pagination.current_page + 1)"
        >
          ถัดไป
        </button>
      </div>
    </footer>
  </main>
</template>
