<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import facesService from '@/services/faces'
import { apiErrorText, statusLabel } from '@/constants/labels'

const route = useRoute()
const router = useRouter()

const profile = ref(null)
const loading = ref(true)
const errorMessage = ref('')

async function fetchProfile() {
  const uuid = route.params.uuid
  if (!uuid) return

  loading.value = true
  errorMessage.value = ''

  try {
    const res = await facesService.getProfile(uuid)
    profile.value = res.data
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายละเอียดข้อมูลใบหน้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchProfile()
})
</script>

<template>
  <div class="face-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <div class="flex items-center justify-between border-b pb-4">
      <div class="flex items-center space-x-4">
        <button
          @click="router.back()"
          class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg text-sm"
        >
          ← ย้อนกลับ
        </button>
        <div>
          <h1 class="text-2xl font-bold text-gray-900">รายละเอียดข้อมูลใบหน้า</h1>
          <p class="text-xs font-mono text-gray-500">
            {{ profile?.face_profile_uuid || route.params.uuid }}
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดรายละเอียด...</div>

    <div v-else-if="errorMessage" class="p-4 bg-red-50 text-red-700 rounded-lg text-sm">
      {{ errorMessage }}
    </div>

    <div v-else-if="profile" class="space-y-6">
      <!-- Status & Identity Card -->
      <div
        class="bg-surface p-6 rounded-xl border border-gray-200 grid grid-cols-1 md:grid-cols-2 gap-6"
      >
        <div>
          <h2 class="text-sm font-bold uppercase text-gray-400 mb-3">ข้อมูลตัวตน</h2>
          <div class="space-y-2 text-sm">
            <div>
              <span class="text-gray-500">รหัสบุคคล:</span>
              <span class="font-mono font-medium ml-2">{{ profile.person_uuid }}</span>
            </div>
            <div>
              <span class="text-gray-500">รหัสโปรไฟล์ใบหน้า:</span>
              <span class="font-mono font-medium ml-2">{{ profile.face_profile_uuid }}</span>
            </div>
            <div>
              <span class="text-gray-500">สถานะ:</span>
              <span
                class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-800"
              >
                {{ statusLabel(profile.status) }}
              </span>
            </div>
          </div>
        </div>

        <div>
          <h2 class="text-sm font-bold uppercase text-gray-400 mb-3">ข้อมูลการประมวลผล</h2>
          <div class="space-y-2 text-sm">
            <div>
              <span class="text-gray-500">โมเดล:</span>
              <span class="font-medium ml-2"
                >{{ profile.model?.code || 'ค่าเริ่มต้น' }} (v{{
                  profile.model?.version || '1.0'
                }})</span
              >
            </div>
            <div>
              <span class="text-gray-500">คะแนนคุณภาพ:</span>
              <span class="font-bold text-blue-600 ml-2">
                {{ profile.quality_score ? (profile.quality_score * 100).toFixed(1) + '%' : 'N/A' }}
              </span>
            </div>
            <div>
              <span class="text-gray-500">ลงทะเบียนเมื่อ:</span>
              <span class="ml-2">{{
                profile.enrolled_at ? new Date(profile.enrolled_at).toLocaleString('th-TH') : '-'
              }}</span>
            </div>
          </div>
        </div>

        <!-- Revocation Info if revoked -->
        <div
          v-if="profile.status === 'REVOKED'"
          class="col-span-1 md:col-span-2 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm"
        >
          <p class="font-bold">ข้อมูลใบหน้าถูกเพิกถอนแล้ว</p>
          <p class="text-xs mt-1">
            เพิกถอนเมื่อ: {{ new Date(profile.revoked_at).toLocaleString('th-TH') }}
          </p>
          <p class="text-xs mt-1">เหตุผล: {{ profile.revocation_reason }}</p>
        </div>
      </div>

      <!-- Sample History -->
      <div class="bg-surface p-6 rounded-xl border border-gray-200">
        <h2 class="text-base font-bold text-gray-900 mb-4">
          ประวัติภาพตัวอย่าง ({{ profile.samples_count || 1 }})
        </h2>

        <div
          v-if="profile.sample"
          class="p-4 border rounded-lg bg-gray-50 grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs"
        >
          <div>
            <div class="text-gray-400">รหัสภาพตัวอย่าง</div>
            <div class="font-mono font-medium">{{ profile.sample.uuid }}</div>
          </div>
          <div>
            <div class="text-gray-400">ท่าทาง / แหล่งที่มา</div>
            <div class="font-semibold">{{ profile.sample.pose }} / {{ profile.sample.source }}</div>
          </div>
          <div>
            <div class="text-gray-400">คุณภาพ / ความคมชัด</div>
            <div class="font-semibold">
              {{ (profile.sample.quality_score * 100).toFixed(0) }}% /
              {{ (profile.sample.blur_score * 100).toFixed(0) }}%
            </div>
          </div>
          <div>
            <div class="text-gray-400">ถ่ายเมื่อ</div>
            <div>
              {{
                profile.sample.captured_at
                  ? new Date(profile.sample.captured_at).toLocaleDateString('th-TH')
                  : '-'
              }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
