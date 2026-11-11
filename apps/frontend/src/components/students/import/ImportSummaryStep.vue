<script setup>
import { computed } from 'vue'

const props = defineProps({
  job: {
    type: Object,
    required: true,
  },
})

defineEmits(['newImport'])

const isSuccess = computed(() => {
  return props.job.status === 'COMPLETED'
})

const conflictModeLabels = {
  CREATE_ONLY: 'สร้างเฉพาะนักเรียนใหม่ (CREATE_ONLY)',
  SKIP_EXISTING: 'ข้ามรายการเดิม (SKIP_EXISTING)',
  UPDATE_EXISTING: 'อัปเดตรายการเดิม (UPDATE_EXISTING)',
}

function formatDate(isoStr) {
  if (!isoStr) return '-'
  try {
    const d = new Date(isoStr)
    return d.toLocaleString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
  } catch {
    return isoStr
  }
}
</script>

<template>
  <div class="space-y-6">
    <!-- Successful Import Summary -->
    <div v-if="isSuccess" class="rounded-lg bg-surface p-8 shadow text-center">
      <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
        <svg class="h-8 w-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"
          />
        </svg>
      </div>

      <h3 class="mt-4 text-xl font-bold text-gray-900">
        การนำเข้าข้อมูลเสร็จสมบูรณ์ (Import Completed)
      </h3>
      <p class="mt-1 text-sm text-gray-500">
        ระบบได้ประมวลผลข้อมูลนักเรียนตามนโยบายที่เลือกเรียบร้อยแล้ว
      </p>

      <!-- Results Grid -->
      <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4 max-w-3xl mx-auto text-left">
        <div class="rounded-lg bg-green-50 p-4 border border-green-200">
          <p class="text-xs font-medium text-green-700">สำเร็จ (Success)</p>
          <p class="mt-1 text-2xl font-bold text-green-800 font-mono">{{ job.success_rows }}</p>
          <p class="mt-1 text-[11px] text-green-600">บันทึกข้อมูลเรียบร้อย</p>
        </div>

        <div class="rounded-lg bg-blue-50 p-4 border border-blue-200">
          <p class="text-xs font-medium text-blue-700">ข้ามรายการเดิม (Skipped)</p>
          <p class="mt-1 text-2xl font-bold text-blue-800 font-mono">{{ job.skipped_rows }}</p>
          <p class="mt-1 text-[11px] text-blue-600">ตามโหมด SKIP_EXISTING</p>
        </div>

        <div class="rounded-lg bg-amber-50 p-4 border border-amber-200">
          <p class="text-xs font-medium text-amber-700">ล้มเหลวขณะประมวลผล (Failed)</p>
          <p class="mt-1 text-2xl font-bold text-amber-800 font-mono">{{ job.failed_rows }}</p>
          <p class="mt-1 text-[11px] text-amber-600">เกิดข้อผิดพลาดในการบันทึก</p>
        </div>

        <div class="rounded-lg bg-gray-50 p-4 border border-gray-200">
          <p class="text-xs font-medium text-gray-600">ไม่ผ่าน validation (Invalid)</p>
          <p class="mt-1 text-2xl font-bold text-gray-800 font-mono">{{ job.invalid_rows }}</p>
          <p class="mt-1 text-[11px] text-gray-500">ถูกคัดออกตั้งแต่การตรวจ</p>
        </div>
      </div>

      <!-- Execution Metadata -->
      <div
        class="mt-6 max-w-3xl mx-auto bg-gray-50 p-4 rounded-lg border border-gray-200 text-left text-xs text-gray-600"
      >
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-3">
          <div>
            <dt class="font-medium text-gray-500">รหัสงาน (Job UUID):</dt>
            <dd class="mt-0.5 font-mono text-gray-800">{{ job.uuid }}</dd>
          </div>
          <div>
            <dt class="font-medium text-gray-500">นโยบาย (Conflict Mode):</dt>
            <dd class="mt-0.5 text-gray-800">
              {{ conflictModeLabels[job.conflict_mode] || job.conflict_mode }}
            </dd>
          </div>
          <div>
            <dt class="font-medium text-gray-500">เวลาที่เสร็จสิ้น:</dt>
            <dd class="mt-0.5 text-gray-800">
              {{ formatDate(job.completed_at || job.updated_at) }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- Action Buttons -->
      <div class="mt-8 flex justify-center space-x-4">
        <router-link
          to="/students"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <svg class="-ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
            />
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
            />
          </svg>
          ดูรายการนักเรียนในระบบ
        </router-link>
        <button
          type="button"
          class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-5 py-2.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          @click="$emit('newImport')"
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
              d="M12 4v16m8-8H4"
            />
          </svg>
          นำเข้าไฟล์ใหม่ (New Import)
        </button>
      </div>
    </div>

    <!-- Failed Job State -->
    <div v-else class="rounded-lg bg-surface p-8 shadow text-center">
      <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-red-100">
        <svg class="h-8 w-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M6 18L18 6M6 6l12 12"
          />
        </svg>
      </div>

      <h3 class="mt-4 text-xl font-bold text-gray-900">การนำเข้าข้อมูลล้มเหลว (Import Failed)</h3>
      <p class="mt-1 text-sm text-gray-500">
        ไม่สามารถประมวลผลไฟล์นำเข้าได้ กรุณาตรวจสอบรูปแบบไฟล์และลองใหม่อีกครั้ง
      </p>

      <div
        class="mt-6 max-w-xl mx-auto rounded-md bg-red-50 p-4 border border-red-200 text-left text-sm text-red-800"
      >
        <p class="font-medium">สถานะงานนำเข้า: {{ job.status }}</p>
        <p class="mt-1 text-xs text-red-700">
          รหัสงาน: <span class="font-mono">{{ job.uuid }}</span>
        </p>
      </div>

      <!-- Action Button -->
      <div class="mt-8 flex justify-center">
        <button
          type="button"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
          @click="$emit('newImport')"
        >
          เริ่มต้นนำเข้าไฟล์ใหม่
        </button>
      </div>
    </div>
  </div>
</template>
