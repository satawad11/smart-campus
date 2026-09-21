<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import facesService from '@/services/faces'

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
    errorMessage.value = err.message || 'Failed to load face profiles.'
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
    revokeError.value = 'Revocation reason is required.'
    return
  }

  revokeLoading.value = true
  revokeError.value = ''

  try {
    await facesService.revokeProfile(targetProfile.value.face_profile_uuid, revokeReason.value.trim())
    showRevokeModal.value = false
    await fetchProfiles()
  } catch (err) {
    revokeError.value = err.message || 'Revocation failed.'
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
    reenrollError.value = 'New face photo is required.'
    return
  }
  if (!reenrollReason.value.trim()) {
    reenrollError.value = 'Re-enrollment reason is required.'
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
    reenrollError.value = err.message || 'Re-enrollment failed.'
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
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Face Biometric Profiles</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
          Manage enrolled biometrics, revocation lifecycle, and sample history (FACE-017).
        </p>
      </div>
      <div class="flex space-x-3">
        <button
          v-if="canEnroll"
          @click="router.push({ name: 'faces.enroll' })"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 transition"
        >
          + New Enrollment
        </button>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex flex-wrap gap-4 items-center">
      <input
        v-model="filters.search"
        @keyup.enter="fetchProfiles"
        type="text"
        placeholder="Search Person UUID, Profile UUID, or Name..."
        class="flex-1 min-w-[240px] px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      />

      <select
        v-model="filters.status"
        @change="fetchProfiles"
        class="px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
      >
        <option value="">All Statuses</option>
        <option value="ACTIVE">ACTIVE</option>
        <option value="PENDING">PENDING</option>
        <option value="REVOKED">REVOKED</option>
        <option value="RE_ENROLL_REQUIRED">RE_ENROLL_REQUIRED</option>
      </select>

      <button
        @click="fetchProfiles"
        class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-sm font-medium"
      >
        Search
      </button>
    </div>

    <!-- Error Alert -->
    <div v-if="errorMessage" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
      {{ errorMessage }}
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      <div v-if="loading" class="text-center py-12 text-gray-500">Loading face profiles...</div>

      <div v-else-if="profiles.length === 0" class="text-center py-12 text-gray-400">
        No face profiles found matching criteria.
      </div>

      <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
        <thead class="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Person / Profile</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Status</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Model Provenance</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Quality</th>
            <th class="px-6 py-3 text-left font-medium text-gray-500 uppercase">Enrolled At</th>
            <th class="px-6 py-3 text-right font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
          <tr v-for="profile in profiles" :key="profile.face_profile_uuid" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
            <td class="px-6 py-4">
              <div class="font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ profile.face_profile_uuid }}</div>
              <div class="text-xs text-gray-500">Person: {{ profile.person_uuid }}</div>
            </td>
            <td class="px-6 py-4">
              <span class="px-2.5 py-1 rounded-full text-xs font-semibold" :class="getStatusBadgeClass(profile.status)">
                {{ profile.status }}
              </span>
            </td>
            <td class="px-6 py-4 text-xs">
              <div class="font-medium text-gray-800 dark:text-gray-200">{{ profile.model?.code || 'N/A' }}</div>
              <div class="text-gray-400">v{{ profile.model?.version || '1.0' }}</div>
            </td>
            <td class="px-6 py-4 text-xs font-semibold">
              <span v-if="profile.quality_score">{{ (profile.quality_score * 100).toFixed(1) }}%</span>
              <span v-else class="text-gray-400">-</span>
            </td>
            <td class="px-6 py-4 text-xs text-gray-500">
              {{ profile.enrolled_at ? new Date(profile.enrolled_at).toLocaleDateString() : '-' }}
            </td>
            <td class="px-6 py-4 text-right space-x-2">
              <button
                @click="router.push({ name: 'faces.show', params: { uuid: profile.face_profile_uuid } })"
                class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-xs"
              >
                Detail
              </button>
              <button
                v-if="canReenroll"
                @click="openReenrollModal(profile)"
                class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded text-xs"
              >
                Re-enroll
              </button>
              <button
                v-if="canRevoke && profile.status !== 'REVOKED'"
                @click="openRevokeModal(profile)"
                class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs"
              >
                Revoke
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Revoke Modal -->
    <div v-if="showRevokeModal" class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
      <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Revoke Biometric Face Profile</h3>
        <p class="text-sm text-gray-500">
          Revoking will exclude this face profile from active recognition and door access policies.
        </p>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            Revocation Reason <span class="text-red-500">*</span>
          </label>
          <input
            v-model="revokeReason"
            type="text"
            placeholder="e.g. Consent withdrawn, student graduated"
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
            Cancel
          </button>
          <button
            @click="submitRevoke"
            :disabled="revokeLoading || !revokeReason"
            class="px-4 py-2 text-sm text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50"
          >
            <span v-if="revokeLoading">Revoking...</span>
            <span v-else>Confirm Revocation</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Re-enroll Modal -->
    <div v-if="showReenrollModal" class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
      <div class="bg-white dark:bg-gray-800 rounded-xl max-w-md w-full p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Re-enroll Biometric Face Profile</h3>
        <p class="text-sm text-gray-500">
          Upload a new face photo to reactivate or update baseline biometrics for this Person.
        </p>

        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            New Face Photo <span class="text-red-500">*</span>
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
            Re-enrollment Reason <span class="text-red-500">*</span>
          </label>
          <input
            v-model="reenrollReason"
            type="text"
            placeholder="e.g. Updated photo for new academic term"
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
            Cancel
          </button>
          <button
            @click="submitReenroll"
            :disabled="reenrollLoading || !reenrollFile || !reenrollReason"
            class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            <span v-if="reenrollLoading">Processing...</span>
            <span v-else>Submit Re-enrollment</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
