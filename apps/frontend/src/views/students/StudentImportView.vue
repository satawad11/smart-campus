<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { studentImportService } from '@/services/students'
import ImportUploadStep from '@/components/students/import/ImportUploadStep.vue'
import ImportPreviewStep from '@/components/students/import/ImportPreviewStep.vue'
import ImportErrorsTable from '@/components/students/import/ImportErrorsTable.vue'
import ImportProcessingStep from '@/components/students/import/ImportProcessingStep.vue'
import ImportSummaryStep from '@/components/students/import/ImportSummaryStep.vue'

const route = useRoute()
const authStore = useAuthStore()

const currentStep = ref('upload') // 'upload' | 'preview' | 'errors' | 'processing' | 'summary'
const importJob = ref(null)
const previewData = ref(null)
const loading = ref(false)
const errorMessage = ref('')

const steps = [
  { id: 'upload', name: '1. อัปโหลดไฟล์' },
  { id: 'preview', name: '2. ตรวจสอบข้อมูล' },
  { id: 'errors', name: '3. รายการข้อผิดพลาด' },
  { id: 'processing', name: '4. ประมวลผล' },
  { id: 'summary', name: '5. สรุปผล' },
]

async function handleUploaded(job) {
  importJob.value = job
  loading.value = true
  errorMessage.value = ''

  try {
    const data = await studentImportService.preview(job.uuid)
    previewData.value = data
    currentStep.value = 'preview'
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถโหลดข้อมูลตัวอย่างได้'
    currentStep.value = 'preview'
  } finally {
    loading.value = false
  }
}

function handleReviewErrors() {
  currentStep.value = 'errors'
}

function handleBackToPreview() {
  currentStep.value = 'preview'
}

function handleProceedToProcess() {
  currentStep.value = 'processing'
}

function handleCompleted(job) {
  importJob.value = job
  currentStep.value = 'summary'
}

function handleFailed(job) {
  importJob.value = job
  currentStep.value = 'summary'
}

function handleNewImport() {
  importJob.value = null
  previewData.value = null
  errorMessage.value = ''
  currentStep.value = 'upload'
}

onMounted(async () => {
  // If route query contains job uuid, optionally restore preview
  const jobUuid = route.query.job
  if (jobUuid && typeof jobUuid === 'string') {
    loading.value = true
    try {
      const job = await studentImportService.show(jobUuid)
      importJob.value = job
      if (job.status === 'COMPLETED' || job.status === 'FAILED' || job.status === 'CANCELLED') {
        currentStep.value = 'summary'
      } else if (job.status === 'PROCESSING') {
        currentStep.value = 'processing'
      } else {
        const pData = await studentImportService.preview(job.uuid)
        previewData.value = pData
        currentStep.value = 'preview'
      }
    } catch {
      // Fallback to upload step
      currentStep.value = 'upload'
    } finally {
      loading.value = false
    }
  }
})
</script>

<template>
  <div class="max-w-5xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div>
      <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
        <router-link to="/students" class="hover:text-blue-600">จัดการข้อมูลนักเรียน</router-link>
        <span>/</span>
        <span class="text-gray-900 font-medium">นำเข้าข้อมูลนักเรียน (Import Wizard)</span>
      </div>
      <h1 class="text-2xl font-bold text-gray-900">
        ระบบนำเข้าข้อมูลนักเรียน (Student Import Wizard)
      </h1>
      <p class="mt-1 text-sm text-gray-500">
        นำเข้าข้อมูลนักเรียนจากไฟล์ CSV พร้อมระบบตรวจสอบความถูกต้องและจัดการความขัดแย้งของข้อมูล
      </p>
    </div>

    <!-- Permission Guard Check -->
    <div
      v-if="!authStore.can('student.import')"
      class="rounded-lg bg-red-50 p-6 text-center border border-red-200"
    >
      <svg
        class="mx-auto h-12 w-12 text-red-500"
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
      <h3 class="mt-3 text-lg font-medium text-red-800">ไม่มีสิทธิ์เข้าถึง</h3>
      <p class="mt-1 text-sm text-red-600">
        คุณไม่มีสิทธิ์ในการนำเข้าข้อมูลนักเรียน กรุณาติดต่อผู้ดูแลระบบเพื่อขอสิทธิ์ student.import
      </p>
      <div class="mt-4">
        <router-link
          to="/students"
          class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
        >
          กลับสู่หน้ารายการนักเรียน
        </router-link>
      </div>
    </div>

    <div v-else class="space-y-6">
      <!-- Steps Progress Bar -->
      <nav aria-label="Progress" class="rounded-lg bg-white p-4 shadow">
        <ol role="list" class="flex items-center justify-between text-xs font-medium text-gray-500">
          <li
            v-for="(step, idx) in steps"
            :key="step.id"
            class="flex items-center space-x-2"
            :class="[currentStep === step.id ? 'text-blue-600 font-semibold' : 'text-gray-400']"
          >
            <span
              class="flex h-6 w-6 items-center justify-center rounded-full border text-xs"
              :class="[
                currentStep === step.id
                  ? 'border-blue-600 bg-blue-50 text-blue-600 font-bold'
                  : 'border-gray-300 bg-gray-50 text-gray-500',
              ]"
            >
              {{ idx + 1 }}
            </span>
            <span class="hidden sm:inline">{{ step.name }}</span>
          </li>
        </ol>
      </nav>

      <!-- Loading State -->
      <div v-if="loading" class="rounded-lg bg-white p-12 text-center shadow">
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
        <p class="mt-3 text-sm text-gray-500">กำลังโหลดข้อมูล...</p>
      </div>

      <!-- Step 1: Upload Step -->
      <ImportUploadStep v-else-if="currentStep === 'upload'" @uploaded="handleUploaded" />

      <!-- Step 2: Preview Step -->
      <ImportPreviewStep
        v-else-if="currentStep === 'preview'"
        :job="importJob"
        :preview-data="previewData"
        @review-errors="handleReviewErrors"
        @proceed="handleProceedToProcess"
        @reset="handleNewImport"
      />

      <!-- Step 3: Errors Review Step -->
      <ImportErrorsTable
        v-else-if="currentStep === 'errors'"
        :job-uuid="importJob?.uuid"
        @back="handleBackToPreview"
        @proceed="handleProceedToProcess"
      />

      <!-- Step 4: Processing Step -->
      <ImportProcessingStep
        v-else-if="currentStep === 'processing'"
        :job="importJob"
        @completed="handleCompleted"
        @failed="handleFailed"
        @back="handleBackToPreview"
      />

      <!-- Step 5: Summary Step -->
      <ImportSummaryStep
        v-else-if="currentStep === 'summary'"
        :job="importJob"
        @new-import="handleNewImport"
      />
    </div>
  </div>
</template>
