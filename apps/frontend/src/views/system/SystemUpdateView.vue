<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import ReleaseNotes from '@/components/system/ReleaseNotes.vue'
import { systemUpdateService } from '@/services/systemUpdate'

/**
 * อัปเดตระบบ (ACR-OPS-01). Needs system.update (SUPER_ADMIN only); the
 * backend enforces it. The host update agent does the real work (verified
 * backup, SHA-256 check, migrations, restart); this page shows versions,
 * Thai release notes, history and job status, and queues requests.
 */
const state = ref(null)
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const checking = ref(false)
const showInstalledNotes = ref(false)

const confirmTarget = ref(null)
const password = ref('')
const passwordError = ref('')
const submitting = ref(false)

const restarting = ref(false)
let pollTimer = null

const reasonText = {
  NOT_KIT_INSTALL: 'เครื่องนี้ไม่ได้ติดตั้งจากชุดติดตั้งเซิร์ฟเวอร์ จึงอัปเดตผ่านหน้าเว็บไม่ได้',
  DISABLED_BY_ADMIN:
    'ผู้ดูแลเซิร์ฟเวอร์ปิดการอัปเดตผ่านหน้าเว็บไว้ (เปิดได้ด้วย sudo smart-campus web-update on)',
  AGENT_UNAVAILABLE: 'บริการอัปเดตบนเซิร์ฟเวอร์ยังไม่พร้อม (ต้องมี systemd และ python3)',
  NOT_CONFIGURED:
    'ยังตั้งค่าการอัปเดตผ่านหน้าเว็บไม่ครบ ติดตั้งรุ่นนี้ซ้ำด้วยชุดติดตั้งบนเซิร์ฟเวอร์',
}

const errorMap = {
  SYSTEM_UPDATE_UNAVAILABLE: 'อัปเดตผ่านหน้าเว็บไม่ได้บนเซิร์ฟเวอร์นี้',
  SYSTEM_UPDATE_IN_PROGRESS: 'มีการอัปเดตที่รอดำเนินการหรือกำลังทำงานอยู่แล้ว',
  SYSTEM_UPDATE_VERSION_NOT_ALLOWED:
    'รุ่นนี้อัปเดตผ่านหน้าเว็บไม่ได้ (ต้องใหม่กว่ารุ่นที่ติดตั้งและมีชุดติดตั้งสำหรับเครื่องนี้)',
  SYSTEM_UPDATE_REQUEST_FAILED: 'ส่งคำขอไปยังบริการอัปเดตบนเซิร์ฟเวอร์ไม่สำเร็จ',
  FORBIDDEN: 'คุณไม่มีสิทธิ์อัปเดตระบบ',
}

const jobStateLabel = {
  running: { text: 'กำลังดำเนินการ', cls: 'bg-blue-100 text-blue-800' },
  succeeded: { text: 'สำเร็จ', cls: 'bg-green-100 text-green-800' },
  failed: { text: 'ไม่สำเร็จ', cls: 'bg-red-100 text-red-800' },
  rejected: { text: 'ถูกปฏิเสธ', cls: 'bg-red-100 text-red-800' },
}

const webEnabled = computed(() => !!state.value?.web_update?.enabled)
const installed = computed(() => state.value?.installed || null)
const releases = computed(() => state.value?.releases || [])
const newer = computed(() => releases.value.filter((r) => r.is_newer))
const current = computed(() => releases.value.find((r) => r.is_installed) || null)
const job = computed(() => state.value?.job || null)
const pending = computed(() => state.value?.pending || [])
const pendingUpdate = computed(() => pending.value.find((p) => p.type === 'update') || null)
const pendingCheck = computed(() => pending.value.some((p) => p.type === 'check'))
const busy = computed(
  () => !!pendingUpdate.value || (job.value?.state === 'running' && !job.value?.stale),
)
const active = computed(() => busy.value || pendingCheck.value)

function formatDate(value) {
  return value ? new Date(value).toLocaleString('th-TH') : '-'
}

function cliCommand(tag) {
  return `sudo smart-campus update ${tag}`
}

