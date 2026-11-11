<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { rmsSyncService } from '@/services/students'
import { apiErrorText } from '@/constants/labels'

/**
 * RMS API Center sync (ACR-INT-01): start a dry run or a real run and read
 * the report of each run (counts and review items, no names).
 */

const COUNT_LABELS = {
  groups_seen: 'กลุ่มเรียนจาก RMS',
  groups_skipped: 'กลุ่มเรียนที่ข้าม',
  departments_created: 'แผนกที่สร้างใหม่',
  programs_created: 'สาขาที่สร้างใหม่',
  classrooms_created: 'ห้องเรียนที่สร้างใหม่',
  classrooms_updated: 'ห้องเรียนที่ปรับปรุง',
  students_seen: 'นักเรียนจาก RMS',
  students_created: 'นักเรียนใหม่',
  students_updated: 'นักเรียนที่ปรับปรุง',
  students_unchanged: 'นักเรียนที่ไม่เปลี่ยน',
  students_skipped: 'นักเรียนที่ข้าม',
  students_failed: 'นักเรียนที่บันทึกไม่ได้',
  students_missing_from_rms: 'นักเรียนที่หายไปจาก RMS',
  students_active_not_in_rms: 'นักเรียนสถานะปกติที่ไม่มีใน RMS',
  staff_seen: 'บุคลากรจาก RMS',
  staff_created: 'บุคลากรใหม่',
  staff_updated: 'บุคลากรที่ปรับปรุง',
  staff_unchanged: 'บุคลากรที่ไม่เปลี่ยน',
  staff_skipped: 'บุคลากรที่ข้าม',
  staff_missing_from_rms: 'บุคลากรที่หายไปจาก RMS',
}

const ISSUE_LABELS = {
  MAJOR_NOT_MAPPED: 'สาขาที่ยังไม่ได้กำหนดแผนก (ใช้ชื่อ "แผนกวิชา<สาขา>")',
  DEPARTMENT_CREATED: 'แผนกที่สร้างใหม่',
  DEPARTMENT_INACTIVE: 'แผนกที่ปิดใช้งานอยู่',
  CLASSROOM_INACTIVE: 'ห้องเรียนที่ปิดใช้งานอยู่',
  GROUP_INVALID: 'กลุ่มเรียนข้อมูลไม่ครบ',
  GROUP_MOVED: 'กลุ่มเรียนที่เปลี่ยนแผนกหรือปี (สร้างห้องเรียนใหม่)',
  STUDENT_INVALID: 'นักเรียนข้อมูลไม่ครบ',
  STUDENT_DUPLICATE: 'รหัสนักเรียนซ้ำใน RMS',
  STUDENT_NO_GROUP: 'นักเรียนที่ไม่มีกลุ่มเรียน',
  STUDENT_REJECTED: 'นักเรียนที่บันทึกไม่ได้',
  STUDENT_STATUS_REVIEW: 'ยังอยู่ใน RMS แต่สถานะในระบบไม่ใช่ปกติ (ตรวจสอบ)',
  STUDENT_MISSING_FROM_RMS: 'หายไปจาก RMS (ตรวจสอบ ระบบไม่เปลี่ยนสถานะเอง)',
  STUDENT_ACTIVE_NOT_IN_RMS: 'สถานะปกติในระบบแต่ไม่มีใน RMS (ตรวจสอบ)',
  STUDENT_PERSON_DELETED: 'ข้อมูลบุคคลของนักเรียนถูกลบ',
  STAFF_INVALID: 'บุคลากรข้อมูลไม่ครบ',
  STAFF_PERSON_DELETED: 'ข้อมูลบุคคลของบุคลากรถูกลบ',
}

const RUN_STATUS = { RUNNING: 'กำลังทำงาน', COMPLETED: 'เสร็จ', FAILED: 'ล้มเหลว' }
const TRIGGERS = { SCHEDULE: 'ตามเวลา', MANUAL: 'สั่งจากหน้าเว็บ', CLI: 'คำสั่งเซิร์ฟเวอร์' }

const runs = ref([])
const meta = ref({})
const selected = ref(null)
const loading = ref(false)
const starting = ref(false)
const errorMessage = ref('')
const notice = ref('')
let pollTimer = null

const hasRunning = computed(
  () => meta.value.running || runs.value.some((r) => r.status === 'RUNNING'),
)

function countLabel(key) {
  return COUNT_LABELS[key] ?? key
}

function issueLabel(code) {
  return ISSUE_LABELS[code] ?? code
}

function formatTime(iso) {
  return iso ? new Date(iso).toLocaleString('th-TH') : '-'
}

function summary(run) {
  const c = run.counts ?? {}
  return `ใหม่ ${(c.students_created ?? 0) + (c.staff_created ?? 0)} · ปรับปรุง ${(c.students_updated ?? 0) + (c.staff_updated ?? 0)} · หายจาก RMS ${c.students_missing_from_rms ?? 0}`
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const result = await rmsSyncService.list()
    runs.value = result.data
    meta.value = result.meta ?? {}
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายการซิงก์ไม่ได้')
  } finally {
    loading.value = false
  }
  schedulePoll()
}

function schedulePoll() {
  clearTimeout(pollTimer)
  if (hasRunning.value) {
    pollTimer = setTimeout(load, 5000)
  }
}

async function openRun(run) {
  errorMessage.value = ''
  try {
    selected.value = await rmsSyncService.show(run.uuid)
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดรายงานไม่ได้')
  }
}

