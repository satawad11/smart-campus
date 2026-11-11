<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { licenseService } from '@/services/license'

/**
 * Licence status and installation (COM-02). Viewing needs system.view;
 * installing a licence or a revocation list needs system.manage (the
 * backend enforces both; hiding the form is only convenience).
 */
const authStore = useAuthStore()
const canManage = computed(() => authStore.can('system.manage'))

const state = ref(null)
const loading = ref(false)
const busy = ref(false)
const document = ref('')
const errorMessage = ref('')
const successMessage = ref('')

const stateLabels = {
  NONE: { text: 'ยังไม่ได้ติดตั้ง License', cls: 'bg-gray-100 text-gray-800' },
  VALID: { text: 'ใช้งานได้', cls: 'bg-green-100 text-green-800' },
  EXPIRING: { text: 'ใกล้หมดอายุ', cls: 'bg-amber-100 text-amber-800' },
  GRACE: { text: 'หมดอายุแล้ว (ช่วงผ่อนผัน)', cls: 'bg-orange-100 text-orange-800' },
  EXPIRED: { text: 'หมดอายุ', cls: 'bg-red-100 text-red-800' },
  REVOKED: { text: 'ถูกเพิกถอน', cls: 'bg-red-100 text-red-800' },
  INVALID: { text: 'ไม่ถูกต้อง', cls: 'bg-red-100 text-red-800' },
  NOT_YET_VALID: { text: 'ยังไม่ถึงวันเริ่มใช้', cls: 'bg-gray-100 text-gray-800' },
}

const errorMap = {
  LICENSE_MALFORMED: 'ไฟล์ License ไม่ถูกต้อง',
  LICENSE_UNTRUSTED_KEY: 'License ลงนามด้วย key ที่ระบบไม่รู้จัก',
  LICENSE_SIGNATURE_INVALID: 'ลายเซ็นของ License ไม่ถูกต้อง (ไฟล์อาจถูกแก้ไข)',
  LICENSE_WRONG_TYPE: 'ไฟล์นี้ไม่ใช่ประเภทที่ถูกต้อง (License / รายการเพิกถอน)',
  LICENSE_INSTALLATION_MISMATCH: 'License นี้ออกให้กับการติดตั้งอื่น',
  LICENSE_REVOKED: 'License นี้ถูกเพิกถอนแล้ว',
  LICENSE_EXPIRED: 'License นี้หมดอายุแล้ว',
  LICENSE_OLDER_THAN_ACTIVE: 'License นี้ออกก่อน License ที่ใช้งานอยู่',
  LICENSE_CRYPTO_UNAVAILABLE: 'เซิร์ฟเวอร์ไม่รองรับการตรวจลายเซ็น (ต้องมี PHP sodium)',
  REVOCATION_LIST_STALE: 'รายการเพิกถอนนี้ไม่ใหม่กว่ารายการที่นำเข้าแล้ว',
  VALIDATION_ERROR: 'กรุณาวางเนื้อหาไฟล์',
}

const label = computed(
  () =>
    stateLabels[state.value?.state] || {
      text: state.value?.state,
      cls: 'bg-gray-100 text-gray-800',
    },
)
const lic = computed(() => state.value?.license || null)

function formatDate(value) {
  return value ? new Date(value).toLocaleString('th-TH') : '-'
}