async function load({ quiet = false } = {}) {
  if (!quiet) loading.value = true
  try {
    state.value = await systemUpdateService.getState()
    if (!quiet) errorMessage.value = ''
    restarting.value = false
  } catch (err) {
    // While an update runs the backend restarts: keep polling quietly.
    if (quiet && (err?.status >= 500 || err?.status === 0 || !err?.status)) {
      restarting.value = true
    } else {
      errorMessage.value = errorMap[err?.code] || err?.message || 'โหลดข้อมูลการอัปเดตไม่สำเร็จ'
    }
  } finally {
    loading.value = false
    schedulePoll()
  }
}

function schedulePoll() {
  clearTimeout(pollTimer)
  if (active.value || restarting.value) {
    pollTimer = setTimeout(() => load({ quiet: true }), 5000)
  }
}

async function checkNow() {
  errorMessage.value = ''
  successMessage.value = ''
  checking.value = true
  try {
    const result = await systemUpdateService.requestCheck()
    state.value = result.state
    successMessage.value = 'ส่งคำขอตรวจหารุ่นใหม่แล้ว ระบบจะแสดงผลภายในไม่กี่นาที'
  } catch (err) {
    errorMessage.value = errorMap[err?.code] || err?.message || 'ส่งคำขอไม่สำเร็จ'
  } finally {
    checking.value = false
    schedulePoll()
  }
}

function openConfirm(release) {
  confirmTarget.value = release
  password.value = ''
  passwordError.value = ''
}

function closeConfirm() {
  confirmTarget.value = null
  password.value = ''
}

