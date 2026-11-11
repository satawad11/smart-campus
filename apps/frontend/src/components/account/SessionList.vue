<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'

/**
 * The signed-in user's own sessions (AUTH-012 API; V1 audit A-17):
 * see where the account is signed in and sign out other devices.
 */
const sessions = ref([])
const errorMessage = ref('')
const message = ref('')
const busy = ref(false)

const active = computed(() => sessions.value.filter((s) => !s.revoked_at && !s.logout_at))
const others = computed(() => active.value.filter((s) => !s.is_current))

function formatDate(value) {
  return value ? new Date(value).toLocaleString('th-TH') : '-'
}

async function load() {
  errorMessage.value = ''
  try {
    const response = await api.get('/auth/sessions')
    sessions.value = response.data.data
  } catch (err) {
    errorMessage.value = err?.message || 'โหลดรายการ session ไม่สำเร็จ'
  }
}

async function run(fn, done) {
  errorMessage.value = ''
  message.value = ''
  busy.value = true
  try {
    await fn()
    await load()
    message.value = done
  } catch (err) {
    errorMessage.value = err?.message || 'ดำเนินการไม่สำเร็จ'
  } finally {
    busy.value = false
  }
}

function revoke(session) {
  return run(() => api.delete(`/auth/sessions/${session.uuid}`), 'ออกจากระบบอุปกรณ์นั้นแล้ว')
}

function revokeOthers() {
  return run(() => api.post('/auth/sessions/revoke-others'), 'ออกจากระบบอุปกรณ์อื่นทั้งหมดแล้ว')
}

onMounted(load)
</script>

<template>
  <section class="rounded-lg border border-gray-200 bg-surface p-6 shadow-sm">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold text-gray-900">อุปกรณ์ที่เข้าสู่ระบบอยู่</h2>
      <button
        v-if="others.length > 0"
        data-test="revoke-others"
        type="button"
        :disabled="busy"
        class="rounded-md border border-red-300 bg-surface px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
        @click="revokeOthers"
      >
        ออกจากระบบอุปกรณ์อื่นทั้งหมด
      </button>
    </div>
    <p v-if="message" class="mt-2 text-sm text-green-700" role="status">{{ message }}</p>
    <p v-if="errorMessage" class="mt-2 text-sm text-red-700" role="alert">{{ errorMessage }}</p>
    <ul class="mt-3 divide-y divide-gray-100">
      <li
        v-for="session in active"
        :key="session.uuid"
        data-test="session"
        class="flex items-center justify-between py-2 text-sm"
      >
        <div>
          <div class="text-gray-900">
            {{ session.device_name || session.user_agent || 'อุปกรณ์ไม่ทราบชื่อ' }}
            <span
              v-if="session.is_current"
              class="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-800"
              >อุปกรณ์นี้</span
            >
          </div>
          <div class="text-xs text-gray-500">
            {{ session.ip_address || '-' }} · เข้าสู่ระบบ {{ formatDate(session.login_at) }} ·
            ใช้งานล่าสุด {{ formatDate(session.last_activity_at) }}
          </div>
        </div>
        <button
          v-if="!session.is_current"
          data-test="revoke"
          type="button"
          :disabled="busy"
          class="text-xs text-red-600 hover:underline disabled:opacity-50"
          @click="revoke(session)"
        >
          ออกจากระบบ
        </button>
      </li>
    </ul>
  </section>
</template>
