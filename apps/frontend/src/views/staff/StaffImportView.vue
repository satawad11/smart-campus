<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import staffService from '@/services/staff'
import { apiErrorText } from '@/constants/labels'

/**
 * Staff CSV import (PPL-004): upload -> the server validates every row -> review
 * errors -> process the valid rows in the background -> result.
 */
const COLUMNS =
  'staff_code,first_name,last_name,person_type,position,department_code,email,phone,employment_status'
const SAMPLE = `${COLUMNS}\nT001,สมชาย,ใจดี,TEACHER,ครูประจำแผนก,IT,somchai@example.com,0812345678,ACTIVE\n`

const MODES = {
  CREATE_ONLY: 'เพิ่มใหม่เท่านั้น (รหัสซ้ำ = ข้อผิดพลาด)',
  SKIP_EXISTING: 'ข้ามรหัสที่มีอยู่แล้ว',
  UPDATE_EXISTING: 'อัปเดตข้อมูลรหัสที่มีอยู่แล้ว',
}

const file = ref(null)
const mode = ref('CREATE_ONLY')
const job = ref(null)
const errors = ref([])
const busy = ref(false)
const error = ref('')
let pollTimer = null

const step = computed(() => {
  if (!job.value) return 'upload'
  if (['PROCESSING'].includes(job.value.status)) return 'processing'
  if (job.value.status === 'COMPLETED') return 'done'
  return 'review'
})

function pick(event) {
  file.value = event.target.files?.[0] || null
}

function downloadSample() {
  const blob = new Blob(['﻿' + SAMPLE], { type: 'text/csv;charset=utf-8' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'staff-import-template.csv'
  link.click()
  URL.revokeObjectURL(link.href)
}

async function upload() {
  if (!file.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await staffService.uploadImport(file.value, mode.value)
    job.value = res.data
    const errs = await staffService.importErrors(job.value.uuid)
    errors.value = errs.data || []
  } catch (err) {
    error.value = apiErrorText(err, 'อัปโหลดไฟล์ไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

async function poll() {
  try {
    const res = await staffService.getImport(job.value.uuid)
    job.value = res.data
    if (job.value.status === 'PROCESSING') {
      pollTimer = setTimeout(poll, 2000)
    } else {
      const errs = await staffService.importErrors(job.value.uuid)
      errors.value = errs.data || []
    }
  } catch (err) {
    error.value = apiErrorText(err, 'ตรวจสอบสถานะไม่สำเร็จ')
  }
}

async function process() {
  busy.value = true
  error.value = ''
  try {
    const res = await staffService.processImport(job.value.uuid)
    job.value = res.data
    await poll()
  } catch (err) {
    error.value = apiErrorText(err, 'เริ่มนำเข้าไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

function reset() {
  clearTimeout(pollTimer)
  job.value = null
  errors.value = []
  file.value = null
  error.value = ''
}

onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6 p-6">
    <div class="border-b pb-4">
      <router-link :to="{ name: 'staff.index' }" class="text-sm text-blue-600"
        >← กลับไปรายชื่อ</router-link
      >
      <h1 class="mt-2 text-2xl font-bold text-gray-900">นำเข้าบุคลากรจากไฟล์ CSV</h1>
    </div>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <!-- 1. Upload -->
    <div
      v-if="step === 'upload'"
      class="space-y-4 rounded-xl border border-gray-200 bg-surface p-6"
    >
      <p class="text-sm text-gray-600">
        คอลัมน์ที่ต้องมี: <code>staff_code, first_name, last_name</code> · ไม่บังคับ:
        <code
          >person_type (TEACHER/STAFF), position, department_code, email, phone, employment_status,
          display_name, gender, person_code</code
        >
      </p>
      <button type="button" class="text-sm text-blue-600 hover:underline" @click="downloadSample">
        ดาวน์โหลดไฟล์ตัวอย่าง
      </button>
      <input
        type="file"
        accept=".csv,text/csv"
        data-test="import-file"
        class="block text-sm"
        @change="pick"
      />
      <label class="block text-sm text-gray-700">
        เมื่อพบรหัสที่มีอยู่แล้ว
        <select v-model="mode" class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm">
          <option v-for="(label, value) in MODES" :key="value" :value="value">{{ label }}</option>
        </select>
      </label>
      <button
        type="button"
        data-test="import-upload"
        :disabled="!file || busy"
        class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50"
        @click="upload"
      >
        {{ busy ? 'กำลังตรวจสอบ...' : 'อัปโหลดและตรวจสอบ' }}
      </button>
    </div>

    <!-- 2. Review / 3. Done -->
    <div v-else class="space-y-4 rounded-xl border border-gray-200 bg-surface p-6">
      <div data-test="import-summary" class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div>
          ทั้งหมด <b>{{ job.total_rows }}</b> แถว
        </div>
        <div class="text-green-700">
          ผ่าน <b>{{ job.valid_rows }}</b>
        </div>
        <div class="text-red-700">
          ไม่ผ่าน <b>{{ job.invalid_rows }}</b>
        </div>
        <div v-if="step === 'done'">
          นำเข้าสำเร็จ <b>{{ job.success_rows }}</b> · ข้าม {{ job.skipped_rows }} · ล้มเหลว
          {{ job.failed_rows }}
        </div>
      </div>

      <p v-if="job.status === 'FAILED'" class="text-sm text-red-600">
        ไฟล์ไม่ผ่านการตรวจสอบ ดูรายละเอียดด้านล่าง แล้วแก้ไขไฟล์และอัปโหลดใหม่
      </p>
      <p v-if="step === 'processing'" class="text-sm text-blue-700">กำลังนำเข้า...</p>
      <p v-if="step === 'done'" data-test="import-done" class="text-sm font-medium text-green-700">
        นำเข้าเสร็จแล้ว
      </p>

      <table v-if="errors.length" class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="text-left text-xs text-gray-500">
          <tr>
            <th class="py-2">แถว</th>
            <th class="py-2">คอลัมน์</th>
            <th class="py-2">ค่า</th>
            <th class="py-2">ปัญหา</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="(e, i) in errors" :key="i" data-test="import-error">
            <td class="py-1">{{ e.row_number }}</td>
            <td class="py-1 font-mono text-xs">{{ e.field }}</td>
            <td class="py-1">{{ e.value || '-' }}</td>
            <td class="py-1 text-red-700">{{ e.error_message }}</td>
          </tr>
        </tbody>
      </table>

      <div class="flex justify-end gap-2">
        <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="reset">
          อัปโหลดไฟล์ใหม่
        </button>
        <button
          v-if="step === 'review' && job.status === 'VALIDATED' && job.valid_rows > 0"
          type="button"
          data-test="import-process"
          :disabled="busy"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50"
          @click="process"
        >
          นำเข้า {{ job.valid_rows }} แถวที่ผ่าน
        </button>
        <router-link
          v-if="step === 'done'"
          :to="{ name: 'staff.index' }"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white"
          >ไปที่รายชื่อบุคลากร</router-link
        >
      </div>
    </div>
  </div>
</template>
