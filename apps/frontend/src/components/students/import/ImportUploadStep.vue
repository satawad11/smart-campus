<script setup>
import { ref } from 'vue'
import { studentImportService } from '@/services/students'
import { apiErrorText } from '@/constants/labels'

const emit = defineEmits(['uploaded'])

const file = ref(null)
const fileName = ref('')
const fileSize = ref(0)
const conflictMode = ref('CREATE_ONLY')
const isDragging = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const validationErrors = ref({})

const conflictOptions = [
  {
    value: 'CREATE_ONLY',
    title: 'สร้างเฉพาะนักเรียนใหม่ (CREATE_ONLY) — ค่าเริ่มต้น',
    description:
      'นำเข้าเฉพาะนักเรียนที่ยังไม่มีในระบบ หากพบรหัสนักเรียน (student_code) ซ้ำจะถูกปฏิเสธเป็นข้อผิดพลาด',
    badge: 'ปลอดภัยสูงสุด',
    badgeClass: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  },
  {
    value: 'SKIP_EXISTING',
    title: 'ข้ามรายการเดิม (SKIP_EXISTING)',
    description:
      'หากพบรหัสนักเรียนที่มีอยู่เดิมในระบบและข้อมูลระบุตัวบุคคลตรงกัน ระบบจะข้ามรายการนั้นและนำเข้าเฉพาะรายการใหม่',
    badge: 'ข้ามรายการซ้ำ',
    badgeClass: 'bg-blue-50 text-blue-700 ring-blue-600/20',
  },
  {
    value: 'UPDATE_EXISTING',
    title: 'อัปเดตรายการเดิม (UPDATE_EXISTING)',
    description:
      'อัปเดตข้อมูลการศึกษา/สถานะของนักเรียนเดิมเฉพาะเมื่อข้อมูลตัวบุคคล (Identity) ตรงกัน หากข้อมูลตัวบุคคลไม่ตรงกัน (Identity Mismatch) จะถูกปฏิเสธเสมอ',
    badge: 'อัปเดตข้อมูล',
    badgeClass: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  },
]