async function start(dryRun) {
  if (
    !dryRun &&
    !window.confirm(
      'ซิงก์จริงจะเพิ่มและปรับปรุงข้อมูลนักเรียน บุคลากร แผนก สาขา และห้องเรียนตาม RMS (ไม่ลบและไม่เปลี่ยนสถานะนักเรียน) ดำเนินการต่อหรือไม่?',
    )
  ) {
    return
  }
  starting.value = true
  errorMessage.value = ''
  notice.value = ''
  try {
    await rmsSyncService.start(dryRun)
    notice.value = dryRun
      ? 'เริ่มทดลองซิงก์แล้ว ผลจะแสดงในรายการด้านล่างภายในไม่กี่นาที'
      : 'เริ่มซิงก์แล้ว ผลจะแสดงในรายการด้านล่างภายในไม่กี่นาที'
    meta.value = { ...meta.value, running: true }
    setTimeout(load, 2000)
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'เริ่มซิงก์ไม่ได้')
  } finally {
    starting.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="space-y-6">
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">ซิงก์ข้อมูลจาก RMS</h1>
        <p class="mt-1 text-sm text-gray-500">
          ดึงนักเรียน บุคลากร และกลุ่มเรียนจาก RMS ของสถานศึกษา
          ไม่ลบข้อมูลและไม่เปลี่ยนสถานะนักเรียน
        </p>
      </div>
      <div class="mt-4 sm:mt-0 flex flex-wrap gap-3">
        <button
          type="button"
          data-test="dry-run"
          :disabled="starting || hasRunning || !meta.configured"
          class="rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
          @click="start(true)"
        >
          ทดลองซิงก์ (ไม่บันทึก)
        </button>
        <button
          type="button"
          data-test="real-run"
          :disabled="starting || hasRunning || !meta.configured"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          @click="start(false)"
        >
          ซิงก์จริง
        </button>
      </div>
    </div>

    <div class="rounded-md bg-gray-50 p-4 text-sm text-gray-700 space-y-1">
      <p>
        การเชื่อมต่อ RMS:
        <span :class="meta.configured ? 'text-green-700' : 'text-red-700'">
          {{ meta.configured ? 'ตั้งค่าแล้ว' : 'ยังไม่ได้ตั้งค่ากุญแจ RMS' }}
        </span>
      </p>
      <p>
        ซิงก์อัตโนมัติรายวัน:
        {{
          meta.schedule_enabled
            ? `เปิด (${meta.schedule_cron})`
            : 'ปิด (เปิดได้หลังตรวจผลทดลองซิงก์)'
        }}
      </p>
      <p v-if="hasRunning" class="text-blue-700">กำลังซิงก์อยู่...</p>
    </div>

    <div v-if="notice" class="rounded-md bg-blue-50 p-3 text-sm text-blue-800">{{ notice }}</div>
    <div v-if="errorMessage" class="rounded-md bg-red-50 p-3 text-sm text-red-700" role="alert">
      {{ errorMessage }}
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-surface">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-gray-600">
          <tr>
            <th class="px-4 py-2">เริ่ม</th>
            <th class="px-4 py-2">ประเภท</th>
            <th class="px-4 py-2">สถานะ</th>
            <th class="px-4 py-2">สรุป</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="!loading && runs.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-gray-500">ยังไม่เคยซิงก์</td>
          </tr>
          <tr v-for="run in runs" :key="run.uuid" data-test="run-row">
            <td class="px-4 py-2 whitespace-nowrap">{{ formatTime(run.started_at) }}</td>
            <td class="px-4 py-2">
              {{ run.dry_run ? 'ทดลอง' : 'จริง' }} · {{ TRIGGERS[run.trigger] ?? run.trigger }}
              <span v-if="run.created_by" class="text-gray-500">({{ run.created_by }})</span>
            </td>
            <td class="px-4 py-2">
              <span
                :class="{
                  'text-green-700': run.status === 'COMPLETED',
                  'text-red-700': run.status === 'FAILED',
                  'text-blue-700': run.status === 'RUNNING',
                }"
                >{{ RUN_STATUS[run.status] ?? run.status }}</span
              >
            </td>
            <td class="px-4 py-2 text-gray-600">
              <span v-if="run.status === 'FAILED'">{{ run.error }}</span>
              <span v-else-if="run.status === 'COMPLETED'">{{ summary(run) }}</span>
            </td>
            <td class="px-4 py-2 text-right">
              <button
                v-if="run.status !== 'RUNNING'"
                type="button"
                class="text-blue-600 hover:underline"
                @click="openRun(run)"
              >
                ดูรายงาน
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="selected"
      class="space-y-4 rounded-lg border border-gray-200 bg-surface p-4"
      data-test="report"
    >
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-900">
          รายงาน{{ selected.dry_run ? 'ทดลองซิงก์ (ยังไม่ได้บันทึก)' : 'การซิงก์' }} ·
          {{ formatTime(selected.started_at) }}
        </h2>
        <button
          type="button"
          class="text-sm text-gray-500 hover:underline"
          @click="selected = null"
        >
          ปิด
        </button>
      </div>
      <p v-if="selected.error" class="text-sm text-red-700">{{ selected.error }}</p>
      <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
        <div
          v-for="(value, key) in selected.counts"
          :key="key"
          class="flex justify-between border-b border-gray-100 py-1"
        >
          <dt class="text-gray-600">{{ countLabel(key) }}</dt>
          <dd class="font-medium text-gray-900">{{ value }}</dd>
        </div>
      </dl>
      <div v-for="issue in selected.issues ?? []" :key="issue.code" class="text-sm">
        <h3 class="font-medium text-gray-900">{{ issueLabel(issue.code) }} ({{ issue.count }})</h3>
        <p class="mt-1 text-gray-600 break-words">
          {{ issue.items.join(', ')
          }}<span v-if="issue.count > issue.items.length">
            และอีก {{ issue.count - issue.items.length }} รายการ</span
          >
        </p>
      </div>
    </div>
  </div>
</template>
