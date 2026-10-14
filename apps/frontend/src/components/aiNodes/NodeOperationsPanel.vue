<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import aiNodesService from '@/services/aiNodes'
import { useAuthStore } from '@/stores/auth'

/**
 * COM-03 node operations: allowlisted remote commands and versioned
 * runtime settings with rollback. Viewing needs ai_node.view; acting needs
 * ai_node.manage (both enforced by the backend, incl. data scope).
 */
const props = defineProps({ nodeUuid: { type: String, required: true } })

const authStore = useAuthStore()
const canManage = computed(() => authStore.can('ai_node.manage'))

const COMMANDS = [
  { value: 'RESYNC_CONFIG', label: 'ซิงก์การตั้งค่าทันที' },
  { value: 'RESTART_SERVICE', label: 'รีสตาร์ทบริการ AI Node' },
]
const SETTING_LABELS = {
  target_fps: 'Target FPS',
  heartbeat_interval_seconds: 'Heartbeat (วินาที)',
  config_sync_interval_seconds: 'ซิงก์การตั้งค่า (วินาที)',
  camera_health_interval_seconds: 'รายงานสุขภาพกล้อง (วินาที)',
  command_poll_interval_seconds: 'ตรวจคำสั่ง (วินาที)',
}

const commands = ref([])
const revisions = ref([])
const appliedRevision = ref(null)
const schema = ref({})
const reason = ref('')
const note = ref('')
const draft = reactive({})
const busy = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const latest = computed(() => revisions.value[0] || null)

async function load() {
  try {
    const [cmd, cfg] = await Promise.all([
      aiNodesService.listCommands(props.nodeUuid),
      aiNodesService.listConfigRevisions(props.nodeUuid),
    ])
    commands.value = cmd.data
    revisions.value = cfg.data.revisions
    appliedRevision.value = cfg.data.applied_revision
    schema.value = cfg.data.schema
    for (const key of Object.keys(cfg.data.schema)) {
      draft[key] = latest.value?.settings?.[key] ?? ''
    }
  } catch (err) {
    errorMessage.value = err?.message || 'โหลดข้อมูลไม่สำเร็จ'
  }
}