function formatFileSize(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

function handleFileSelect(event) {
  const selected = event.target.files?.[0]
  processSelectedFile(selected)
}

function handleDrop(event) {
  isDragging.value = false
  const dropped = event.dataTransfer?.files?.[0]
  processSelectedFile(dropped)
}

function processSelectedFile(selectedFile) {
  errorMessage.value = ''
  validationErrors.value = {}

  if (!selectedFile) return

  // Check extension
  const isCsv = selectedFile.name.toLowerCase().endsWith('.csv') || selectedFile.type === 'text/csv'
  if (!isCsv) {
    errorMessage.value = 'รองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น'
    file.value = null
    fileName.value = ''
    fileSize.value = 0
    return
  }

  // Check size (max 10MB = 10 * 1024 * 1024 bytes)
  const maxBytes = 10 * 1024 * 1024
  if (selectedFile.size > maxBytes) {
    errorMessage.value = 'ขนาดไฟล์เกินกำหนด (ขนาดสูงสุดไม่เกิน 10 MB)'
    file.value = null
    fileName.value = ''
    fileSize.value = 0
    return
  }

  file.value = selectedFile
  fileName.value = selectedFile.name
  fileSize.value = selectedFile.size
}

function removeFile() {
  file.value = null
  fileName.value = ''
  fileSize.value = 0
  errorMessage.value = ''
  validationErrors.value = {}
}

async function handleUpload() {
  if (!file.value) {
    errorMessage.value = 'กรุณาเลือกไฟล์ CSV ที่ต้องการนำเข้า'
    return
  }

  loading.value = true
  errorMessage.value = ''
  validationErrors.value = {}

  try {
    const formData = new FormData()
    formData.append('file', file.value)
    formData.append('conflict_mode', conflictMode.value)

    const result = await studentImportService.upload(formData)
    emit('uploaded', result)
  } catch (err) {
    if (err.errors) {
      validationErrors.value = err.errors
    }
    errorMessage.value = apiErrorText(
      err,
      'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ กรุณาตรวจสอบความถูกต้องของไฟล์',
    )
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <!-- Card: Select File & Drag/Drop -->
    <div class="rounded-lg bg-surface p-6 shadow">
      <h2 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
        1. เลือกไฟล์ข้อมูลนักเรียน (.CSV)
      </h2>

      <!-- Error Alert -->
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

      <!-- Drag & Drop Zone -->
      <div
        v-if="!file"
        class="flex justify-center rounded-lg border-2 border-dashed px-6 py-10 transition-colors"
        :class="[
          isDragging
            ? 'border-blue-500 bg-blue-50/50'
            : 'border-gray-300 hover:border-gray-400 bg-gray-50/50',
        ]"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop"
      >
        <div class="text-center">
          <svg
            class="mx-auto h-12 w-12 text-gray-400"
            stroke="currentColor"
            fill="none"
            viewBox="0 0 48 48"
            aria-hidden="true"
          >
            <path
              d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <div class="mt-4 flex text-sm leading-6 text-gray-600 justify-center">
            <label
              for="file-upload"
              class="relative cursor-pointer rounded-md bg-transparent font-semibold text-blue-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-600 focus-within:ring-offset-2 hover:text-blue-500"
            >
              <span>เลือกไฟล์จากเครื่อง</span>
              <input
                id="file-upload"
                name="file-upload"
                type="file"
                accept=".csv,text/csv"
                class="sr-only"
                @change="handleFileSelect"
              />
            </label>
            <p class="pl-1">หรือลากไฟล์มาวางที่นี่</p>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            ไฟล์ CSV ขนาดสูงสุดไม่เกิน 10 MB (สูงสุด 5,000 แถวต่อไฟล์)
          </p>
        </div>
      </div>

      <!-- Selected File Card -->
      <div
        v-else
        class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 p-4"
      >
        <div class="flex items-center space-x-3">
          <div
            class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600"
          >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
          </div>
          <div>
            <p class="text-sm font-medium text-gray-900">{{ fileName }}</p>
            <p class="text-xs text-gray-500">{{ formatFileSize(fileSize) }}</p>
          </div>
        </div>
        <button
          type="button"
          :disabled="loading"
          class="text-sm font-medium text-red-600 hover:text-red-800 disabled:opacity-50"
          @click="removeFile"
        >
          เปลี่ยนไฟล์
        </button>
      </div>

      <!-- Validation Error List -->
      <div v-if="validationErrors.file" class="mt-2 text-xs text-red-600">
        {{ validationErrors.file[0] }}
      </div>
    </div>

    <!-- Card: Conflict Mode Selection -->
    <div class="rounded-lg bg-surface p-6 shadow">
      <h2 class="text-base font-semibold text-gray-900 border-b border-gray-200 pb-3 mb-4">
        2. เลือกรูปแบบการจัดการข้อมูลซ้ำ (Conflict Mode)
      </h2>

      <fieldset class="space-y-3">
        <legend class="sr-only">เลือกรูปแบบการจัดการข้อมูลซ้ำ</legend>
        <div
          v-for="opt in conflictOptions"
          :key="opt.value"
          class="relative flex items-start rounded-lg border p-4 cursor-pointer transition-colors"
          :class="[
            conflictMode === opt.value
              ? 'border-blue-500 bg-blue-50/30 ring-1 ring-blue-500'
              : 'border-gray-200 hover:bg-gray-50',
          ]"
          @click="conflictMode = opt.value"
        >
          <div class="flex h-5 items-center">
            <input
              :id="`mode-${opt.value}`"
              v-model="conflictMode"
              :value="opt.value"
              name="conflict-mode"
              type="radio"
              class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
            />
          </div>
          <div class="ml-3 flex-1 text-sm">
            <div class="flex items-center gap-2">
              <label :for="`mode-${opt.value}`" class="font-medium text-gray-900 cursor-pointer">
                {{ opt.title }}
              </label>
              <span
                class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium ring-1 ring-inset"
                :class="opt.badgeClass"
              >
                {{ opt.badge }}
              </span>
            </div>
            <p class="text-xs text-gray-500 mt-1 leading-relaxed">{{ opt.description }}</p>
          </div>
        </div>
      </fieldset>

      <!-- Identity Safety Notice -->
      <div class="mt-4 rounded-md bg-blue-50 p-4 border border-blue-200 text-xs text-blue-900">
        <div class="flex">
          <svg
            class="h-4 w-4 text-blue-600 mr-2 shrink-0 mt-0.5"
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
          <div>
            <strong class="font-semibold">ความปลอดภัยของข้อมูลบุคคล (Identity Invariant):</strong>
            โหมดอัปเดต (UPDATE_EXISTING)
            จะอนุญาตให้อัปเดตเฉพาะประวัติการศึกษาของนักเรียนเดิมที่มีข้อมูลระบุตัวบุคคล (Identity)
            ตรงกันเท่านั้น หากตรวจพบว่าข้อมูลบุคคลไม่ตรงกัน (Identity Mismatch)
            ระบบจะปฏิเสธการอัปเดตเพื่อป้องกันการสวมสิทธิ์ระเบียนข้อมูล
          </div>
        </div>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="flex justify-end space-x-3">
      <router-link
        to="/students"
        class="inline-flex items-center rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        ยกเลิก
      </router-link>
      <button
        type="button"
        :disabled="loading || !file"
        class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
        @click="handleUpload"
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
        <span>{{ loading ? 'กำลังอัปโหลดและตรวจสอบ...' : 'อัปโหลดและตรวจสอบข้อมูล (Next)' }}</span>
      </button>
    </div>
  </div>
</template>
