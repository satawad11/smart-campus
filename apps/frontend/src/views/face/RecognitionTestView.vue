<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'
import { apiErrorText } from '@/constants/labels'

const authStore = useAuthStore()

const probeFile = ref(null)
const previewUrl = ref('')
const topK = ref(5)
const minConfidence = ref(0.5)
const loading = ref(false)
const errorMessage = ref('')
const testResult = ref(null)

const canTest = computed(() => authStore.can('face.test'))

function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  probeFile.value = file
  previewUrl.value = URL.createObjectURL(file)
  testResult.value = null
  errorMessage.value = ''
}

async function runTest() {
  if (!probeFile.value) {
    errorMessage.value = 'กรุณาเลือกภาพใบหน้าที่ต้องการทดสอบ'
    return
  }

  loading.value = true
  errorMessage.value = ''
  testResult.value = null

  const formData = new FormData()
  formData.append('image', probeFile.value)
  formData.append('top_k', topK.value)
  formData.append('min_confidence', minConfidence.value)

  try {
    const res = await facesService.runRecognitionTest(formData)
    testResult.value = res.data
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ทดสอบการจดจำใบหน้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="recognition-test-view max-w-6xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900">ทดสอบการจดจำใบหน้า</h1>
      <p class="text-sm text-gray-500">
        เครื่องมือสำหรับผู้ดูแลระบบ ใช้ทดสอบการค้นหาใบหน้าที่คล้ายกันและเวลาที่ใช้ประมวลผล
      </p>
    </div>

    <!-- Permission Guard -->
    <div v-if="!canTest" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
      คุณไม่มีสิทธิ์ใช้เครื่องมือทดสอบการจดจำใบหน้า
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Probe Configuration -->
      <div class="bg-surface p-6 rounded-xl border border-gray-200 space-y-4">
        <h2 class="text-base font-bold text-gray-900">ตั้งค่าการทดสอบ</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">ภาพใบหน้าที่ใช้ทดสอบ</label>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="onFileSelected"
            class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700"
          />
        </div>

        <div v-if="previewUrl">
          <p class="text-xs text-gray-400 mb-1">ตัวอย่างภาพ</p>
          <img :src="previewUrl" alt="Probe" class="w-full h-44 object-cover rounded-lg border" />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"
            >จำนวนผลลัพธ์สูงสุด (1 - 20)</label
          >
          <input
            v-model.number="topK"
            type="number"
            min="1"
            max="20"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"
            >ความมั่นใจขั้นต่ำ (0.0 - 1.0)</label
          >
          <input
            v-model.number="minConfidence"
            type="number"
            step="0.05"
            min="0.0"
            max="1.0"
            class="w-full px-3 py-2 border rounded-lg text-sm"
          />
        </div>

        <button
          @click="runTest"
          :disabled="loading || !probeFile"
          class="w-full py-2.5 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition"
        >
          <span v-if="loading">กำลังค้นหาใบหน้าที่คล้ายกัน...</span>
          <span v-else>เริ่มทดสอบ</span>
        </button>

        <div v-if="errorMessage" class="p-3 bg-red-50 text-red-700 rounded-lg text-xs">
          {{ errorMessage }}
        </div>
      </div>

      <!-- Results Display -->
      <div class="lg:col-span-2 space-y-6">
        <div
          v-if="!testResult && !loading"
          class="bg-surface p-12 text-center rounded-xl border text-gray-400 text-sm"
        >
          เริ่มทดสอบเพื่อดูรายชื่อบุคคลที่ใบหน้าคล้ายที่สุด เรียงตามความคล้าย
        </div>

        <div v-if="testResult" class="space-y-4">
          <!-- Diagnostics & Latency Banner -->
          <div
            class="bg-surface p-4 rounded-xl border border-gray-200 grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs"
          >
            <div>
              <div class="text-gray-400">โมเดล</div>
              <div class="font-bold text-gray-900">
                {{ testResult.model?.code }}
              </div>
            </div>
            <div>
              <div class="text-gray-400">คุณภาพภาพ</div>
              <div class="font-bold text-blue-600">
                {{ (testResult.probe_quality_score * 100).toFixed(1) }}%
              </div>
            </div>
            <div>
              <div class="text-gray-400">เวลาที่ใช้ทั้งหมด</div>
              <div class="font-bold text-emerald-600">{{ testResult.latency_ms?.total_ms }} ms</div>
            </div>
            <div>
              <div class="text-gray-400">จำนวนที่พบ</div>
              <div class="font-bold text-gray-900">
                {{ testResult.candidates_count }}
              </div>
            </div>
          </div>

          <!-- Candidates List -->
          <div class="bg-surface rounded-xl border border-gray-200 overflow-hidden">
            <div class="p-4 border-b">
              <h3 class="font-bold text-sm text-gray-900">
                บุคคลที่ใบหน้าคล้ายกัน (เรียงตามความคล้าย)
              </h3>
            </div>

            <div
              v-if="testResult.candidates?.length === 0"
              class="p-8 text-center text-gray-400 text-sm"
            >
              ไม่พบบุคคลที่มีความมั่นใจเกิน
              {{ (minConfidence * 100).toFixed(0) }}%
            </div>

            <div v-else class="divide-y divide-gray-100">
              <div
                v-for="candidate in testResult.candidates"
                :key="candidate.rank"
                class="p-4 flex items-center justify-between hover:bg-gray-50 text-sm"
              >
                <div class="flex items-center space-x-4">
                  <span
                    class="w-7 h-7 flex items-center justify-center rounded-full bg-indigo-50 text-indigo-700 font-bold text-xs"
                  >
                    #{{ candidate.rank }}
                  </span>
                  <div>
                    <div class="font-mono text-xs font-semibold text-gray-900">
                      บุคคล: {{ candidate.person_uuid }}
                    </div>
                    <div class="text-xs text-gray-400 font-mono">
                      โปรไฟล์: {{ candidate.face_profile_uuid }}
                    </div>
                  </div>
                </div>

                <div class="text-right">
                  <div class="font-bold text-sm text-indigo-600">
                    {{ (candidate.similarity * 100).toFixed(2) }}%
                  </div>
                  <div class="text-xs text-gray-400">
                    ระยะห่าง: {{ candidate.distance?.toFixed(4) }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
