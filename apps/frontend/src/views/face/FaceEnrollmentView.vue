<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'
import { apiErrorText, statusLabel } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

// State
const personUuid = ref('')
const selectedFile = ref(null)
const previewUrl = ref('')
const pose = ref('FRONT')
const source = ref('UPLOAD')
const loading = ref(false)
const errorMessage = ref('')
const successData = ref(null)
const duplicateReviewData = ref(null)

const canEnroll = computed(() => authStore.can('face.enroll'))

function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  selectedFile.value = file
  previewUrl.value = URL.createObjectURL(file)
  errorMessage.value = ''
  successData.value = null
  duplicateReviewData.value = null
}

async function handleEnroll() {
  if (!personUuid.value.trim()) {
    errorMessage.value = 'กรุณาระบุรหัสบุคคล'
    return
  }

  if (!selectedFile.value) {
    errorMessage.value = 'กรุณาเลือกหรือถ่ายภาพใบหน้า'
    return
  }

  loading.value = true
  errorMessage.value = ''
  successData.value = null
  duplicateReviewData.value = null

  const formData = new FormData()
  formData.append('image', selectedFile.value)
  formData.append('person_uuid', personUuid.value.trim())
  formData.append('pose', pose.value)
  formData.append('source', source.value)

  try {
    const res = await facesService.enrollFace(formData, personUuid.value.trim())
    successData.value = res.data

    // Check for potential duplicate review candidate
    try {
      const dupRes = await facesService.checkDuplicateReview(personUuid.value.trim(), true)
      if (dupRes?.data?.duplicate_found) {
        duplicateReviewData.value = dupRes.data
      }
    } catch {
      // Duplicate review check failure is non-fatal to completed enrollment
    }
  } catch (err) {
    errorMessage.value = apiErrorText(
      err,
      'ลงทะเบียนใบหน้าไม่สำเร็จ กรุณาตรวจสอบคุณภาพภาพแล้วลองใหม่',
    )
  } finally {
    loading.value = false
  }
}

function resetForm() {
  selectedFile.value = null
  previewUrl.value = ''
  successData.value = null
  duplicateReviewData.value = null
  errorMessage.value = ''
}
</script>

