<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { studentImportService } from '@/services/students'

const props = defineProps({
  job: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['completed', 'failed', 'back'])

const currentJob = ref({ ...props.job })
const processing = ref(false)
const errorMessage = ref('')
let pollTimer = null

const isProcessing = computed(() => {
  return currentJob.value.status === 'PROCESSING'
})

const isCompleted = computed(() => {
  return currentJob.value.status === 'COMPLETED'
})

const isFailed = computed(() => {
  return currentJob.value.status === 'FAILED' || currentJob.value.status === 'CANCELLED'
})

const conflictModeLabels = {
  CREATE_ONLY: 'สร้างเฉพาะนักเรียนใหม่ (CREATE_ONLY)',
  SKIP_EXISTING: 'ข้ามรายการเดิม (SKIP_EXISTING)',
  UPDATE_EXISTING: 'อัปเดตรายการเดิม (UPDATE_EXISTING)',
}

async function startProcess() {
  processing.value = true
  errorMessage.value = ''

  try {
    const updated = await studentImportService.process(currentJob.value.uuid)
    currentJob.value = updated
    startPolling()
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถเริ่มการประมวลผลได้'
    processing.value = false
  }
}

function startPolling() {
  stopPolling()
  pollTimer = setInterval(async () => {
    try {
      const refreshed = await studentImportService.show(currentJob.value.uuid)
      currentJob.value = refreshed

      if (refreshed.status === 'COMPLETED') {
        stopPolling()
        processing.value = false
        emit('completed', refreshed)
      } else if (refreshed.status === 'FAILED' || refreshed.status === 'CANCELLED') {
        stopPolling()
        processing.value = false
        emit('failed', refreshed)
      }
    } catch {
      // Ignore transient polling network errors
    }
  }, 2000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

onMounted(() => {
  if (currentJob.value.status === 'PROCESSING') {
    processing.value = true
    startPolling()
  } else if (currentJob.value.status === 'COMPLETED') {
    emit('completed', currentJob.value)
  }
})

onBeforeUnmount(() => {
  stopPolling()
})
</script>

<template>
  <div class="space-y-6">
    <!-- State 1: Confirmation before dispatching process -->
    <div v-if="!isProcessing && !isCompleted && !isFailed" class="rounded-lg bg-white p-6 shadow">
      <h3 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
        ยืนยันการเริ่มประมวลผลข้อมูล (Confirm Process Execution)
      </h3>

      <!-- Error Banner -->
      <div
        v-if="errorMessage"
        class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-800 border border-red-200"
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

      <div class="space-y-4">
        <dl
          class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2 text-sm bg-gray-50 p-4 rounded-lg border border-gray-200"
        >
          <div>
            <dt class="text-xs font-medium text-gray-500">รหัสงานนำเข้า (Job UUID)</dt>
            <dd class="mt-1 font-mono text-xs text-gray-900">{{ currentJob.uuid }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500">
              รูปแบบจัดการข้อมูลซ้ำ (Conflict Policy)
            </dt>
            <dd class="mt-1 text-xs font-medium text-gray-900">
              {{ conflictModeLabels[currentJob.conflict_mode] || currentJob.conflict_mode }}
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500">
              จำนวนแถวที่ผ่านการตรวจสอบ (จะถูกประมวลผล)
            </dt>
            <dd class="mt-1 font-mono font-bold text-green-700 text-base">
              {{ currentJob.valid_rows }} แถว
            </dd>
          </div>
          <div>
            <dt class="text-xs font-medium text-gray-500">จำนวนแถวที่ไม่ผ่าน (จะไม่ถูกนำเข้า)</dt>
            <dd class="mt-1 font-mono font-bold text-red-700 text-base">
              {{ currentJob.invalid_rows }} แถว
            </dd>
          </div>
        </dl>

        <!-- Warning Card for UPDATE_EXISTING -->
        <div
          v-if="currentJob.conflict_mode === 'UPDATE_EXISTING'"
          class="rounded-md bg-amber-50 p-4 border border-amber-200 text-xs text-amber-900"
        >
          <div class="flex">
            <svg
              class="h-5 w-5 text-amber-600 mr-2 shrink-0"
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
            <div>
              <strong class="font-semibold">โปรดทราบ:</strong>
              ในโหมด UPDATE_EXISTING ข้อมูลนักเรียนเดิมในระบบที่มี identity
              ตรงกันจะได้รับการอัปเดตข้อมูลการศึกษา/สถานะการศึกษาตามไฟล์ CSV
            </div>
          </div>
        </div>

        <div class="rounded-md bg-blue-50 p-3 border border-blue-100 text-xs text-blue-800">
          การประมวลผลจะดำเนินการผ่านระบบคิวงานเบื้องหลัง (Background Queue)
          ข้อมูลจะได้รับการบันทึกอย่างปลอดภัยและตรวจสอบความสมบูรณ์ทุกแถว
        </div>
      </div>

      <!-- Action Bar -->
      <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4">
        <button
          type="button"
          :disabled="processing"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
          @click="$emit('back')"
        >
          &larr; กลับไปตรวจสอบข้อมูล
        </button>
        <button
          type="button"
          :disabled="processing || currentJob.valid_rows === 0"
          class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-6 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
          @click="startProcess"
        >
          <svg
            v-if="processing"
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
          {{
            processing ? 'กำลังส่งคำสั่งประมวลผล...' : 'ยืนยันและเริ่มประมวลผล (Start Processing)'
          }}
        </button>
      </div>
    </div>

    <!-- State 2: Active Processing and Polling -->
    <div v-else-if="isProcessing" class="rounded-lg bg-white p-8 shadow text-center">
      <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-blue-100">
        <svg class="h-8 w-8 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
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
      </div>

      <h3 class="mt-4 text-lg font-bold text-gray-900">กำลังประมวลผลข้อมูลนักเรียน (Processing)</h3>
      <p class="mt-1 text-sm text-gray-500">
        ระบบกำลังนำเข้าข้อมูลลงสู่ฐานข้อมูลตามนโยบายที่กำหนด กรุณารอสักครู่...
      </p>

      <!-- Live Counters Grid -->
      <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 max-w-2xl mx-auto text-left">
        <div class="rounded-lg bg-gray-50 p-4 border border-gray-200">
          <p class="text-xs font-medium text-gray-500">แถวที่ต้องประมวลผล</p>
          <p class="mt-1 text-xl font-bold text-gray-900 font-mono">{{ currentJob.valid_rows }}</p>
        </div>
        <div class="rounded-lg bg-green-50 p-4 border border-green-200">
          <p class="text-xs font-medium text-green-700">สำเร็จแล้ว</p>
          <p class="mt-1 text-xl font-bold text-green-700 font-mono">
            {{ currentJob.success_rows }}
          </p>
        </div>
        <div class="rounded-lg bg-blue-50 p-4 border border-blue-200">
          <p class="text-xs font-medium text-blue-700">ข้ามรายการเดิม</p>
          <p class="mt-1 text-xl font-bold text-blue-700 font-mono">
            {{ currentJob.skipped_rows }}
          </p>
        </div>
        <div class="rounded-lg bg-red-50 p-4 border border-red-200">
          <p class="text-xs font-medium text-red-700">ล้มเหลวขณะประมวลผล</p>
          <p class="mt-1 text-xl font-bold text-red-700 font-mono">{{ currentJob.failed_rows }}</p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4 border border-gray-200">
          <p class="text-xs font-medium text-gray-500">ไม่ผ่าน validation</p>
          <p class="mt-1 text-xl font-bold text-gray-700 font-mono">
            {{ currentJob.invalid_rows }}
          </p>
        </div>
        <div class="rounded-lg bg-gray-50 p-4 border border-gray-200">
          <p class="text-xs font-medium text-gray-500">จำนวนแถวทั้งหมด</p>
          <p class="mt-1 text-xl font-bold text-gray-900 font-mono">{{ currentJob.total_rows }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
