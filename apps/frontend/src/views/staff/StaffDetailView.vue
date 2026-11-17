<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import staffService from '@/services/staff'
import { apiErrorText } from '@/constants/labels'
import { STAFF_TYPES, EMPLOYMENT_STATUSES, employmentBadge, fullName } from '@/constants/staff'

/** One teacher/staff member: details, employment status change and its history. */
const route = useRoute()
const authStore = useAuthStore()
const uuid = route.params.uuid

const staff = ref(null)
const history = ref([])
const loading = ref(true)
const error = ref('')

const canUpdate = computed(() => authStore.can('staff.update'))
const canChangeStatus = computed(() => authStore.can('staff.status.change'))
const canEnroll = computed(() => authStore.can('face.enroll'))

const statusForm = ref({ open: false, status: '', reason: '', saving: false, error: '' })

async function load() {
  try {
    const [res, hist] = await Promise.all([
      staffService.get(uuid),
      staffService.statusHistory(uuid),
    ])
    staff.value = res.data
    history.value = hist.data || []
  } catch (err) {
    error.value = apiErrorText(err, 'โหลดข้อมูลบุคลากรไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function openStatus() {
  statusForm.value = { open: true, status: '', reason: '', saving: false, error: '' }
}

async function saveStatus() {
  statusForm.value.saving = true
  statusForm.value.error = ''
  try {
    await staffService.changeStatus(uuid, statusForm.value.status, statusForm.value.reason || null)
    statusForm.value.open = false
    await load()
  } catch (err) {
    statusForm.value.error = apiErrorText(err, 'เปลี่ยนสถานะไม่สำเร็จ')
  } finally {
    statusForm.value.saving = false
  }
}

function date(iso) {
  return iso ? new Date(iso).toLocaleString('th-TH') : '-'
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6 p-6">
    <router-link :to="{ name: 'staff.index' }" class="text-sm text-blue-600"
      >← กลับไปรายชื่อ</router-link
    >
    <p v-if="loading" class="text-gray-500">กำลังโหลด...</p>
    <p v-else-if="error" class="text-red-600">{{ error }}</p>

    <template v-else-if="staff">
      <div class="flex flex-wrap items-start justify-between gap-3 border-b pb-4">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">{{ fullName(staff.person) }}</h1>
          <p class="text-sm text-gray-500">
            {{ STAFF_TYPES[staff.person?.person_type] }} · รหัส {{ staff.staff_code }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <router-link
            v-if="canEnroll"
            :to="{
              name: 'faces.enroll',
              query: { person: staff.person?.uuid, name: fullName(staff.person) },
            }"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
            >ลงทะเบียนใบหน้า</router-link
          >
          <router-link
            :to="{ name: 'persons.timeline', params: { uuid: staff.person?.uuid } }"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
            >ประวัติเข้า-ออก</router-link
          >
          <button
            v-if="canChangeStatus"
            type="button"
            data-test="staff-status-open"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
            @click="openStatus"
          >
            เปลี่ยนสถานะ
          </button>
          <router-link
            v-if="canUpdate"
            :to="{ name: 'staff.edit', params: { uuid } }"
            data-test="staff-edit"
            class="rounded-lg bg-blue-600 px-3 py-2 text-sm text-white hover:bg-blue-700"
            >แก้ไข</router-link
          >
        </div>
      </div>

      <div
        class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-surface p-6 text-sm sm:grid-cols-2"
      >
        <div>
          <span class="text-gray-500">สถานะ:</span>
          <span
            data-test="staff-status"
            class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="employmentBadge(staff.employment_status)"
            >{{ EMPLOYMENT_STATUSES[staff.employment_status] }}</span
          >
        </div>
        <div><span class="text-gray-500">ตำแหน่ง:</span> {{ staff.position || '-' }}</div>
        <div><span class="text-gray-500">แผนก:</span> {{ staff.department?.name || '-' }}</div>
        <div><span class="text-gray-500">โทรศัพท์:</span> {{ staff.phone || '-' }}</div>
        <div><span class="text-gray-500">อีเมล:</span> {{ staff.email || '-' }}</div>
        <div>
          <span class="text-gray-500">สถานะตั้งแต่:</span> {{ staff.status_start_date || '-' }}
        </div>
      </div>

      <div class="rounded-xl border border-gray-200 bg-surface p-6">
        <h2 class="mb-3 font-semibold text-gray-900">ประวัติสถานะการปฏิบัติงาน</h2>
        <ul class="divide-y divide-gray-100 text-sm">
          <li v-for="h in history" :key="h.uuid" data-test="staff-history" class="py-2">
            <span class="font-medium">
              {{ h.old_status ? EMPLOYMENT_STATUSES[h.old_status] + ' → ' : ''
              }}{{ EMPLOYMENT_STATUSES[h.new_status] }}
            </span>
            <span class="text-xs text-gray-500">
              · {{ date(h.effective_from) }} · {{ h.source }}</span
            >
            <span v-if="h.changed_by" class="text-xs text-gray-500">
              · โดย {{ h.changed_by.username }}</span
            >
            <p v-if="h.reason" class="text-xs text-gray-600">เหตุผล: {{ h.reason }}</p>
          </li>
          <li v-if="history.length === 0" class="py-2 text-gray-400">ยังไม่มีประวัติ</li>
        </ul>
      </div>
    </template>

    <div
      v-if="statusForm.open"
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="statusForm.open = false"
    >
      <form
        data-test="staff-status-form"
        class="w-full max-w-md space-y-4 rounded-xl bg-surface p-6 shadow-xl"
        @submit.prevent="saveStatus"
      >
        <h2 class="text-lg font-semibold">เปลี่ยนสถานะการปฏิบัติงาน</h2>
        <select
          v-model="statusForm.status"
          data-test="staff-status-select"
          required
          class="block w-full rounded-lg border px-3 py-2 text-sm"
        >
          <option value="" disabled>เลือกสถานะใหม่</option>
          <option
            v-for="(label, value) in EMPLOYMENT_STATUSES"
            :key="value"
            :value="value"
            :disabled="value === staff?.employment_status"
          >
            {{ label }}
          </option>
        </select>
        <textarea
          v-model="statusForm.reason"
          rows="2"
          placeholder="เหตุผล (ถ้ามี)"
          class="block w-full rounded-lg border px-3 py-2 text-sm"
        />
        <p v-if="statusForm.error" class="text-sm text-red-600">{{ statusForm.error }}</p>
        <div class="flex justify-end gap-2">
          <button
            type="button"
            class="rounded-lg border px-4 py-2 text-sm"
            @click="statusForm.open = false"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            data-test="staff-status-save"
            :disabled="statusForm.saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white disabled:opacity-50"
          >
            บันทึก
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