<template>
  <div class="face-enrollment-container max-w-4xl mx-auto p-6 space-y-6">
    <div class="flex items-center justify-between border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">ลงทะเบียนใบหน้า</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          ลงทะเบียนข้อมูลใบหน้าให้กับบุคคลในระบบ
        </p>
      </div>
      <button
        @click="router.push({ name: 'faces.index' })"
        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
      >
        ดูข้อมูลใบหน้าทั้งหมด
      </button>
    </div>

    <!-- Permission Guard Warning -->
    <div v-if="!canEnroll" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
      คุณไม่มีสิทธิ์ลงทะเบียนใบหน้า
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Input Panel -->
      <div
        class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 space-y-4"
      >
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
          1. บุคคลและภาพตัวอย่าง
        </h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            รหัสบุคคล (UUID) <span class="text-red-500">*</span>
          </label>
          <input
            v-model="personUuid"
            type="text"
            placeholder="00000000-0000-0000-0000-000000000000"
            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >ท่าทางใบหน้า</label
          >
          <select
            v-model="pose"
            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm"
          >
            <option value="FRONT">หน้าตรง</option>
            <option value="LEFT">หันซ้าย</option>
            <option value="RIGHT">หันขวา</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >ภาพใบหน้า</label
          >
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="onFileSelected"
            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
          />
        </div>

        <div v-if="previewUrl" class="mt-4">
          <p class="text-xs font-medium text-gray-500 mb-2">ตัวอย่างภาพ</p>
          <img
            :src="previewUrl"
            alt="Face Preview"
            class="w-full h-56 object-cover rounded-lg border"
          />
        </div>

        <button
          @click="handleEnroll"
          :disabled="loading || !selectedFile || !personUuid"
          class="w-full py-2.5 px-4 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center justify-center space-x-2"
        >
          <span v-if="loading">กำลังประมวลผลภาพ...</span>
          <span v-else>ลงทะเบียนใบหน้า</span>
        </button>

        <div
          v-if="errorMessage"
          class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
        >
          {{ errorMessage }}
        </div>
      </div>

      <!-- Result Panel -->
      <div
        class="bg-white dark:bg-gray-800 p-6 rounded-xl border border-gray-200 dark:border-gray-700 space-y-4"
      >
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
          2. ผลการตรวจสอบและคุณภาพภาพ
        </h2>

        <div v-if="!successData && !loading" class="text-center py-12 text-gray-400 text-sm">
          ผลการลงทะเบียน ค่าคุณภาพภาพ และโมเดลที่ใช้จะแสดงที่นี่หลังส่งข้อมูล
        </div>

        <div v-if="loading" class="flex flex-col items-center justify-center py-12 space-y-3">
          <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600"></div>
          <p class="text-sm text-gray-500">กำลังตรวจคุณภาพภาพ สร้างข้อมูลใบหน้า และบันทึก...</p>
        </div>

        <div v-if="successData" class="space-y-4">
          <div class="p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
            <p class="font-bold">✓ ลงทะเบียนใบหน้าสำเร็จ</p>
            <p class="text-xs text-green-700">สร้างและเปิดใช้งานข้อมูลใบหน้าเรียบร้อยแล้ว</p>
          </div>

          <!-- Profile Details -->
          <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-gray-500">รหัสโปรไฟล์:</span>
              <span class="font-mono font-medium">{{ successData.face_profile_uuid }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">สถานะ:</span>
              <span class="px-2 py-0.5 rounded text-xs font-bold bg-green-100 text-green-800">
                {{ statusLabel(successData.status) }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">โมเดลที่ใช้:</span>
              <span class="font-medium"
                >{{ successData.model?.code }} v{{ successData.model?.version }}</span
              >
            </div>
            <div class="flex justify-between">
              <span class="text-gray-500">คะแนนคุณภาพ:</span>
              <span class="font-bold text-blue-600"
                >{{ (successData.quality_score * 100).toFixed(1) }}%</span
              >
            </div>
          </div>

          <!-- Quality Metrics -->
          <div v-if="successData.sample" class="grid grid-cols-3 gap-2 text-center text-xs">
            <div class="p-2 bg-gray-100 rounded">
              <div class="text-gray-500">ความคมชัด</div>
              <div class="font-bold text-gray-800">
                {{ (successData.sample.blur_score * 100).toFixed(0) }}%
              </div>
            </div>
            <div class="p-2 bg-gray-100 rounded">
              <div class="text-gray-500">ความสว่าง</div>
              <div class="font-bold text-gray-800">
                {{ (successData.sample.brightness_score * 100).toFixed(0) }}%
              </div>
            </div>
            <div class="p-2 bg-gray-100 rounded">
              <div class="text-gray-500">ท่าทาง</div>
              <div class="font-bold text-gray-800">{{ successData.sample.pose }}</div>
            </div>
          </div>

          <!-- Duplicate Warning Banner -->
          <div
            v-if="duplicateReviewData"
            class="p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg"
          >
            <p class="font-bold text-sm">⚠️ พบใบหน้าที่อาจซ้ำกับบุคคลอื่น</p>
            <p class="text-xs mt-1">
              พบบุคคลที่ใบหน้าคล้ายกัน
              {{ (duplicateReviewData.top_candidate?.similarity * 100).toFixed(1) }}%
              ระบบสร้างรายการ ให้ผู้ดูแลตรวจสอบแล้ว
            </p>
          </div>

          <button
            @click="resetForm"
            class="w-full py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 text-sm"
          >
            ลงทะเบียนบุคคลถัดไป
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
