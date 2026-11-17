<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import staffService from '@/services/staff'
import academicsService from '@/services/academics'
import { apiErrorText } from '@/constants/labels'
import { STAFF_TYPES } from '@/constants/staff'

/**
 * Add or edit a teacher/staff member. On edit, staff code and type are fixed and
 * employment status has its own workflow on the detail page (the API enforces both).
 */
const route = useRoute()
const router = useRouter()
const uuid = computed(() => route.params.uuid || null)
const isEdit = computed(() => uuid.value !== null)

const form = ref({
  first_name: '',
  last_name: '',
  display_name: '',
  gender: '',
  person_type: 'TEACHER',
  staff_code: '',
  position: '',
  department_uuid: '',
  phone: '',
  email: '',
})
const departments = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

function fieldError(name) {
  const v = fieldErrors.value[name]
  return Array.isArray(v) ? v[0] : v
}

function payload() {
  const f = form.value
  const person = {
    first_name: f.first_name,
    last_name: f.last_name,
    display_name: f.display_name || null,
    gender: f.gender || null,
  }
  const details = {
    position: f.position || null,
    department_uuid: f.department_uuid || null,
    phone: f.phone || null,
    email: f.email || null,
  }
  return isEdit.value
    ? { person, ...details }
    : { person, person_type: f.person_type, staff_code: f.staff_code, ...details }
}

async function save() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    const res = isEdit.value
      ? await staffService.update(uuid.value, payload())
      : await staffService.create(payload())
    router.push({ name: 'staff.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    fieldErrors.value = err?.fields || {}
    error.value = apiErrorText(err, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    departments.value = await academicsService.listDepartments({ per_page: 100, status: 'ACTIVE' })
  } catch {
    departments.value = []
  }
  if (!isEdit.value) return
  loading.value = true
  try {
    const { data } = await staffService.get(uuid.value)
    form.value = {
      first_name: data.person?.first_name || '',
      last_name: data.person?.last_name || '',
      display_name: data.person?.display_name || '',
      gender: data.person?.gender || '',
      person_type: data.person?.person_type || 'STAFF',
      staff_code: data.staff_code,
      position: data.position || '',
      department_uuid: data.department?.uuid || '',
      phone: data.phone || '',
      email: data.email || '',
    }
  } catch (err) {
    error.value = apiErrorText(err, 'โหลดข้อมูลไม่สำเร็จ')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-6 p-6">
    <div class="border-b pb-4">
      <router-link :to="{ name: 'staff.index' }" class="text-sm text-blue-600">← กลับ</router-link>
      <h1 class="mt-2 text-2xl font-bold text-gray-900">
        {{ isEdit ? 'แก้ไขข้อมูลบุคลากร' : 'เพิ่มบุคลากร' }}
      </h1>
    </div>

    <p v-if="loading" class="text-gray-500">กำลังโหลด...</p>

    <form
      v-else
      data-test="staff-form"
      class="grid grid-cols-1 gap-4 rounded-xl border border-gray-200 bg-surface p-6 sm:grid-cols-2"
      @submit.prevent="save"
    >
      <label class="text-sm text-gray-700">
        ชื่อ *
        <input
          v-model="form.first_name"
          data-test="f-first"
          required
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
        <span v-if="fieldError('person.first_name')" class="text-xs text-red-600">{{
          fieldError('person.first_name')
        }}</span>
      </label>
      <label class="text-sm text-gray-700">
        นามสกุล *
        <input
          v-model="form.last_name"
          data-test="f-last"
          required
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
      </label>
      <label class="text-sm text-gray-700">
        ชื่อที่แสดง
        <input
          v-model="form.display_name"
          placeholder="เช่น ครูสมชาย"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
      </label>
      <label class="text-sm text-gray-700">
        เพศ
        <select v-model="form.gender" class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm">
          <option value="">- ไม่ระบุ -</option>
          <option value="MALE">ชาย</option>
          <option value="FEMALE">หญิง</option>
          <option value="OTHER">อื่นๆ</option>
        </select>
      </label>

      <label class="text-sm text-gray-700">
        ประเภท *
        <select
          v-model="form.person_type"
          data-test="f-type"
          :disabled="isEdit"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
        >
          <option v-for="(label, value) in STAFF_TYPES" :key="value" :value="value">
            {{ label }}
          </option>
        </select>
      </label>
      <label class="text-sm text-gray-700">
        รหัสบุคลากร *
        <input
          v-model="form.staff_code"
          data-test="f-code"
          required
          :disabled="isEdit"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
        />
        <span v-if="isEdit" class="text-xs text-gray-400">รหัสและประเภทแก้ไขไม่ได้หลังสร้าง</span>
        <span v-if="fieldError('staff_code')" class="text-xs text-red-600">{{
          fieldError('staff_code')
        }}</span>
      </label>

      <label class="text-sm text-gray-700">
        ตำแหน่ง
        <input
          v-model="form.position"
          data-test="f-position"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
      </label>
      <label class="text-sm text-gray-700">
        แผนก
        <select
          v-model="form.department_uuid"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        >
          <option value="">- ไม่ระบุ -</option>
          <option v-for="d in departments" :key="d.uuid" :value="d.uuid">{{ d.name }}</option>
        </select>
      </label>
      <label class="text-sm text-gray-700">
        โทรศัพท์
        <input v-model="form.phone" class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm" />
      </label>
      <label class="text-sm text-gray-700">
        อีเมล
        <input
          v-model="form.email"
          type="email"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
        <span v-if="fieldError('email')" class="text-xs text-red-600">{{
          fieldError('email')
        }}</span>
      </label>

      <p v-if="error" data-test="staff-form-error" class="text-sm text-red-600 sm:col-span-2">
        {{ error }}
      </p>

      <div class="flex justify-end gap-2 sm:col-span-2">
        <router-link :to="{ name: 'staff.index' }" class="rounded-lg border px-4 py-2 text-sm"
          >ยกเลิก</router-link
        >
        <button
          type="submit"
          data-test="staff-save"
          :disabled="saving"
          class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
        </button>
      </div>
    </form>
  </div>
</template>
