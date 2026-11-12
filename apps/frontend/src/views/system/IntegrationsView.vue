<script setup>
import { ref, computed, onMounted } from 'vue'
import { integrationsService } from '@/services/integrations'
import { apiErrorText } from '@/constants/labels'
import { useAuthStore } from '@/stores/auth'

/**
 * External API connections (ACR-INT-02). RMS API Center is the only
 * connector in V1. The API key is write-only: the browser never receives it,
 * only whether one is set, where it comes from and its non-secret id.
 */

const authStore = useAuthStore()
const canManage = computed(() => authStore.hasPermission('integration.manage'))

const rms = ref(null)
const form = ref(emptyForm())
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const showKey = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})
const notice = ref('')
const testResult = ref(null)

const SOURCE_LABELS = { database: 'บันทึกจากหน้าเว็บ', env: 'จากไฟล์ .env ของเซิร์ฟเวอร์' }

function emptyForm() {
  return {
    base_url: '',
    api_key: '',
    clear_api_key: false,
    timeout_seconds: 30,
    sync_enabled: false,
    sync_time: '05:30',
  }
}

function fill(connection) {
  rms.value = connection
  form.value = {
    base_url: connection.base_url ?? '',
    api_key: '',
    clear_api_key: false,
    timeout_seconds: connection.timeout_seconds ?? 30,
    sync_enabled: !!connection.sync_enabled,
    sync_time: connection.sync_time ?? '05:30',
  }
  showKey.value = false
}

const dirty = computed(() => {
  const c = rms.value
  if (!c) return false
  const f = form.value
  return (
    f.base_url !== (c.base_url ?? '') ||
    f.api_key !== '' ||
    f.clear_api_key ||
    Number(f.timeout_seconds) !== c.timeout_seconds ||
    f.sync_enabled !== !!c.sync_enabled ||
    f.sync_time !== (c.sync_time ?? '05:30')
  )
})

const urlChanged = computed(() => rms.value && form.value.base_url !== (rms.value.base_url ?? ''))

const status = computed(() => {
  const c = rms.value
  if (!c) return { label: '-', tone: 'gray' }
  if (c.api_key_unreadable && !c.api_key_set) {
    return { label: 'อ่านกุญแจที่บันทึกไว้ไม่ได้', tone: 'red' }
  }
  if (!c.api_key_set) return { label: 'ยังไม่ได้ตั้งค่ากุญแจ', tone: 'amber' }
  if (!c.last_test) return { label: 'ยังไม่ได้ทดสอบ', tone: 'gray' }
  return c.last_test.ok
    ? { label: 'เชื่อมต่อได้', tone: 'green' }
    : { label: 'เชื่อมต่อไม่ได้', tone: 'red' }
})

const TONES = {
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  red: 'bg-red-50 text-red-700 ring-red-600/20',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  gray: 'bg-gray-50 text-gray-600 ring-gray-500/20',
}

function formatTime(iso) {
  return iso ? new Date(iso).toLocaleString('th-TH') : '-'
}

async function load() {
  loading.value = true
  errorMessage.value = ''
  try {
    const list = await integrationsService.list()
    const connection = list.find((c) => c.code === 'RMS')
    if (connection) fill(connection)
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'โหลดการตั้งค่าการเชื่อมต่อไม่ได้')
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!canManage.value) return
  saving.value = true
  errorMessage.value = ''
  fieldErrors.value = {}
  notice.value = ''
  testResult.value = null
  try {
    const payload = {
      base_url: form.value.base_url.trim(),
      timeout_seconds: Number(form.value.timeout_seconds),
      sync_enabled: form.value.sync_enabled,
      sync_time: form.value.sync_time,
    }
    if (form.value.clear_api_key) {
      payload.clear_api_key = true
    } else if (form.value.api_key.trim() !== '') {
      payload.api_key = form.value.api_key.trim()
    }
    fill(await integrationsService.updateRms(payload))
    notice.value = 'บันทึกการตั้งค่าแล้ว กด "ทดสอบการเชื่อมต่อ" เพื่อตรวจสอบกับ RMS'
  } catch (err) {
    fieldErrors.value = err?.fields ?? {}
    errorMessage.value = apiErrorText(err, 'บันทึกการตั้งค่าไม่ได้')
  } finally {
    saving.value = false
  }
}

async function test() {
  if (!canManage.value || dirty.value) return
  testing.value = true
  errorMessage.value = ''
  notice.value = ''
  testResult.value = null
  try {
    const { result, connection } = await integrationsService.testRms()
    testResult.value = result
    if (connection) fill(connection)
  } catch (err) {
    errorMessage.value = apiErrorText(err, 'ทดสอบการเชื่อมต่อไม่ได้')
  } finally {
    testing.value = false
  }
}

