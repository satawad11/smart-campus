<script setup>
import { onMounted, ref } from 'vue'
import { guardiansService, studentsService } from '@/services/students'
import { apiErrorText } from '@/constants/labels'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const rows = ref([])
const students = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref('')
const keyword = ref('')
const form = ref({ first_name: '', last_name: '', person_code: '' })
const link = ref({ guardian_person_uuid: '', student_uuid: '', relation: 'GUARDIAN' })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [guardians, studentRows] = await Promise.all([
      guardiansService.list({ keyword: keyword.value || undefined, per_page: 50 }),
      auth.can('student.view')
        ? studentsService.list({ per_page: 100 })
        : Promise.resolve({ data: [] }),
    ])
    rows.value = guardians.data || []
    students.value = studentRows.data || []
  } catch (e) {
    error.value = apiErrorText(e, 'โหลดข้อมูลผู้ปกครองไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function createGuardian() {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const person = await guardiansService.create({
      ...form.value,
      first_name: form.value.first_name.trim(),
      last_name: form.value.last_name.trim(),
    })
    form.value = { first_name: '', last_name: '', person_code: '' }
    link.value.guardian_person_uuid = person.uuid
    success.value = 'เพิ่มผู้ปกครองแล้ว สามารถเลือกนักเรียนเพื่อเชื่อมโยงได้'
    await load()
  } catch (e) {
    error.value = apiErrorText(e, 'เพิ่มผู้ปกครองไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

async function linkStudent() {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await guardiansService.link(link.value.student_uuid, {
      guardian_person_uuid: link.value.guardian_person_uuid,
      relation: link.value.relation,
    })
    success.value = 'เชื่อมโยงผู้ปกครองกับนักเรียนแล้ว'
  } catch (e) {
    error.value = apiErrorText(e, 'เชื่อมโยงไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-6 p-6">
    <header>
      <h1 class="text-2xl font-bold text-gray-900">จัดการข้อมูลผู้ปกครอง</h1>
      <p class="mt-1 text-sm text-gray-500">
        ทะเบียนผู้ปกครองแยกจากนักเรียน พร้อมเชื่อมโยงกับระเบียนนักเรียน
      </p>
    </header>
    <p
      v-if="error"
      role="alert"
      class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
    >
      {{ error }}
    </p>
    <p
      v-if="success"
      role="status"
      class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700"
    >
      {{ success }}
    </p>
    <section
      v-if="auth.can('guardian.manage')"
      class="grid gap-5 rounded-xl border bg-surface p-5 lg:grid-cols-2"
    >
      <form class="space-y-3" @submit.prevent="createGuardian">
        <h2 class="font-semibold">เพิ่มผู้ปกครอง</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm"
            >ชื่อ<input
              v-model="form.first_name"
              required
              maxlength="255"
              class="mt-1 w-full rounded border px-3 py-2"
          /></label>
          <label class="text-sm"
            >นามสกุล<input
              v-model="form.last_name"
              required
              maxlength="255"
              class="mt-1 w-full rounded border px-3 py-2"
          /></label>
        </div>
        <label class="block text-sm"
          >รหัสบุคคล (ถ้ามี)<input
            v-model="form.person_code"
            maxlength="255"
            class="mt-1 w-full rounded border px-3 py-2"
        /></label>
        <button
          :disabled="busy"
          class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
          บันทึกผู้ปกครอง
        </button>
      </form>
      <form
        v-if="auth.can('student.view')"
        class="space-y-3 border-t pt-4 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0"
        @submit.prevent="linkStudent"
      >
        <h2 class="font-semibold">เชื่อมโยงกับนักเรียน</h2>
        <label class="block text-sm"
          >ผู้ปกครอง<select
            v-model="link.guardian_person_uuid"
            required
            class="mt-1 w-full rounded border px-3 py-2"
          >
            <option value="">เลือกผู้ปกครอง</option>
            <option v-for="p in rows" :key="p.uuid" :value="p.uuid">
              {{ p.name }} · {{ p.person_code || 'ไม่มีรหัส' }}
            </option>
          </select></label
        >
        <label class="block text-sm"
          >นักเรียน<select
            v-model="link.student_uuid"
            required
            class="mt-1 w-full rounded border px-3 py-2"
          >
            <option value="">เลือกนักเรียน</option>
            <option v-for="s in students" :key="s.uuid" :value="s.uuid">
              {{
                s.person?.display_name ||
                `${s.person?.first_name || ''} ${s.person?.last_name || ''}`
              }}
              · {{ s.student_code }}
            </option>
          </select></label
        >
        <label class="block text-sm"
          >ความสัมพันธ์<select v-model="link.relation" class="mt-1 w-full rounded border px-3 py-2">
            <option value="FATHER">บิดา</option>
            <option value="MOTHER">มารดา</option>
            <option value="GUARDIAN">ผู้ปกครอง</option>
            <option value="OTHER">อื่น ๆ</option>
          </select></label
        >
        <button
          :disabled="busy || !link.guardian_person_uuid || !link.student_uuid"
          class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
          เชื่อมโยง
        </button>
      </form>
      <p v-else class="self-center text-sm text-gray-500">
        ต้องมีสิทธิ์ดูข้อมูลนักเรียนจึงจะเชื่อมโยงผู้ปกครองได้
      </p>
    </section>
    <section class="overflow-hidden rounded-xl border bg-surface">
      <form class="flex gap-2 border-b p-4" @submit.prevent="load">
        <input
          v-model="keyword"
          type="search"
          placeholder="ค้นหาชื่อหรือรหัสบุคคล"
          class="min-w-0 flex-1 rounded border px-3 py-2 text-sm"
        /><button class="rounded border px-4 py-2 text-sm">ค้นหา</button>
      </form>
      <p v-if="loading" class="p-8 text-center text-sm text-gray-500">กำลังโหลด…</p>
      <div v-else-if="rows.length" class="divide-y">
        <div v-for="p in rows" :key="p.uuid" class="flex flex-wrap justify-between gap-2 p-4">
          <div>
            <p class="font-medium">{{ p.name }}</p>
            <p class="text-sm text-gray-500">
              {{ p.person_type }} · {{ p.person_code || 'ไม่มีรหัส' }} · {{ p.status }}
            </p>
          </div>
          <router-link
            v-if="p.person_type === 'PARENT'"
            :to="{ name: 'faces.enroll', query: { person: p.uuid } }"
            class="text-sm text-blue-700"
            >ลงทะเบียนใบหน้า</router-link
          >
        </div>
      </div>
      <p v-else class="p-8 text-center text-sm text-gray-500">ไม่พบข้อมูลผู้ปกครอง</p>
    </section>
  </main>
</template>
