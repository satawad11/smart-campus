<script setup>
import { computed } from 'vue'

const props = defineProps({
  job: {
    type: Object,
    required: true,
  },
  previewData: {
    type: Object,
    default: () => ({ summary: {}, sample_rows: [] }),
  },
})

defineEmits(['reviewErrors', 'proceed', 'reset'])

const summary = computed(() => {
  return (
    props.previewData?.summary || {
      total_rows: props.job?.total_rows || 0,
      valid_rows: props.job?.valid_rows || 0,
      invalid_rows: props.job?.invalid_rows || 0,
      conflict_rows: 0,
      identity_mismatches: 0,
      conflict_mode: props.job?.conflict_mode || 'CREATE_ONLY',
      is_ready_for_execution: false,
    }
  )
})

const sampleRows = computed(() => {
  return props.previewData?.sample_rows || []
})

const conflictModeLabels = {
  CREATE_ONLY: {
    label: 'สร้างเฉพาะนักเรียนใหม่ (CREATE_ONLY)',
    class: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  },
  SKIP_EXISTING: {
    label: 'ข้ามรายการเดิม (SKIP_EXISTING)',
    class: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  },
  UPDATE_EXISTING: {
    label: 'อัปเดตรายการเดิม (UPDATE_EXISTING)',
    class: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  },
}

const conflictTypeLabels = {
  IDENTITY_MISMATCH: {
    label: 'ข้อมูลตัวบุคคลไม่ตรงกัน (Identity Mismatch)',
    class: 'bg-red-50 text-red-700 ring-red-600/20',
  },
  PERSON_CODE_CONFLICT: {
    label: 'เลขประจำตัวบุคคลซ้ำ (Person Code Conflict)',
    class: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  },
  DUPLICATE_STUDENT_CODE: {
    label: 'รหัสนักเรียนซ้ำ (Duplicate Code)',
    class: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  },
}

function getConflictDisplay(conflictType) {
  return (
    conflictTypeLabels[conflictType] || {
      label: conflictType || 'มีข้อขัดแย้ง',
      class: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    }
  )
}
</script>