async function confirmUpdate() {
  if (!password.value) {
    passwordError.value = 'กรุณากรอกรหัสผ่านของคุณ'
    return
  }
  submitting.value = true
  passwordError.value = ''
  errorMessage.value = ''
  try {
    const tag = confirmTarget.value.tag
    const result = await systemUpdateService.requestUpdate(tag, password.value)
    state.value = result.state
    successMessage.value = `ส่งคำขออัปเดตเป็น ${tag} แล้ว ระบบจะสำรองข้อมูลก่อน แล้วปิดให้บริการชั่วคราวระหว่างอัปเดต`
    closeConfirm()
  } catch (err) {
    if (err?.fields?.current_password) {
      passwordError.value = 'รหัสผ่านไม่ถูกต้อง'
    } else {
      errorMessage.value = errorMap[err?.code] || err?.message || 'ส่งคำขออัปเดตไม่สำเร็จ'
      closeConfirm()
    }
  } finally {
    submitting.value = false
    schedulePoll()
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text)
    successMessage.value = 'คัดลอกคำสั่งแล้ว'
  } catch {
    // Clipboard may be blocked (non-HTTPS); the command stays selectable.
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">อัปเดตระบบ</h1>
        <p class="mt-1 text-sm text-gray-500">
          รุ่นที่ติดตั้ง รุ่นใหม่ที่มีให้อัปเดต และรายละเอียดการเปลี่ยนแปลงของแต่ละรุ่น
        </p>
      </div>
      <button
        data-test="check-now"
        type="button"
        :disabled="checking || !webEnabled || pendingCheck"
        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        @click="checkNow"
      >
        {{ pendingCheck ? 'กำลังตรวจหารุ่นใหม่...' : 'ตรวจหารุ่นใหม่' }}
      </button>
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
      data-test="error"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
      role="alert"
    >
      {{ errorMessage }}
    </div>
    <div
      v-if="restarting"
      data-test="restarting"
      class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800"
      role="status"
    >
      ระบบกำลังอัปเดตและเริ่มใหม่ หน้านี้จะกลับมาแสดงผลเองเมื่อระบบพร้อม
    </div>

    <div v-if="loading && !state" class="text-sm text-gray-500">กำลังโหลด...</div>

    <template v-if="state">
      <div
        v-if="!webEnabled"
        data-test="web-disabled"
        class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
      >
        {{ reasonText[state.web_update?.reason] || 'อัปเดตผ่านหน้าเว็บไม่ได้บนเซิร์ฟเวอร์นี้' }}
        <span class="block pt-1"
          >ยังอัปเดตได้ด้วยคำสั่งบนเซิร์ฟเวอร์ตามที่แสดงในแต่ละรุ่นด้านล่าง</span
        >
      </div>

      <!-- Current / last job -->
      <section
        v-if="job || pendingUpdate"
        data-test="job"
        class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
      >
        <div class="flex items-center justify-between">
          <h2 class="text-lg font-semibold text-gray-900">งานอัปเดตล่าสุด</h2>
          <span
            v-if="pendingUpdate"
            class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800"
            >รอเซิร์ฟเวอร์รับงาน</span
          >
          <span
            v-else
            data-test="job-state"
            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="(jobStateLabel[job.state] || jobStateLabel.failed).cls"
          >
            {{
              job.stale ? 'ไม่ทราบผล (ถูกขัดจังหวะ)' : jobStateLabel[job.state]?.text || job.state
            }}
          </span>
        </div>
        <p v-if="pendingUpdate" class="mt-3 text-sm text-gray-700">
          ขออัปเดตเป็น {{ pendingUpdate.version }} โดย {{ pendingUpdate.requested_by }} เมื่อ
          {{ formatDate(pendingUpdate.requested_at) }}
        </p>
        <template v-else>
          <p data-test="job-message" class="mt-3 text-sm text-gray-700">{{ job.message }}</p>
          <p class="mt-1 text-xs text-gray-500">
            {{ job.type === 'check' ? 'ตรวจหารุ่นใหม่' : `อัปเดตเป็น ${job.version}` }} · โดย
            {{ job.requested_by || '-' }} · เริ่ม {{ formatDate(job.started_at) }}
            <template v-if="job.finished_at"> · เสร็จ {{ formatDate(job.finished_at) }}</template>
          </p>
          <details v-if="job.log_tail?.length" class="mt-3">
            <summary class="cursor-pointer text-xs text-gray-500">บันทึกการทำงาน (ท้ายสุด)</summary>
            <pre
              class="mt-2 max-h-64 overflow-auto rounded bg-gray-900 p-3 font-mono text-xs text-gray-100"
              >{{ job.log_tail.join('\n') }}</pre>
          </details>
        </template>
      </section>

      <!-- Installed -->
      <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900">รุ่นที่ติดตั้งอยู่</h2>
        <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div>
            <dt class="text-gray-500">รุ่น</dt>
            <dd data-test="installed-version" class="font-mono text-lg font-semibold text-gray-900">
              {{ installed?.version || 'ไม่ทราบ' }}
            </dd>
          </div>
          <div>
            <dt class="text-gray-500">ติดตั้งครั้งแรก</dt>
            <dd class="text-gray-900">{{ formatDate(installed?.installed_at) }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">อัปเดตล่าสุด</dt>
            <dd class="text-gray-900">{{ formatDate(installed?.updated_at) }}</dd>
          </div>
        </dl>
        <p class="mt-3 text-xs text-gray-500">
          ตรวจรายการรุ่นจาก GitHub ล่าสุดเมื่อ {{ formatDate(state.checked_at) }}
          <template v-if="!state.checked_at">
            (ยังไม่เคยตรวจ: ผู้ดูแลเซิร์ฟเวอร์ต้องรัน sudo smart-campus update-setup ก่อน)
          </template>
        </p>
        <div v-if="current" class="mt-4 border-t border-gray-100 pt-3">
          <button
            type="button"
            class="text-sm font-medium text-blue-700 hover:underline"
            @click="showInstalledNotes = !showInstalledNotes"
          >
            {{ showInstalledNotes ? 'ซ่อน' : 'ดู' }}การเปลี่ยนแปลงของรุ่นนี้
          </button>
          <div v-if="showInstalledNotes" class="mt-3">
            <ReleaseNotes :notes="current.notes" />
          </div>
        </div>
      </section>

      <!-- Newer releases -->
      <section class="space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">รุ่นใหม่ที่อัปเดตได้</h2>
        <p
          v-if="!newer.length"
          data-test="up-to-date"
          class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500 shadow-sm"
        >
          ระบบเป็นรุ่นล่าสุดแล้ว
        </p>
        <article
          v-for="release in newer"
          :key="release.tag"
          data-test="release"
          class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
        >
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="font-mono text-lg font-semibold text-gray-900">{{ release.tag }}</h3>
            <span
              v-if="release.prerelease"
              class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800"
              >รุ่นทดสอบ</span
            >
            <span
              v-if="!release.has_kit"
              class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700"
              >ไม่มีชุดติดตั้งสำหรับเครื่องนี้</span
            >
            <span class="text-xs text-gray-500"
              >เผยแพร่ {{ formatDate(release.published_at) }}</span
            >
          </div>
          <p v-if="release.name && release.name !== release.tag" class="mt-1 text-sm text-gray-700">
            {{ release.name }}
          </p>
          <div class="mt-4">
            <ReleaseNotes :notes="release.notes" />
          </div>
          <div
            class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4"
          >
            <div class="text-xs text-gray-500">
              หรือรันบนเซิร์ฟเวอร์:
              <code
                data-test="cli-command"
                class="select-all rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-800"
                >{{ cliCommand(release.tag) }}</code
              >
              <button
                type="button"
                class="ml-1 text-blue-700 hover:underline"
                @click="copy(cliCommand(release.tag))"
              >
                คัดลอก
              </button>
            </div>
            <button
              data-test="update-button"
              type="button"
              :disabled="!webEnabled || !release.has_kit || busy"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
              @click="openConfirm(release)"
            >
              อัปเดตเป็นรุ่นนี้
            </button>
          </div>
        </article>
      </section>

      <!-- History -->
      <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-gray-900">ประวัติการอัปเดต</h2>
        <p v-if="!state.history?.length" class="mt-3 text-sm text-gray-500">ยังไม่มีประวัติ</p>
        <table v-else data-test="history" class="mt-3 min-w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500">
              <th class="py-2 pr-4 font-medium">เวลา</th>
              <th class="py-2 pr-4 font-medium">การดำเนินการ</th>
              <th class="py-2 font-medium">รุ่น</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(h, i) in state.history" :key="i">
              <td class="py-2 pr-4 text-gray-700">{{ formatDate(h.at) }}</td>
              <td class="py-2 pr-4 text-gray-700">
                {{ h.action === 'rollback' ? 'ย้อนกลับ' : 'อัปเดต' }}
              </td>
              <td class="py-2 font-mono text-gray-900">{{ h.from || '-' }} → {{ h.to }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>

    <!-- Confirm with password -->
    <div
      v-if="confirmTarget"
      data-test="confirm-dialog"
      class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
      role="dialog"
      aria-modal="true"
    >
      <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h3 class="text-lg font-semibold text-gray-900">
          อัปเดตเป็น {{ confirmTarget.tag }} ใช่หรือไม่?
        </h3>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">
          <li>ระบบจะสำรองข้อมูลและตรวจสอบไฟล์สำรองก่อนเริ่ม</li>
          <li>ระบบจะปิดให้บริการชั่วคราวระหว่างอัปเดต (ประมาณ 5–15 นาที)</li>
          <li>ถ้ามีปัญหา ย้อนกลับได้ด้วยคำสั่ง sudo smart-campus rollback บนเซิร์ฟเวอร์</li>
          <li v-if="confirmTarget.prerelease" class="text-amber-700">
            รุ่นนี้เป็นรุ่นทดสอบ ควรอัปเดตนอกเวลาใช้งาน
          </li>
        </ul>
        <label class="mt-4 block text-sm font-medium text-gray-700" for="update-password"
          >ยืนยันด้วยรหัสผ่านของคุณ</label
        >
        <input
          id="update-password"
          v-model="password"
          data-test="confirm-password"
          type="password"
          autocomplete="current-password"
          class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
          @keyup.enter="confirmUpdate"
        />
        <p v-if="passwordError" data-test="password-error" class="mt-1 text-sm text-red-600">
          {{ passwordError }}
        </p>
        <div class="mt-6 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            @click="closeConfirm"
          >
            ยกเลิก
          </button>
          <button
            data-test="confirm-update"
            type="button"
            :disabled="submitting"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
            @click="confirmUpdate"
          >
            {{ submitting ? 'กำลังส่งคำขอ...' : 'อัปเดตเลย' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
