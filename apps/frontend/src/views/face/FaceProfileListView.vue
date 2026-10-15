<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'
import { apiErrorText, statusLabel } from '@/constants/labels'

const router = useRouter()
const authStore = useAuthStore()

const profiles = ref([])
const loading = ref(false)
const errorMessage = ref('')

const filters = reactive({
  search: '',
  status: '',
  page: 1,
  per_page: 15,
})

const pagination = reactive({
  current_page: 1,
  per_page: 15,
  total: 0,
  last_page: 1,
})

// Modals
const showRevokeModal = ref(false)
const targetProfile = ref(null)
const revokeReason = ref('')
const revokeLoading = ref(false)
const revokeError = ref('')

const showReenrollModal = ref(false)
const reenrollFile = ref(null)
const reenrollReason = ref('')
const reenrollLoading = ref(false)
const reenrollError = ref('')

const canView = computed(() => authStore.can('face.view'))
const canRevoke = computed(() => authStore.can('face.revoke'))
const canReenroll = computed(() => authStore.can('face.reenroll'))
const canEnroll = computed(() => authStore.can('face.enroll'))

async function fetchProfiles() {
  if (!canView.value) return

  loading.value = true
  errorMessage.value = ''

  try {
    const res = await facesService.listProfiles({
      search: filters.search || undefined,
      status: filters.status || undefined,
      page: filters.page,
      per_page: filters.per_page,
    })

    profiles.value = res.data || []
    if (res.meta) {
      pagination.current_page = res.meta.current_page
      pagination.last_page = res.meta.last_page
      pagination.total = res.meta.total
    }
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดข้อมูลใบหน้าไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function openRevokeModal(profile) {
  targetProfile.value = profile
  revokeReason.value = ''
  revokeError.value = ''
  showRevokeModal.value = true
}

async function submitRevoke() {
  if (!revokeReason.value.trim()) {
    revokeError.value = 'กรุณาระบุเหตุผลการเพิกถอน'
    return
  }

  revokeLoading.value = true
  revokeError.value = ''

  try {
    await facesService.revokeProfile(
      targetProfile.value.face_profile_uuid,
      revokeReason.value.trim(),
    )
    showRevokeModal.value = false
    await fetchProfiles()
  } catch (err) {
    revokeError.value = apiErrorText(err, 'เพิกถอนไม่สำเร็จ')
  } finally {
    revokeLoading.value = false
  }
}

function openReenrollModal(profile) {
  targetProfile.value = profile
  reenrollFile.value = null
  reenrollReason.value = ''
  reenrollError.value = ''
  showReenrollModal.value = true
}

function onReenrollFileSelected(event) {
  reenrollFile.value = event.target.files?.[0] || null
}

async function submitReenroll() {
  if (!reenrollFile.value) {
    reenrollError.value = 'กรุณาเลือกรูปใบหน้าใหม่'
    return
  }
  if (!reenrollReason.value.trim()) {
    reenrollError.value = 'กรุณาระบุเหตุผลการลงทะเบียนใหม่'
    return
  }

  reenrollLoading.value = true
  reenrollError.value = ''

  const formData = new FormData()
  formData.append('image', reenrollFile.value)
  formData.append('reason', reenrollReason.value.trim())

  try {
    await facesService.reenrollProfile(targetProfile.value.face_profile_uuid, formData)
    showReenrollModal.value = false
    await fetchProfiles()
  } catch (err) {
    reenrollError.value = apiErrorText(err, 'ลงทะเบียนใหม่ไม่สำเร็จ')
  } finally {
    reenrollLoading.value = false
  }
}

function getStatusBadgeClass(status) {
  switch (status) {
    case 'ACTIVE':
      return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
    case 'PENDING':
      return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300'
    case 'REVOKED':
      return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300'
    case 'RE_ENROLL_REQUIRED':
      return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300'
    default:
      return 'bg-gray-100 text-gray-800'
  }
}

onMounted(() => {
  fetchProfiles()
})
</script>

<template>
  <div class="face-profiles-view max-w-7xl mx-auto p-6 space-y-6">
    <!-- Header -->
    <div
      class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4"
    >
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">ข้อมูลใบหน้า</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          จัดการข้อมูลใบหน้าที่ลงทะเบียน การเพิกถอน และประวัติภาพตัวอย่าง
        </p>
      </div>
      <div class="flex space-x-3">
        <button
          v-if="canEnroll"
          @click="router.push({ name: 'faces.enroll' })"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
        >
          + ลงทะเบียนใบหน้า
        </button>
      </div>
    </div>

    <!-- Filters Bar -->
    <div
      class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-wrap gap-4 items-center"
    >
      <input
        v-model="filters.search"
        @keyup.enter="fetchProfiles"
        type="text"
        placeholder="ค้นหาด้วยรหัสบุคคล รหัสโปรไฟล์ หรือชื่อ..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      />

      <select
        v-model="filters.status"
        @change="fetchProfiles"
        class="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      >
        <option value="">ทุกสถานะ</option>
        <option value="ACTIVE">ใช้งาน</option>
        <option value="PENDING">รอดำเนินการ</option>
        <option value="REVOKED">ถูกเพิกถอน</option>
        <option value="RE_ENROLL_REQUIRED">ต้องลงทะเบียนใหม่</option>
      </select>

      <button
        @click="fetchProfiles"
        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
      >
        ค้นหา
      </button>
    </div>

    <!-- Error Alert -->
    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <!-- Table -->
    <div
      class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden"
    >
      <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลดข้อมูลใบหน้า...</div>

      <div v-else-if="profiles.length === 0" class="text-center py-12 text-gray-400">
        ไม่พบข้อมูลใบหน้าตามเงื่อนไข
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
        <thead class="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">บุคคล / โปรไฟล์</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">สถานะ</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">โมเดลที่ใช้</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">คุณภาพ</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">ลงทะเบียนเมื่อ</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase">การดำเนินการ</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr
            v-for="profile in profiles"
            :key="profile.face_profile_uuid"
            class="hover:bg-gray-50 dark:hover:bg-gray-700/30"
          >
            <td class="px-6 py-4">
              <div class="font-mono text-xs font-semibold text-gray-900 dark:text-white">
                {{ profile.face_profile_uuid }}
              </div>
              <div class="text-xs text-gray-500">บุคคล: {{ profile.person_uuid }}</div>
            </td>
            <td class="px-6 py-4">
              <span
                class="px-2.5 py-1 rounded-full text-xs font-semibold"
                :class="getStatusBadgeClass(profile.status)"
              >
                {{ statusLabel(profile.status) }}
              </span>
            </td>
            <td class="px-6 py-4 text-xs">
              <div class="font-medium text-gray-800 dark:text-gray-200">
                {{ profile.model?.code || 'ไม่ระบุ' }}
              </div>
              <div class="text-gray-400">v{{ profile.model?.version || '1.0' }}</div>
            </td>
            <td class="px-6 py-4 text-xs font-semibold">
              <span v-if="profile.quality_score"
                >{{ (profile.quality_score * 100).toFixed(1) }}%</span
              >
              <span v-else class="text-gray-400">-</span>
            </td>
            <td class="px-6 py-4 text-xs text-gray-500">
              {{
                profile.enrolled_at
                  ? new Date(profile.enrolled_at).toLocaleDateString('th-TH')
                  : '-'
              }}
            </td>
            <td class="px-6 py-4 text-right space-x-2">
              <button
                @click="
                  router.push({ name: 'faces.show', params: { uuid: profile.face_profile_uuid } })
                "
                class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
              >
                ดูรายละเอียด
              </button>
              <button
                v-if="canReenroll"
                @click="openReenrollModal(profile)"
                class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-xs"
              >
                ลงทะเบียนใหม่
              </button>
              <button
                v-if="canRevoke && profile.status !== 'REVOKED'"
                @click="openRevokeModal(profile)"
                class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs"
              >
                เพิกถอน
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Revoke Modal -->
    <div
      v-if="showRevokeModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"
    >
      <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">เพิกถอนข้อมูลใบหน้า</h3>
        <p class="text-sm text-gray-500">
          เมื่อเพิกถอนแล้ว ระบบจะไม่ใช้ข้อมูลใบหน้านี้ในการจดจำและนโยบายการเข้าออกอีก
        </p>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            เหตุผลการเพิกถอน <span class="text-red-500">*</span>
          </label>
          <input
            v-model="revokeReason"
            type="text"
            placeholder="เช่น ถอนความยินยอม นักเรียนจบการศึกษา"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>

        <div v-if="revokeError" class="p-2 bg-red-50 text-red-700 rounded text-xs">
          {{ revokeError }}
        </div>

        <div class="flex justify-end space-x-3 pt-2">
          <button
            @click="showRevokeModal = false"
            class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ยกเลิก
          </button>
          <button
            @click="submitRevoke"
            :disabled="revokeLoading || !revokeReason"
            class="px-4 py-2 text-sm text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50"
          >
            <span v-if="revokeLoading">กำลังเพิกถอน...</span>
            <span v-else>ยืนยันการเพิกถอน</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Re-enroll Modal -->
    <div
      v-if="showReenrollModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50"
    >
      <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">ลงทะเบียนใบหน้าใหม่</h3>
        <p class="text-sm text-gray-500">
          อัปโหลดรูปใบหน้าใหม่เพื่อเปิดใช้งานหรือปรับปรุงข้อมูลใบหน้าของบุคคลนี้
        </p>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            รูปใบหน้าใหม่ <span class="text-red-500">*</span>
          </label>
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            @change="onReenrollFileSelected"
            class="w-full text-xs text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-blue-50 file:text-blue-700"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            เหตุผลการลงทะเบียนใหม่ <span class="text-red-500">*</span>
          </label>
          <input
            v-model="reenrollReason"
            type="text"
            placeholder="เช่น ปรับรูปสำหรับภาคเรียนใหม่"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>

        <div v-if="reenrollError" class="p-2 bg-red-50 text-red-700 rounded text-xs">
          {{ reenrollError }}
        </div>

        <div class="flex justify-end space-x-3 pt-2">
          <button
            @click="showReenrollModal = false"
            class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
          >
            ยกเลิก
          </button>
          <button
            @click="submitReenroll"
            :disabled="reenrollLoading || !reenrollFile || !reenrollReason"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            <span v-if="reenrollLoading">กำลังดำเนินการ...</span>
            <span v-else>ยืนยันการลงทะเบียนใหม่</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
