<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { staffService } from '@/services/staff'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const staff = ref(null)
const history = ref([])
const loading = ref(true)
const error = ref('')
const showStatus = ref(false)
const newStatus = ref('')
const reason = ref('')
const saving = ref(false)
const labels = {
  ACTIVE: 'ปฏิบัติงาน',
  LEAVE: 'ลา',
  SUSPENDED: 'พักงาน',
  RESIGNED: 'ลาออก',
  RETIRED: 'เกษียณ',
  TEACHER: 'ครู',
  STAFF: 'บุคลากร',
  MANUAL: 'บันทึกโดยผู้ดูแล',
  IMPORT: 'นำเข้าข้อมูล',
  API: 'API',
}
async function load() {
  loading.value = true
  error.value = ''
  try {
    staff.value = await staffService.get(route.params.uuid)
    newStatus.value = staff.value.employment_status
    history.value = (await staffService.statusHistory(route.params.uuid)).data
  } catch (err) {
    error.value = err.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ'
  } finally {
    loading.value = false
  }
}
async function changeStatus() {
  saving.value = true
  error.value = ''
  try {
    await staffService.changeStatus(route.params.uuid, {
      status: newStatus.value,
      reason: reason.value || undefined,
    })
    showStatus.value = false
    reason.value = ''
    await load()
  } catch (err) {
    error.value = err.response?.data?.message || 'เปลี่ยนสถานะไม่สำเร็จ'
  } finally {
    saving.value = false
  }
}
onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-5xl p-6">
    <RouterLink to="/staff" class="text-sm text-blue-700">← กลับรายการบุคลากร</RouterLink>
    <p v-if="error" role="alert" class="my-4 rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
    <p v-if="loading" class="py-12 text-center text-gray-500">กำลังโหลดข้อมูล…</p>
    <template v-else-if="staff">
      <header class="my-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold">
            {{
              staff.person?.display_name ||
              `${staff.person?.first_name || ''} ${staff.person?.last_name || ''}`
            }}
          </h1>
          <p class="mt-1 text-sm text-gray-500">
            {{ labels[staff.person?.person_type] || staff.person?.person_type }} · รหัส
            {{ staff.staff_code }}
          </p>
        </div>
        <div class="flex gap-2">
          <RouterLink
            v-if="auth.can('staff.update')"
            :to="`/staff/${staff.uuid}/edit`"
            class="rounded-lg border px-4 py-2"
            >แก้ไข</RouterLink
          ><button
            v-if="auth.can('staff.status.change')"
            class="rounded-lg bg-amber-500 px-4 py-2 text-white"
            @click="showStatus = true"
          >
            เปลี่ยนสถานะ
          </button>
        </div>
      </header>
      <section class="grid gap-4 md:grid-cols-2">
        <article class="rounded-xl border bg-white p-5">
          <h2 class="mb-4 font-semibold">ข้อมูลบุคลากร</h2>
          <dl class="grid grid-cols-[9rem_1fr] gap-y-3 text-sm">
            <dt class="text-gray-500">สถานะ</dt>
            <dd>{{ labels[staff.employment_status] || staff.employment_status }}</dd>
            <dt class="text-gray-500">ตำแหน่ง</dt>
            <dd>{{ staff.position || '—' }}</dd>
            <dt class="text-gray-500">หน่วยงาน</dt>
            <dd>{{ staff.department?.name || '—' }}</dd>
            <dt class="text-gray-500">โทรศัพท์</dt>
            <dd>{{ staff.phone || '—' }}</dd>
            <dt class="text-gray-500">อีเมล</dt>
            <dd>{{ staff.email || '—' }}</dd>
            <dt class="text-gray-500">Person UUID</dt>
            <dd class="break-all font-mono text-xs">{{ staff.person?.uuid }}</dd>
          </dl>
        </article>
        <article class="rounded-xl border bg-white p-5">
          <h2 class="mb-4 font-semibold">ประวัติสถานะการทำงาน</h2>
          <p v-if="!history.length" class="text-sm text-gray-500">ยังไม่มีรายการประวัติ</p>
          <ol v-else class="space-y-4">
            <li v-for="item in history" :key="item.uuid" class="border-l-2 border-blue-200 pl-3">
              <p class="font-medium">
                {{ labels[item.old_status] || item.old_status || 'เริ่มต้น' }} →
                {{ labels[item.new_status] || item.new_status }}
              </p>
              <p class="text-xs text-gray-500">
                {{
                  item.effective_from ? new Date(item.effective_from).toLocaleString('th-TH') : ''
                }}
                · {{ labels[item.source] || item.source }}
              </p>
              <p v-if="item.reason" class="mt-1 text-sm">{{ item.reason }}</p>
              <p v-if="item.changed_by" class="text-xs text-gray-500">
                โดย {{ item.changed_by.display_name || item.changed_by.username }}
              </p>
            </li>
          </ol>
        </article>
      </section>
      <div v-if="showStatus" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
        <form
          class="w-full max-w-md space-y-4 rounded-xl bg-white p-6"
          @submit.prevent="changeStatus"
        >
          <h2 class="text-lg font-bold">เปลี่ยนสถานะการทำงาน</h2>
          <label class="block text-sm"
            >สถานะใหม่<select
              v-model="newStatus"
              required
              class="mt-1 w-full rounded-lg border p-2"
            >
              <option
                v-for="(label, value) in {
                  ACTIVE: 'ปฏิบัติงาน',
                  LEAVE: 'ลา',
                  SUSPENDED: 'พักงาน',
                  RESIGNED: 'ลาออก',
                  RETIRED: 'เกษียณ',
                }"
                :key="value"
                :value="value"
              >
                {{ label }}
              </option>
            </select></label
          ><label class="block text-sm"
            >เหตุผล (ถ้ามี)<textarea
              v-model.trim="reason"
              maxlength="1000"
              rows="3"
              class="mt-1 w-full rounded-lg border p-2"
            ></textarea>
          </label>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded border px-4 py-2" @click="showStatus = false">
              ยกเลิก</button
            ><button :disabled="saving" class="rounded bg-blue-600 px-4 py-2 text-white">
              ยืนยัน
            </button>
          </div>
        </form>
      </div>
    </template>
  </main>
</template>