function fieldError(name) {
  const e = fieldErrors.value[name]
  return Array.isArray(e) ? e[0] : e
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">การเชื่อมต่อระบบภายนอก</h1>
      <p class="mt-1 text-sm text-gray-500">
        ตั้งค่าที่อยู่และกุญแจ API ของระบบภายนอกที่แพลตฟอร์มดึงข้อมูลมาใช้
        การเรียกทุกครั้งทำจากเซิร์ฟเวอร์เท่านั้น กุญแจถูกเข้ารหัสและไม่ถูกส่งกลับมาที่เบราว์เซอร์
      </p>
    </div>

    <div v-if="notice" class="rounded-md bg-blue-50 p-3 text-sm text-blue-800" data-test="notice">
      {{ notice }}
    </div>
    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-3 text-sm text-red-700"
      role="alert"
      data-test="error"
    >
      {{ errorMessage }}
    </div>

    <p v-if="loading && !rms" class="text-sm text-gray-500">กำลังโหลด...</p>

    <section
      v-if="rms"
      class="rounded-xl border border-gray-200 bg-surface shadow-sm"
      data-test="rms-card"
    >
      <header
        class="flex flex-col gap-3 border-b border-gray-100 p-5 sm:flex-row sm:items-start sm:justify-between"
      >
        <div class="flex items-start gap-3">
          <div
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-sm font-bold text-white"
          >
            RMS
          </div>
          <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ rms.name }}</h2>
            <p class="text-sm text-gray-500">{{ rms.description }}</p>
          </div>
        </div>
        <span
          class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset"
          :class="TONES[status.tone]"
          data-test="status"
        >
          {{ status.label }}
        </span>
      </header>

      <div class="grid gap-6 p-5 lg:grid-cols-3">
        <form class="space-y-5 lg:col-span-2" @submit.prevent="save">
          <div>
            <label for="rms-url" class="block text-sm font-medium text-gray-700">ที่อยู่ API</label>
            <input
              id="rms-url"
              v-model="form.base_url"
              type="url"
              required
              :disabled="!canManage"
              placeholder="https://rms.example.ac.th/api_center.php"
              class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50"
              data-test="base-url"
            />
            <p v-if="fieldError('base_url')" class="mt-1 text-xs text-red-600">
              {{ fieldError('base_url') }}
            </p>
            <p v-else-if="urlChanged && rms.api_key_set" class="mt-1 text-xs text-amber-700">
              เมื่อเปลี่ยนที่อยู่ API ต้องกรอกกุญแจ API อีกครั้ง
            </p>
          </div>

          <div>
            <label for="rms-key" class="block text-sm font-medium text-gray-700">
              กุญแจ API (rmsk_...)
            </label>
            <div class="mt-1 flex gap-2">
              <input
                id="rms-key"
                v-model="form.api_key"
                :type="showKey ? 'text' : 'password'"
                autocomplete="new-password"
                spellcheck="false"
                :disabled="!canManage || form.clear_api_key"
                :placeholder="
                  rms.api_key_set
                    ? `ตั้งค่าแล้ว (${rms.api_key_hint}) เว้นว่างเพื่อใช้กุญแจเดิม`
                    : 'วางกุญแจที่ได้จากผู้ดูแลระบบ RMS'
                "
                class="block w-full rounded-md border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-50"
                data-test="api-key"
              />
              <button
                v-if="canManage"
                type="button"
                class="shrink-0 rounded-md border border-gray-300 px-3 text-sm text-gray-600 hover:bg-gray-50"
                @click="showKey = !showKey"
              >
                {{ showKey ? 'ซ่อน' : 'แสดง' }}
              </button>
            </div>
            <p v-if="fieldError('api_key')" class="mt-1 text-xs text-red-600">
              {{ fieldError('api_key') }}
            </p>
            <p v-else class="mt-1 text-xs text-gray-500" data-test="key-source">
              <template v-if="rms.api_key_set">
                กุญแจปัจจุบัน {{ rms.api_key_hint }} · {{ SOURCE_LABELS[rms.api_key_source] }}
              </template>
              <template v-else-if="rms.api_key_unreadable">
                อ่านกุญแจที่บันทึกไว้ไม่ได้ (APP_KEY ของเซิร์ฟเวอร์อาจถูกเปลี่ยน) กรุณากรอกใหม่
              </template>
              <template v-else>ยังไม่มีกุญแจ</template>
            </p>
            <label
              v-if="canManage && rms.api_key_source === 'database'"
              class="mt-2 flex items-center gap-2 text-sm text-gray-700"
            >
              <input
                v-model="form.clear_api_key"
                type="checkbox"
                class="rounded border-gray-300"
                data-test="clear-key"
              />
              ลบกุญแจที่บันทึกจากหน้าเว็บ (กลับไปใช้ค่าใน .env ถ้ามี)
            </label>
          </div>

          <div class="grid gap-5 sm:grid-cols-3">
            <div>
              <label for="rms-timeout" class="block text-sm font-medium text-gray-700">
                รอการตอบกลับ (วินาที)
              </label>
              <input
                id="rms-timeout"
                v-model.number="form.timeout_seconds"
                type="number"
                min="5"
                max="120"
                :disabled="!canManage"
                class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm disabled:bg-gray-50"
              />
              <p v-if="fieldError('timeout_seconds')" class="mt-1 text-xs text-red-600">
                {{ fieldError('timeout_seconds') }}
              </p>
            </div>
            <div>
              <span class="block text-sm font-medium text-gray-700">ซิงก์อัตโนมัติรายวัน</span>
              <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                <input
                  v-model="form.sync_enabled"
                  type="checkbox"
                  :disabled="!canManage"
                  class="rounded border-gray-300"
                  data-test="sync-enabled"
                />
                {{ form.sync_enabled ? 'เปิด' : 'ปิด' }}
              </label>
              <p v-if="fieldError('sync_enabled')" class="mt-1 text-xs text-red-600">
                {{ fieldError('sync_enabled') }}
              </p>
            </div>
            <div>
              <label for="rms-time" class="block text-sm font-medium text-gray-700">
                เวลาซิงก์
              </label>
              <input
                id="rms-time"
                v-model="form.sync_time"
                type="time"
                :disabled="!canManage || !form.sync_enabled"
                class="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm disabled:bg-gray-50"
              />
              <p v-if="fieldError('sync_time')" class="mt-1 text-xs text-red-600">
                {{ fieldError('sync_time') }}
              </p>
            </div>
          </div>
          <p class="text-xs text-gray-500">
            เปิดซิงก์อัตโนมัติหลังจากตรวจผล "ทดลองซิงก์" ในหน้าซิงก์ข้อมูลจาก RMS แล้วเท่านั้น
          </p>

          <div v-if="canManage" class="flex flex-wrap gap-3 border-t border-gray-100 pt-4">
            <button
              type="submit"
              :disabled="saving || !dirty"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
              data-test="save"
            >
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
            <button
              type="button"
              :disabled="testing || dirty || !rms.api_key_set"
              :title="dirty ? 'บันทึกการเปลี่ยนแปลงก่อนทดสอบ' : ''"
              class="rounded-md border border-gray-300 bg-surface px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50"
              data-test="test"
              @click="test"
            >
              {{ testing ? 'กำลังทดสอบ...' : 'ทดสอบการเชื่อมต่อ' }}
            </button>
            <span v-if="dirty" class="self-center text-xs text-gray-500">
              บันทึกก่อนจึงจะทดสอบได้
            </span>
          </div>
          <p v-else class="text-sm text-gray-500">
            ดูได้อย่างเดียว การแก้ไขต้องใช้สิทธิ์ integration.manage
          </p>
        </form>

        <aside class="space-y-4 text-sm">
          <div
            v-if="testResult"
            class="rounded-lg p-3"
            :class="testResult.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'"
            data-test="test-result"
          >
            <p class="font-medium">
              {{ testResult.ok ? 'เชื่อมต่อสำเร็จ' : 'เชื่อมต่อไม่สำเร็จ' }}
            </p>
            <p class="mt-1">{{ testResult.message }}</p>
            <p class="mt-1 text-xs opacity-75">{{ testResult.duration_ms }} ms</p>
          </div>

          <dl class="space-y-2 rounded-lg bg-gray-50 p-3 text-gray-700">
            <div>
              <dt class="text-xs text-gray-500">ทดสอบล่าสุด</dt>
              <dd v-if="rms.last_test">
                {{ formatTime(rms.last_test.tested_at) }} ·
                <span :class="rms.last_test.ok ? 'text-emerald-700' : 'text-red-700'">
                  {{ rms.last_test.ok ? 'สำเร็จ' : 'ไม่สำเร็จ' }}
                </span>
                <span class="block text-xs text-gray-500">{{ rms.last_test.message }}</span>
              </dd>
              <dd v-else>ยังไม่เคยทดสอบ</dd>
            </div>
            <div>
              <dt class="text-xs text-gray-500">แก้ไขล่าสุด</dt>
              <dd>
                {{ formatTime(rms.updated_at) }}
                <span v-if="rms.updated_by">โดย {{ rms.updated_by }}</span>
              </dd>
            </div>
          </dl>

          <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
            <p class="font-medium">ก่อนเชื่อมต่อ</p>
            <ul class="mt-1 list-disc space-y-1 pl-4">
              <li>ขอกุญแจจากผู้ดูแลระบบ RMS (ระบบผู้ดูแลระบบ → API Center → ออกกุญแจให้แอปใหม่)</li>
              <li>
                เลือกเฉพาะข้อมูลที่ใช้: school.read, staff.read, classes.read, students.read
                ไม่ต้องเลือกเลขบัตรประชาชน
              </li>
              <li>ใส่ IP ของเซิร์ฟเวอร์นี้ในช่อง "IP ที่อนุญาต" ของกุญแจ</li>
              <li>กรอกกุญแจผิด 10 ครั้งใน 15 นาที RMS จะระงับการเรียก 15 นาที</li>
            </ul>
          </div>
        </aside>
      </div>
    </section>
  </div>
</template>