<template>
  <div class="space-y-6">
    <!-- Validation Summary KPI Cards -->
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
      <div class="rounded-lg bg-surface p-4 shadow">
        <p class="text-xs font-medium text-gray-500">จำนวนแถวทั้งหมด</p>
        <p class="mt-1 text-2xl font-bold text-gray-900 font-mono">{{ summary.total_rows }}</p>
        <p class="mt-1 text-[11px] text-gray-400">ในไฟล์ CSV</p>
      </div>

      <div class="rounded-lg bg-surface p-4 shadow">
        <p class="text-xs font-medium text-green-600">ผ่านการตรวจสอบ (Valid)</p>
        <p class="mt-1 text-2xl font-bold text-green-700 font-mono">{{ summary.valid_rows }}</p>
        <p class="mt-1 text-[11px] text-green-600">พร้อมนำเข้าข้อมูล</p>
      </div>

      <div class="rounded-lg bg-surface p-4 shadow">
        <p class="text-xs font-medium text-red-600">ไม่ผ่านการตรวจสอบ (Invalid)</p>
        <p class="mt-1 text-2xl font-bold text-red-700 font-mono">{{ summary.invalid_rows }}</p>
        <p class="mt-1 text-[11px] text-red-600">จะถูกข้าม (ไม่นำเข้า)</p>
      </div>

      <div class="rounded-lg bg-surface p-4 shadow">
        <p class="text-xs font-medium text-amber-600">ข้อมูลซ้ำ/ขัดแย้ง (Conflicts)</p>
        <p class="mt-1 text-2xl font-bold text-amber-700 font-mono">{{ summary.conflict_rows }}</p>
        <p class="mt-1 text-[11px] text-amber-600">ตามนโยบายจัดการ</p>
      </div>

      <div class="rounded-lg bg-surface p-4 shadow">
        <p class="text-xs font-medium text-purple-600">Identity Mismatches</p>
        <p class="mt-1 text-2xl font-bold text-purple-700 font-mono">
          {{ summary.identity_mismatches }}
        </p>
        <p class="mt-1 text-[11px] text-purple-600">ปฏิเสธเพื่อความปลอดภัย</p>
      </div>
    </div>

    <!-- Conflict Policy Summary Banner -->
    <div
      class="rounded-lg bg-surface p-4 shadow flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
    >
      <div class="flex items-center space-x-3">
        <span class="text-xs font-medium text-gray-500">นโยบายจัดการข้อมูลซ้ำที่เลือก:</span>
        <span
          class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
          :class="conflictModeLabels[summary.conflict_mode]?.class || 'bg-gray-100 text-gray-800'"
        >
          {{ conflictModeLabels[summary.conflict_mode]?.label || summary.conflict_mode }}
        </span>
      </div>
      <div class="text-xs text-gray-500">
        รหัสงานนำเข้า (Job UUID): <span class="font-mono text-gray-700">{{ job.uuid }}</span>
      </div>
    </div>

    <!-- Sample Preview Table -->
    <div class="rounded-lg bg-surface shadow overflow-hidden">
      <div class="p-6 border-b border-gray-200">
        <div class="sm:flex sm:items-center sm:justify-between">
          <div>
            <h3 class="text-base font-semibold text-gray-900">
              ตัวอย่างข้อมูลจากการตรวจสอบ (Sample Data Preview)
            </h3>
            <p class="text-xs text-gray-500 mt-1">
              แสดงตัวอย่าง {{ sampleRows.length }} แถวแรกจากไฟล์เพื่อการตรวจสอบก่อนยืนยันนำเข้า
            </p>
          </div>
          <button
            v-if="summary.invalid_rows > 0"
            type="button"
            class="mt-3 sm:mt-0 inline-flex items-center text-xs font-medium text-red-600 hover:text-red-800"
            @click="$emit('reviewErrors')"
          >
            <svg
              class="h-4 w-4 mr-1 text-red-500"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
              />
            </svg>
            ดูรายการข้อผิดพลาดทั้งหมด ({{ summary.invalid_rows }} รายการ) &rarr;
          </button>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
          <thead class="bg-gray-50 text-xs font-medium text-gray-500 uppercase tracking-wider">
            <tr>
              <th scope="col" class="px-4 py-3">แถวที่</th>
              <th scope="col" class="px-4 py-3">รหัสนักเรียน</th>
              <th scope="col" class="px-4 py-3">ชื่อ - นามสกุล</th>
              <th scope="col" class="px-4 py-3">แผนก / สาขา / ห้อง</th>
              <th scope="col" class="px-4 py-3">ระดับ / ชั้นปี</th>
              <th scope="col" class="px-4 py-3">สถานะการศึกษา</th>
              <th scope="col" class="px-4 py-3">สถานะการตรวจสอบ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-200 bg-surface">
            <tr
              v-for="row in sampleRows"
              :key="row._row_number"
              :class="[row._has_conflict ? 'bg-amber-50/40' : 'hover:bg-gray-50']"
            >
              <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-gray-500">
                {{ row._row_number }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 font-mono font-medium text-gray-900">
                {{ row.student_code || '-' }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                {{ row.first_name || '' }} {{ row.last_name || '' }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                {{ row.department_code || '-' }} / {{ row.program_code || '-' }} /
                {{ row.classroom_code || '-' }}
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                {{ row.education_level || '-' }} (ปี {{ row.year_level || '-' }})
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs">
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"
                >
                  {{ row.academic_status || 'ACTIVE' }}
                </span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs">
                <span
                  v-if="row._has_conflict"
                  class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                  :class="getConflictDisplay(row._conflict_type).class"
                >
                  {{ getConflictDisplay(row._conflict_type).label }}
                </span>
                <span
                  v-else
                  class="inline-flex items-center rounded-md bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20"
                >
                  พร้อมนำเข้า
                </span>
              </td>
            </tr>
            <tr v-if="sampleRows.length === 0">
              <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-500">
                ไม่มีตัวอย่างข้อมูลที่สามารถแสดงได้
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="flex items-center justify-between">
      <button
        type="button"
        class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
        @click="$emit('reset')"
      >
        &larr; ยกเลิก / อัปโหลดไฟล์ใหม่
      </button>

      <div class="flex space-x-3">
        <button
          v-if="summary.invalid_rows > 0"
          type="button"
          class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          @click="$emit('reviewErrors')"
        >
          ตรวจสอบข้อผิดพลาด ({{ summary.invalid_rows }})
        </button>
        <button
          type="button"
          :disabled="summary.valid_rows === 0"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
          @click="$emit('proceed')"
        >
          ยืนยันและไปขั้นตอนประมวลผล &rarr;
        </button>
      </div>
    </div>
  </div>
</template>
