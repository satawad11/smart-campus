<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { authService } from '@/services/auth'
import SessionList from '@/components/account/SessionList.vue'

/**
 * Account security (V1 audit A-15): MFA enrollment, confirmation and
 * disabling for the signed-in user, using the existing AUTH-007 API.
 *
 * The TOTP secret lives only in this component's memory while enrolling and
 * is cleared on confirm, cancel or unmount. It is never sent to a third-party
 * QR service; the user types it into the authenticator app (or opens the
 * otpauth:// link on the phone).
 */
const authStore = useAuthStore()

const mfaEnabled = computed(() => !!authStore.user?.mfa_enabled)

const setup = ref(null)
const confirmCode = ref('')
const disablePassword = ref('')
const disableCode = ref('')
const busy = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const errorMap = {
  AUTH_MFA_ALREADY_ENABLED: 'บัญชีนี้เปิดใช้งาน MFA อยู่แล้ว',
  AUTH_MFA_INVALID_CODE: 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่',
  AUTH_MFA_NOT_ENABLED: 'บัญชีนี้ยังไม่ได้เปิดใช้งาน MFA',
  INVALID_CURRENT_PASSWORD: 'รหัสผ่านไม่ถูกต้อง',
  VALIDATION_ERROR: 'ข้อมูลไม่ครบถ้วนหรือไม่ถูกต้อง',
}

const groupedSecret = computed(() => (setup.value?.secret || '').replace(/(.{4})/g, '$1 ').trim())

function resetMessages() {
  errorMessage.value = ''
  successMessage.value = ''
}

function showError(err, fallback) {
  errorMessage.value = errorMap[err?.code] || err?.message || fallback
}

function isSixDigits(value) {
  return /^[0-9]{6}$/.test(value)
}

/**
 * The MFA change already succeeded server-side; a failed profile re-read
 * must not be reported as a failed change, so fall back to the known result.
 */
async function syncProfile(mfaEnabledNow) {
  try {
    await authStore.refreshUser()
  } catch {
    if (authStore.user) {
      authStore.user.mfa_enabled = mfaEnabledNow
      if (mfaEnabledNow) authStore.user.mfa_enrollment_required = false
    }
  }
}

function clearSetup() {
  setup.value = null
  confirmCode.value = ''
}

async function startSetup() {
  resetMessages()
  busy.value = true
  try {
    setup.value = await authService.setupMfa()
  } catch (err) {
    showError(err, 'ไม่สามารถเริ่มตั้งค่า MFA ได้')
  } finally {
    busy.value = false
  }
}