function limitText(value) {
  return value === null || value === undefined ? 'ไม่จำกัด' : value
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    state.value = await licenseService.getStatus()
  } catch (err) {
    errorMessage.value = err?.message || 'โหลดสถานะ License ไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}

async function submit(kind) {
  errorMessage.value = ''
  successMessage.value = ''
  if (!document.value.trim()) {
    errorMessage.value = errorMap.VALIDATION_ERROR
    return
  }
  busy.value = true
  try {
    if (kind === 'license') {
      state.value = await licenseService.install(document.value)
      successMessage.value = 'ติดตั้ง License สำเร็จ'
    } else {
      const result = await licenseService.importRevocations(document.value)
      state.value = result.license
      successMessage.value = `นำเข้ารายการเพิกถอนสำเร็จ (${result.newly_revoked} รายการใหม่)`
    }
    document.value = ''
  } catch (err) {
    errorMessage.value = errorMap[err?.code] || err?.message || 'ดำเนินการไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

async function loadFile(event) {
  const file = event.target.files?.[0]
  if (file) {
    document.value = await file.text()
  }
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">License</h1>
      <p class="mt-1 text-sm text-gray-500">
        สถานะสิทธิ์การใช้งานของระบบนี้ ตรวจสอบแบบออฟไลน์ด้วยลายเซ็นดิจิทัล
      </p>
    </div>

    <div
      v-if="successMessage"
      class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800"
      role="status"
    >
      {{ successMessage }}
    </div>
    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <section v-if="state" class="rounded-lg border border-gray-200 bg-surface p-6 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">สถานะ</h2>
        <span
          data-test="license-state"
          class="rounded-full px-2.5 py-0.5 text-xs font-medium"
          :class="label.cls"
        >
          {{ label.text }}
        </span>
      </div>
      <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-gray-500">Installation ID (ส่งให้ผู้จำหน่ายเพื่อขอ License)</dt>
          <dd data-test="installation-id" class="select-all font-mono text-gray-900">
            {{ state.installation_id }}
          </dd>
        </div>
        <div>
          <dt class="text-gray-500">โหมดการบังคับใช้</dt>
          <dd class="text-gray-900">{{ state.enforcement }}</dd>
        </div>
        <template v-if="lic">
          <div>
            <dt class="text-gray-500">ผู้ได้รับสิทธิ์</dt>
            <dd class="text-gray-900">{{ lic.licensee_name }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">Edition / Plan</dt>
            <dd class="text-gray-900">{{ lic.edition }} / {{ lic.plan }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">กล้องสูงสุด / AI Node สูงสุด</dt>
            <dd data-test="license-limits" class="text-gray-900">
              {{ limitText(lic.limits.max_cameras) }} / {{ limitText(lic.limits.max_ai_nodes) }}
            </dd>
          </div>
          <div>
            <dt class="text-gray-500">ฟีเจอร์</dt>
            <dd class="text-gray-900">{{ lic.features.join(', ') }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">หมดอายุ</dt>
            <dd class="text-gray-900">
              {{ formatDate(lic.expires_at) }} (เหลือ {{ lic.days_until_expiry }} วัน)
            </dd>
          </div>
          <div>
            <dt class="text-gray-500">สิ้นสุดช่วงผ่อนผัน</dt>
            <dd class="text-gray-900">{{ formatDate(lic.grace_ends_at) }}</dd>
          </div>
        </template>
      </dl>
      <p v-if="state.clock_rollback_detected" class="mt-4 text-sm text-amber-700">
        ตรวจพบนาฬิการะบบย้อนหลัง กรุณาตรวจสอบ NTP
      </p>
      <p class="mt-4 text-xs text-gray-500">
        License หมดอายุจะไม่ลบข้อมูลและไม่ปิดการดู รายงาน หรือการส่งออกข้อมูล
        จำกัดเฉพาะการเพิ่มกล้อง AI Node หรือการลงทะเบียนใบหน้าใหม่
      </p>
    </section>
    <div v-else-if="loading" class="text-sm text-gray-500">กำลังโหลด...</div>

    <section v-if="canManage" class="rounded-lg border border-gray-200 bg-surface p-6 shadow-sm">
      <h2 class="text-lg font-semibold text-gray-900">ติดตั้ง License / รายการเพิกถอน</h2>
      <div class="mt-3 space-y-3">
        <input type="file" accept=".lic,.json,.txt" class="text-sm" @change="loadFile" />
        <textarea
          v-model="document"
          data-test="license-document"
          rows="6"
          class="block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-xs shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          placeholder="วางเนื้อหาไฟล์ .lic ที่นี่"
        ></textarea>
        <div class="flex gap-3">
          <button
            data-test="install-license"
            type="button"
            :disabled="busy"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
            @click="submit('license')"
          >
            ติดตั้ง License
          </button>
          <button
            data-test="import-revocations"
            type="button"
            :disabled="busy"
            class="rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            @click="submit('revocations')"
          >
            นำเข้ารายการเพิกถอน
          </button>
        </div>
      </div>
    </section>
  </div>
</template>