async function act(fn, done) {
  errorMessage.value = ''
  successMessage.value = ''
  busy.value = true
  try {
    await fn()
    successMessage.value = done
    await load()
  } catch (err) {
    errorMessage.value = err?.message || 'ดำเนินการไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

function send(command) {
  return act(
    () => aiNodesService.sendCommand(props.nodeUuid, command, reason.value || null),
    'ส่งคำสั่งแล้ว รอ AI Node รับไปดำเนินการ',
  )
}

function saveSettings() {
  const settings = {}
  for (const [key, value] of Object.entries(draft)) {
    if (value !== '' && value !== null) settings[key] = Number(value)
  }
  return act(
    () => aiNodesService.saveConfigRevision(props.nodeUuid, settings, note.value || null),
    'บันทึกการตั้งค่าเป็น revision ใหม่แล้ว',
  )
}

function rollback(revision) {
  return act(
    () => aiNodesService.rollbackConfig(props.nodeUuid, revision),
    `ย้อนกลับไปใช้ revision ${revision} แล้ว (สร้างเป็น revision ใหม่)`,
  )
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString('th-TH') : '-'
}

onMounted(load)
</script>

<template>
  <div class="space-y-6 text-sm">
    <div
      v-if="successMessage"
      class="rounded-md border border-green-200 bg-green-50 p-3 text-green-800"
      role="status"
    >
      {{ successMessage }}
    </div>
    <div
      v-if="errorMessage"
      class="rounded-md border border-red-200 bg-red-50 p-3 text-red-800"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <section class="rounded-xl border border-gray-200 bg-white p-6">
      <h3 class="font-semibold text-gray-900">คำสั่งระยะไกล</h3>
      <p class="mt-1 text-xs text-gray-500">
        ส่งได้เฉพาะคำสั่งที่กำหนดไว้ ทุกคำสั่งถูกบันทึก audit
        และหมดอายุหากไม่ดำเนินการภายในเวลาที่กำหนด
      </p>
      <div v-if="canManage" class="mt-3 flex flex-wrap items-center gap-2">
        <input
          v-model="reason"
          data-test="command-reason"
          class="w-64 rounded-md border border-gray-300 px-2 py-1"
          placeholder="เหตุผล (ถ้ามี)"
        />
        <button
          v-for="c in COMMANDS"
          :key="c.value"
          :data-test="`send-${c.value}`"
          type="button"
          :disabled="busy"
          class="rounded-md border border-gray-300 px-3 py-1 hover:bg-gray-50 disabled:opacity-50"
          @click="send(c.value)"
        >
          {{ c.label }}
        </button>
      </div>
      <table class="mt-3 w-full text-left">
        <thead class="text-xs text-gray-500">
          <tr>
            <th class="py-1">เวลา</th>
            <th>คำสั่ง</th>
            <th>สถานะ</th>
            <th>ผู้สั่ง</th>
            <th>ผลลัพธ์</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="commands.length === 0">
            <td colspan="5" class="py-2 text-gray-500">ยังไม่มีคำสั่ง</td>
          </tr>
          <tr
            v-for="c in commands"
            :key="c.uuid"
            data-test="command-row"
            class="border-t border-gray-100"
          >
            <td class="py-1">{{ formatDate(c.created_at) }}</td>
            <td class="font-mono text-xs">{{ c.command }}</td>
            <td>{{ c.status }}</td>
            <td>{{ c.requested_by?.username || '-' }}</td>
            <td class="text-gray-600">{{ c.result_message || '-' }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6">
      <h3 class="font-semibold text-gray-900">การตั้งค่าการทำงาน (มีเวอร์ชัน)</h3>
      <p class="mt-1 text-xs text-gray-500" data-test="revision-status">
        revision ล่าสุด: {{ latest?.revision ?? '-' }} · AI Node ใช้งานอยู่:
        {{ appliedRevision ?? '-' }}
      </p>
      <form
        v-if="canManage"
        class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2"
        @submit.prevent="saveSettings"
      >
        <label v-for="(bounds, key) in schema" :key="key" class="text-gray-700">
          {{ SETTING_LABELS[key] || key }} ({{ bounds.min }}–{{ bounds.max }})
          <input
            v-model="draft[key]"
            :data-test="`setting-${key}`"
            type="number"
            :min="bounds.min"
            :max="bounds.max"
            class="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1"
          />
        </label>
        <input
          v-model="note"
          class="rounded-md border border-gray-300 px-2 py-1 md:col-span-2"
          placeholder="หมายเหตุ (ถ้ามี)"
        />
        <div class="md:col-span-2">
          <button
            data-test="save-settings"
            type="submit"
            :disabled="busy"
            class="rounded-md bg-blue-600 px-4 py-1.5 text-white hover:bg-blue-700 disabled:opacity-50"
          >
            บันทึกเป็น revision ใหม่
          </button>
        </div>
      </form>
      <table class="mt-4 w-full text-left">
        <thead class="text-xs text-gray-500">
          <tr>
            <th class="py-1">Revision</th>
            <th>ค่า</th>
            <th>ผู้บันทึก</th>
            <th>เวลา</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in revisions"
            :key="r.uuid"
            data-test="revision-row"
            class="border-t border-gray-100 align-top"
          >
            <td class="py-1">
              {{ r.revision }}
              <span v-if="r.rolled_back_from_revision" class="text-xs text-gray-500"
                >(จาก {{ r.rolled_back_from_revision }})</span
              >
            </td>
            <td class="font-mono text-xs">{{ JSON.stringify(r.settings) }}</td>
            <td>{{ r.created_by?.username || '-' }}</td>
            <td>{{ formatDate(r.created_at) }}</td>
            <td>
              <button
                v-if="canManage && r.revision !== latest?.revision"
                :data-test="`rollback-${r.revision}`"
                type="button"
                :disabled="busy"
                class="text-xs text-blue-600 hover:underline"
                @click="rollback(r.revision)"
              >
                ย้อนกลับไปใช้
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