async function confirmSetup() {
  resetMessages()
  if (!isSixDigits(confirmCode.value)) {
    errorMessage.value = 'กรุณากรอกรหัส 6 หลักจากแอปยืนยันตัวตน'
    return
  }
  busy.value = true
  try {
    await authService.confirmMfa(confirmCode.value)
    clearSetup()
    await syncProfile(true)
    successMessage.value = 'เปิดใช้งาน MFA สำเร็จ การเข้าสู่ระบบครั้งถัดไปจะต้องใช้รหัสจากแอป'
  } catch (err) {
    showError(err, 'ยืนยันรหัสไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

async function disableMfa() {
  resetMessages()
  if (!disablePassword.value || !isSixDigits(disableCode.value)) {
    errorMessage.value = 'กรุณากรอกรหัสผ่านและรหัส 6 หลักจากแอปยืนยันตัวตน'
    return
  }
  busy.value = true
  try {
    await authService.disableMfa({ password: disablePassword.value, code: disableCode.value })
    disablePassword.value = ''
    disableCode.value = ''
    await syncProfile(false)
    successMessage.value = 'ปิดการใช้งาน MFA แล้ว'
  } catch (err) {
    showError(err, 'ปิดการใช้งาน MFA ไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onBeforeUnmount(clearSetup)
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">ความปลอดภัยของบัญชี</h1>
      <p class="mt-1 text-sm text-gray-500">
        จัดการการยืนยันตัวตนแบบหลายปัจจัย (MFA) และรหัสผ่านของบัญชี
        {{ authStore.user?.username }}
      </p>
    </div>

    <div
      v-if="authStore.user?.mfa_enrollment_required"
      data-test="mfa-required-banner"
      class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
      role="alert"
    >
      บัญชีผู้ดูแลระบบต้องเปิดใช้งาน MFA ก่อนจึงจะใช้งานส่วนอื่นของระบบได้
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

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">การยืนยันตัวตนแบบหลายปัจจัย (MFA)</h2>
        <span
          data-test="mfa-status"
          class="rounded-full px-2.5 py-0.5 text-xs font-medium"
          :class="mfaEnabled ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'"
        >
          {{ mfaEnabled ? 'เปิดใช้งานแล้ว' : 'ยังไม่เปิดใช้งาน' }}
        </span>
      </div>

      <!-- Not enrolled, no setup in progress -->
      <div v-if="!mfaEnabled && !setup" class="mt-4 space-y-3">
        <p class="text-sm text-gray-600">
          แนะนำให้ผู้ดูแลระบบและเจ้าหน้าที่ทุกคนเปิดใช้งาน MFA โดยใช้แอปยืนยันตัวตน (เช่น Google
          Authenticator หรือ Microsoft Authenticator)
        </p>
        <button
          data-test="mfa-start"
          type="button"
          :disabled="busy"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          @click="startSetup"
        >
          เริ่มตั้งค่า MFA
        </button>
      </div>

      <!-- Enrollment in progress -->
      <form
        v-if="!mfaEnabled && setup"
        class="mt-4 space-y-4"
        novalidate
        @submit.prevent="confirmSetup"
      >
        <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700">
          <li>เปิดแอปยืนยันตัวตนแล้วเลือก "เพิ่มบัญชี" → "ป้อนคีย์ด้วยตนเอง"</li>
          <li>
            ชื่อบัญชี: <span class="font-medium">{{ setup.account_name }}</span> ({{
              setup.issuer
            }})
          </li>
          <li>
            คีย์ลับ:
            <code
              data-test="mfa-secret"
              class="select-all rounded bg-gray-100 px-2 py-1 font-mono text-sm tracking-wider"
              >{{ groupedSecret }}</code
            >
            <span class="ml-1 text-xs text-gray-500">(ชนิด: ตามเวลา, 6 หลัก)</span>
          </li>
          <li>
            หรือบนโทรศัพท์ที่ติดตั้งแอปแล้ว:
            <a :href="setup.qr_code_url" class="text-blue-600 underline">เปิดลิงก์ตั้งค่า</a>
          </li>
          <li>กรอกรหัส 6 หลักที่แอปแสดงเพื่อยืนยัน</li>
        </ol>
        <p class="text-xs text-gray-500">
          คีย์นี้แสดงครั้งเดียวและหมดอายุใน
          {{ Math.round((setup.expires_in_seconds || 0) / 60) }} นาที ห้ามบันทึกภาพหน้าจอหรือส่งต่อ
        </p>
        <div class="flex items-end gap-3">
          <div>
            <label for="mfa-confirm-code" class="block text-sm font-medium text-gray-700"
              >รหัสยืนยัน</label
            >
            <input
              id="mfa-confirm-code"
              v-model="confirmCode"
              data-test="mfa-confirm-code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              class="mt-1 block w-32 rounded-md border border-gray-300 px-3 py-2 font-mono tracking-widest shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <button
            data-test="mfa-confirm"
            type="submit"
            :disabled="busy"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          >
            ยืนยันและเปิดใช้งาน
          </button>
          <button
            type="button"
            :disabled="busy"
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            @click="clearSetup"
          >
            ยกเลิก
          </button>
        </div>
      </form>

      <!-- Enrolled: disable -->
      <form v-if="mfaEnabled" class="mt-4 space-y-3" novalidate @submit.prevent="disableMfa">
        <p class="text-sm text-gray-600">
          การปิด MFA ต้องยืนยันด้วยรหัสผ่านปัจจุบันและรหัสจากแอป และจะถูกบันทึกในบันทึกการตรวจสอบ
        </p>
        <div class="flex flex-wrap items-end gap-3">
          <div>
            <label for="mfa-disable-password" class="block text-sm font-medium text-gray-700"
              >รหัสผ่านปัจจุบัน</label
            >
            <input
              id="mfa-disable-password"
              v-model="disablePassword"
              data-test="mfa-disable-password"
              type="password"
              autocomplete="current-password"
              class="mt-1 block w-56 rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <div>
            <label for="mfa-disable-code" class="block text-sm font-medium text-gray-700"
              >รหัสจากแอป</label
            >
            <input
              id="mfa-disable-code"
              v-model="disableCode"
              data-test="mfa-disable-code"
              inputmode="numeric"
              autocomplete="one-time-code"
              maxlength="6"
              class="mt-1 block w-32 rounded-md border border-gray-300 px-3 py-2 font-mono tracking-widest shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>
          <button
            data-test="mfa-disable"
            type="submit"
            :disabled="busy"
            class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            ปิดการใช้งาน MFA
          </button>
        </div>
      </form>
    </section>

    <SessionList />

    <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
      <h2 class="text-lg font-semibold text-gray-900">รหัสผ่าน</h2>
      <p class="mt-1 text-sm text-gray-600">เปลี่ยนรหัสผ่านของบัญชีนี้</p>
      <router-link
        to="/change-password"
        class="mt-3 inline-block rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
      >
        เปลี่ยนรหัสผ่าน
      </router-link>
    </section>
  </div>
</template>
