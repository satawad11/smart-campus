<script setup>
import { ref, watch } from 'vue'
import { usersService } from '@/services/users'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  user: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'success'])

const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

watch(
  () => props.show,
  (val) => {
    if (val) {
      password.value = ''
      passwordConfirmation.value = ''
      errorMessage.value = ''
      fieldErrors.value = {}
      loading.value = false
    }
  },
)

async function handleSubmit() {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (!password.value) {
    fieldErrors.value.password = 'กรุณากรอกรหัสผ่านชั่วคราวใหม่'
  }
  if (!passwordConfirmation.value) {
    fieldErrors.value.password_confirmation = 'กรุณายืนยันรหัสผ่าน'
  } else if (password.value !== passwordConfirmation.value) {
    fieldErrors.value.password_confirmation = 'การยืนยันรหัสผ่านไม่ตรงกัน'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return
  }

  loading.value = true
  try {
    const res = await usersService.resetPassword(props.user.uuid, password.value)
    // Clear password values immediately from component memory
    password.value = ''
    passwordConfirmation.value = ''

    emit('success', {
      message: res.message || 'รีเซ็ตรหัสผ่านสำเร็จ เซสชันเดิมของผู้ใช้ถูกยกเลิกแล้ว',
    })
    emit('close')
  } catch (err) {
    if (err.fields) {
      fieldErrors.value = err.fields
    }
    errorMessage.value = err.message || 'เกิดข้อผิดพลาดในการรีเซ็ตรหัสผ่าน กรุณาลองใหม่อีกครั้ง'
  } finally {
    loading.value = false
  }
}

function handleClose() {
  if (!loading.value) {
    password.value = ''
    passwordConfirmation.value = ''
    emit('close')
  }
}
</script>

<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 overflow-y-auto"
    aria-labelledby="modal-reset-password"
    role="dialog"
    aria-modal="true"
  >
    <div
      class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0"
    >
      <div
        class="fixed inset-0 bg-gray-500/75 transition-opacity"
        aria-hidden="true"
        @click="handleClose"
      ></div>

      <span class="hidden sm:inline-block sm:h-screen sm:align-middle" aria-hidden="true"
        >&#8203;</span
      >

      <div
        class="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:align-middle"
      >
        <form @submit.prevent="handleSubmit" novalidate>
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div
                class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 sm:mx-0 sm:h-10 sm:w-10"
              >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                  />
                </svg>
              </div>

              <div class="mt-3 w-full text-center sm:mt-0 sm:ml-4 sm:text-left">
                <h3
                  id="modal-reset-password"
                  class="text-base font-semibold leading-6 text-gray-900"
                >
                  รีเซ็ตรหัสผ่านสำหรับ:
                  <span class="font-mono text-blue-600">{{ user?.username }}</span>
                </h3>

                <!-- Session Revocation Security Alert -->
                <div
                  class="mt-3 rounded-md bg-amber-50 p-3 text-xs text-amber-800 border border-amber-200"
                >
                  <p class="font-medium">ผลกระทบด้านความปลอดภัย:</p>
                  <ul class="mt-1 list-disc list-inside space-y-0.5 text-amber-700">
                    <li>เซสชันการเข้าสู่ระบบที่ใช้งานอยู่ทั้งหมดของผู้ใช้นี้จะถูกยกเลิกทันที</li>
                    <li>ผู้ใช้จะต้องเปลี่ยนรหัสผ่านใหม่ในการเข้าสู่ระบบครั้งถัดไป</li>
                  </ul>
                </div>

                <!-- Error Alert -->
                <div
                  v-if="errorMessage"
                  class="mt-3 rounded-md bg-red-50 p-3 text-xs text-red-800 border border-red-200"
                  role="alert"
                >
                  {{ errorMessage }}
                </div>

                <!-- Form Fields -->
                <div class="mt-4 space-y-3">
                  <div>
                    <label for="modal-password" class="block text-xs font-medium text-gray-700">
                      รหัสผ่านชั่วคราวใหม่
                    </label>
                    <input
                      id="modal-password"
                      v-model="password"
                      type="password"
                      autocomplete="new-password"
                      required
                      :disabled="loading"
                      class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50"
                      :class="{
                        'border-red-300 focus:border-red-500 focus:ring-red-500':
                          fieldErrors.password,
                      }"
                      placeholder="••••••••"
                    />
                    <p v-if="fieldErrors.password" class="mt-1 text-xs text-red-600">
                      {{
                        Array.isArray(fieldErrors.password)
                          ? fieldErrors.password[0]
                          : fieldErrors.password
                      }}
                    </p>
                  </div>

                  <div>
                    <label
                      for="modal-password-confirm"
                      class="block text-xs font-medium text-gray-700"
                    >
                      ยืนยันรหัสผ่าน
                    </label>
                    <input
                      id="modal-password-confirm"
                      v-model="passwordConfirmation"
                      type="password"
                      autocomplete="new-password"
                      required
                      :disabled="loading"
                      class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50"
                      :class="{
                        'border-red-300 focus:border-red-500 focus:ring-red-500':
                          fieldErrors.password_confirmation,
                      }"
                      placeholder="••••••••"
                    />
                    <p v-if="fieldErrors.password_confirmation" class="mt-1 text-xs text-red-600">
                      {{
                        Array.isArray(fieldErrors.password_confirmation)
                          ? fieldErrors.password_confirmation[0]
                          : fieldErrors.password_confirmation
                      }}
                    </p>
                  </div>

                  <!-- Policy hint -->
                  <div class="rounded bg-gray-50 p-2 text-[11px] text-gray-500">
                    รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร ประกอบด้วยตัวพิมพ์ใหญ่ ตัวพิมพ์เล็ก
                    ตัวเลข และอักขระพิเศษ
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
            <button
              type="submit"
              :disabled="loading"
              class="inline-flex w-full justify-center rounded-md bg-amber-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline-amber-600 sm:w-auto disabled:opacity-60 transition duration-150"
            >
              <svg
                v-if="loading"
                class="mr-2 h-4 w-4 animate-spin text-white"
                xmlns="http://www.w3.org/2000/svg"
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
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              <span>{{ loading ? 'กำลังดำเนินการ...' : 'รีเซ็ตรหัสผ่าน' }}</span>
            </button>
            <button
              type="button"
              :disabled="loading"
              @click="handleClose"
              class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto disabled:opacity-60"
            >
              ยกเลิก
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
