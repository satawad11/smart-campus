<script setup>
import { computed, ref } from 'vue'
import { staffImportService } from '@/services/staff'

const file = ref(null)
const conflictMode = ref('CREATE_ONLY')
const job = ref(null)
const preview = ref(null)
const loading = ref(false)
const error = ref('')
const message = ref('')
const canProcess = computed(
  () => job.value?.status === 'VALIDATED' && Number(job.value.valid_rows) > 0,
)
function selectFile(event) {
  file.value = event.target.files?.[0] || null
  job.value = null
  preview.value = null
  message.value = ''
}
async function upload() {
  if (!file.value) return
  loading.value = true
  error.value = ''
  message.value = ''
  try {
    const data = new FormData()
    data.append('file', file.value)
    data.append('conflict_mode', conflictMode.value)
    job.value = await staffImportService.upload(data)
    preview.value = await staffImportService.preview(job.value.uuid)
    message.value =
      'ตรวจสอบไฟล์และตัวอย่างข้อมูลแล้ว ก่อนเริ่มนำเข้ากรุณาตรวจจำนวนแถวและโหมดจัดการข้อมูลซ้ำ'
  } catch (err) {
    error.value = err.response?.data?.message || 'ตรวจสอบไฟล์นำเข้าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
async function refresh() {
  if (!job.value) return
  loading.value = true
  try {
    job.value = await staffImportService.show(job.value.uuid)
    preview.value = await staffImportService.preview(job.value.uuid)
  } catch (err) {
    error.value = err.response?.data?.message || 'ตรวจสอบสถานะงานนำเข้าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
async function processImport() {
  loading.value = true
  error.value = ''
  try {
    job.value = await staffImportService.process(job.value.uuid)
    message.value = 'ส่งงานนำเข้าเข้าคิวแล้ว กดตรวจสอบสถานะอีกครั้งเพื่อติดตามผล'
  } catch (err) {
    error.value = err.response?.data?.message || 'เริ่มนำเข้าไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <RouterLink to="/staff" class="text-sm text-blue-700">← กลับรายการบุคลากร</RouterLink>
    <h1 class="mb-1 mt-4 text-2xl font-bold">นำเข้าข้อมูลบุคลากร</h1>
    <p class="mb-6 text-sm text-gray-500">
      ใช้ไฟล์ CSV ตามคอลัมน์ในสเปก: staff_code, first_name, last_name และข้อมูลเพิ่มเติมที่รองรับ
    </p>
    <p v-if="error" role="alert" class="mb-3 rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
    <p v-if="message" role="status" class="mb-3 rounded-lg bg-green-50 p-3 text-green-800">
      {{ message }}
    </p>
    <section class="space-y-4 rounded-xl border bg-white p-5">
      <label class="block text-sm font-medium"
        >ไฟล์ CSV<input
          type="file"
          accept=".csv,text/csv"
          class="mt-2 block w-full rounded border p-2"
          @change="selectFile"
      /></label>
      <label class="block text-sm font-medium"
        >กรณีรหัสบุคลากรซ้ำ<select
          v-model="conflictMode"
          class="mt-1 block w-full rounded border p-2"
        >
          <option value="CREATE_ONLY">สร้างใหม่เท่านั้น (CREATE_ONLY)</option>
          <option value="SKIP_EXISTING">ข้ามรายการที่มีอยู่ (SKIP_EXISTING)</option>
          <option value="UPDATE_EXISTING">ปรับปรุงรายการที่มีอยู่ (UPDATE_EXISTING)</option>
        </select></label
      >
      <div class="flex flex-wrap gap-2">
        <button
          :disabled="loading || !file"
          class="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50"
          @click="upload"
        >
          {{ loading ? 'กำลังทำงาน…' : 'อัปโหลดและตรวจสอบตัวอย่าง' }}</button
        ><button v-if="job" :disabled="loading" class="rounded border px-4 py-2" @click="refresh">
          ตรวจสอบสถานะ</button
        ><button
          v-if="canProcess"
          :disabled="loading"
          class="rounded bg-emerald-700 px-4 py-2 text-white"
          @click="processImport"
        >
          ยืนยันเริ่มนำเข้า
        </button>
      </div>
      <div v-if="preview?.sample_errors?.length" class="mt-5">
        <h3 class="mb-2 font-medium text-red-800">ตัวอย่างข้อผิดพลาด</h3>
        <ul class="space-y-2 text-sm text-red-700">
          <li
            v-for="(item, index) in preview.sample_errors"
            :key="`${item.row_number}-${item.field}-${index}`"
            class="rounded bg-red-50 p-2"
          >
            แถว {{ item.row_number }} · {{ item.field || 'ข้อมูล' }}:
            {{ item.error_message || item.error_code }}
          </li>
        </ul>
      </div>
    </section>
    <section v-if="job" class="mt-5 rounded-xl border bg-white p-5">
      <h2 class="font-semibold">ผลตรวจสอบไฟล์</h2>
      <dl class="mt-3 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
        <div>
          <dt class="text-gray-500">สถานะ</dt>
          <dd class="font-medium">{{ job.status }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">ทั้งหมด</dt>
          <dd>{{ job.total_rows }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">ผ่านตรวจสอบ</dt>
          <dd>{{ job.valid_rows }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">ผิดพลาด</dt>
          <dd>{{ job.invalid_rows }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">นำเข้าสำเร็จ</dt>
          <dd>{{ job.success_rows ?? 0 }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">ข้าม</dt>
          <dd>{{ job.skipped_rows ?? 0 }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">นำเข้าไม่สำเร็จ</dt>
          <dd>{{ job.failed_rows ?? 0 }}</dd>
        </div>
        <div>
          <dt class="text-gray-500">โหมดซ้ำ</dt>
          <dd>{{ job.conflict_mode || conflictMode }}</dd>
        </div>
      </dl>
      <h3 class="mb-2 mt-5 font-medium">ตัวอย่างแถวข้อมูล</h3>
      <div class="overflow-x-auto">
        <table class="min-w-full text-left text-xs">
          <thead>
            <tr>
              <th
                v-for="key in Object.keys(preview?.sample_rows?.[0] || {})"
                :key="key"
                class="border-b p-2"
              >
                {{ key }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in (preview?.sample_rows || []).slice(0, 10)" :key="index">
              <td v-for="key in Object.keys(row)" :key="key" class="border-b p-2">
                {{ row[key] ?? '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</template>
