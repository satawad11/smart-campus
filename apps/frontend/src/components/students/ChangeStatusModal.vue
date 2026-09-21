<script setup>
import { ref, computed, watch } from 'vue'
import { studentsService } from '@/services/students'
import AcademicStatusBadge from './AcademicStatusBadge.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  student: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'success'])

const statusOptions = [
  {
    value: 'ACTIVE',
    label: 'กำลังศึกษา (ACTIVE)',
    description: 'นักเรียนลงทะเบียนและมีสถานะการศึกษาปกติ',
  },
  {
    value: 'LEAVE',
    label: 'ลาพักการเรียน (LEAVE)',
    description: 'ได้รับอนุมัติให้ลาพักการเรียนชั่วคราว',
  },
  {
    value: 'SUSPENDED',
    label: 'พักการเรียน (SUSPENDED)',
    description: 'ถูกสั่งพักการเรียนตามระเบียบวินัยหรือเงื่อนไขสถานศึกษา',
  },
  {
    value: 'WITHDRAWN',
    label: 'ลาออก (WITHDRAWN)',
    description: 'นักเรียนยื่นความจำนงขอลาออกจากการศึกษา',
  },
  {
    value: 'GRADUATED',
    label: 'สำเร็จการศึกษา (GRADUATED)',
    description: 'สำเร็จการศึกษาตามหลักสูตรครบถ้วน',
  },
  {
    value: 'EXPELLED',
    label: 'พ้นสภาพ (EXPELLED)',
    description: 'พ้นสภาพการเป็นนักเรียนตามข้อบังคับ',
  },
]

const form = ref({
  academic_status: '',
  reason: '',
})

const loading = ref(false)
const errorMessage = ref('')
const validationErrors = ref({})

const isCriticalStatus = computed(() => {
  return ['EXPELLED', 'WITHDRAWN', 'SUSPENDED'].includes(form.value.academic_status)
})

const isNoOp = computed(() => {
  return form.value.academic_status === props.student?.academic_status
})

const errorTranslations = {
  STUDENT_STATUS_NO_OP: 'สถานะใหม่ตรงกับสถานะปัจจุบัน ไม่มีการเปลี่ยนแปลง',
  STUDENT_PROFILE_NOT_FOUND: 'ไม่พบระเบียนนักเรียนในระบบ',
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการเปลี่ยนสถานะการศึกษาของนักเรียน',
  UNAUTHENTICATED: 'กรุณาเข้าสู่ระบบก่อนดำเนินการ',
}

watch(
  () => props.show,
  (newVal) => {
    if (newVal) {
      form.value = {
        academic_status: '',
        reason: '',
      }
      errorMessage.value = ''
      validationErrors.value = {}
    }
  },
)

function closeModal() {
  if (loading.value) return
  emit('close')
}

async function handleSubmit() {
  if (!form.value.academic_status) {
    validationErrors.value = { academic_status: ['กรุณาเลือกสถานะการศึกษาใหม่'] }
    return
  }

  if (isNoOp.value) {
    errorMessage.value = 'สถานะใหม่ตรงกับสถานะปัจจุบัน ไม่มีการเปลี่ยนแปลง'
    return
  }

  loading.value = true
  errorMessage.value = ''
  validationErrors.value = {}

  try {
    const payload = {
      status: form.value.academic_status,
    }
    if (form.value.reason && form.value.reason.trim()) {
      payload.reason = form.value.reason.trim()
    }

    const updated = await studentsService.changeStatus(props.student.uuid, payload)
    emit('success', updated)
    emit('close')
  } catch (err) {
    if (err.errors) {
      validationErrors.value = {
        ...err.errors,
        academic_status: err.errors.academic_status || err.errors.status,
      }
    }
    errorMessage.value =
      errorTranslations[err.code] || err.message || 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะการศึกษา'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 overflow-y-auto"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
  >
    <!-- Backdrop -->
    <div
      class="fixed inset-0 bg-gray-500/75 transition-opacity"
      aria-hidden="true"
      @click="closeModal"
    />

    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
      <div
        class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg"
      >
        <form @submit.prevent="handleSubmit">
          <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div
                class="mx-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10"
              >
                <svg
                  class="h-6 w-6 text-blue-600"
                  fill="none"
                  viewBox="0 0 24 24"
                  stroke-width="1.5"
                  stroke="currentColor"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"
                  />
                </svg>
              </div>

              <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left w-full">
                <h3 id="modal-title" class="text-base font-semibold leading-6 text-gray-900">
                  เปลี่ยนสถานะการศึกษา (Academic Status)
                </h3>

                <div class="mt-2 text-sm text-gray-500">
                  <p>
                    นักเรียน:
                    <strong class="text-gray-800"
                      >{{ student?.person?.first_name }} {{ student?.person?.last_name }}</strong
                    >
                    <span class="font-mono text-xs text-gray-600">
                      ({{ student?.student_code }})</span
                    >
                  </p>
                  <div class="mt-1 flex items-center gap-2">
                    <span>สถานะปัจจุบัน:</span>
                    <AcademicStatusBadge
                      :status="student?.academic_status || 'UNKNOWN'"
                      size="sm"
                    />
                  </div>
                </div>

                <!-- Error Alert -->
                <div
                  v-if="errorMessage"
                  class="mt-3 rounded-md bg-red-50 p-3 text-sm text-red-800 border border-red-200"
                  role="alert"
                >
                  {{ errorMessage }}
                </div>

                <!-- Form Fields -->
                <div class="mt-4 space-y-4">
                  <!-- New Academic Status Select -->
                  <div>
                    <label
                      for="modal_academic_status"
                      class="block text-sm font-medium text-gray-700"
                    >
                      สถานะการศึกษาใหม่ <span class="text-red-500">*</span>
                    </label>
                    <select
                      id="modal_academic_status"
                      v-model="form.academic_status"
                      class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-blue-500 focus:outline-none focus:ring-blue-500 sm:text-sm"
                      :class="{ 'border-red-300': validationErrors.academic_status }"
                      required
                    >
                      <option value="" disabled>-- เลือกสถานะใหม่ --</option>
                      <option
                        v-for="opt in statusOptions"
                        :key="opt.value"
                        :value="opt.value"
                        :disabled="opt.value === student?.academic_status"
                      >
                        {{ opt.label }}
                        {{ opt.value === student?.academic_status ? '(สถานะปัจจุบัน)' : '' }}
                      </option>
                    </select>
                    <p v-if="validationErrors.academic_status" class="mt-1 text-xs text-red-600">
                      {{ validationErrors.academic_status[0] }}
                    </p>
                  </div>

                  <!-- Critical Status Warning -->
                  <div
                    v-if="isCriticalStatus"
                    class="rounded-md bg-amber-50 p-3 text-xs text-amber-800 border border-amber-200"
                  >
                    <div class="flex">
                      <svg
                        class="h-4 w-4 text-amber-600 mr-1.5 shrink-0"
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
                      <div>
                        <strong>คำเตือน:</strong> สถานะที่เลือกมีผลต่อประวัติการศึกษาของนักเรียน
                        การเปลี่ยนแปลงนี้จะถูกบันทึกประวัติการเปลี่ยนสถานะพร้อมผู้ดำเนินการ
                      </div>
                    </div>
                  </div>

                  <!-- Reason Textarea -->
                  <div>
                    <label
                      for="modal_status_reason"
                      class="block text-sm font-medium text-gray-700"
                    >
                      เหตุผลในการเปลี่ยนสถานะ <span class="text-xs text-gray-500">(ถ้ามี)</span>
                    </label>
                    <textarea
                      id="modal_status_reason"
                      v-model="form.reason"
                      rows="3"
                      maxlength="1000"
                      placeholder="ระบุเหตุผลหรือบันทึกข้อความอ้างอิง..."
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                      :class="{ 'border-red-300': validationErrors.reason }"
                    />
                    <div class="mt-1 flex justify-between text-xs text-gray-500">
                      <span v-if="validationErrors.reason" class="text-red-600">
                        {{ validationErrors.reason[0] }}
                      </span>
                      <span v-else class="text-gray-400">ระบุได้สูงสุด 1,000 ตัวอักษร</span>
                      <span>{{ form.reason.length }}/1000</span>
                    </div>
                  </div>

                  <!-- Invariant Reminder -->
                  <div
                    class="rounded bg-gray-50 p-2.5 text-xs text-gray-500 border border-gray-100"
                  >
                    <p>
                      <strong>หมายเหตุทางวิชาการ:</strong>
                      การเปลี่ยนสถานะนี้เป็นการบันทึกประวัติการศึกษาของทะเบียนนักเรียนเท่านั้น
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Actions -->
          <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6 gap-2">
            <button
              type="submit"
              :disabled="loading || !form.academic_status || isNoOp"
              class="inline-flex w-full justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-base font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed sm:w-auto sm:text-sm"
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
              {{ loading ? 'กำลังบันทึก...' : 'บันทึกการเปลี่ยนสถานะ' }}
            </button>
            <button
              type="button"
              :disabled="loading"
              class="mt-3 inline-flex w-full justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-base font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:mt-0 sm:w-auto sm:text-sm"
              @click="closeModal"
            >
              ยกเลิก
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
