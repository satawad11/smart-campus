<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { staffService } from '@/services/staff'
import { academicsService } from '@/services/academics'

const route = useRoute()
const router = useRouter()
const editing = computed(() => Boolean(route.params.uuid))
const departments = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const form = reactive({
  person_type: 'TEACHER',
  staff_code: '',
  position: '',
  department_uuid: '',
  phone: '',
  email: '',
  person: { first_name: '', last_name: '', display_name: '', person_code: '', gender: '' },
})

onMounted(async () => {
  try {
    departments.value = await academicsService.listDepartments()
  } catch {
    /* department is optional */
  }
  if (editing.value) {
    loading.value = true
    try {
      const staff = await staffService.get(route.params.uuid)
      Object.assign(form, {
        person_type: staff.person?.person_type || 'TEACHER',
        staff_code: staff.staff_code || '',
        position: staff.position || '',
        department_uuid: staff.department?.uuid || '',
        phone: staff.phone || '',
        email: staff.email || '',
      })
      Object.assign(form.person, {
        first_name: staff.person?.first_name || '',
        last_name: staff.person?.last_name || '',
        display_name: staff.person?.display_name || '',
        gender: staff.person?.gender || '',
      })
    } catch (err) {
      error.value = err.response?.data?.message || 'โหลดข้อมูลบุคลากรไม่สำเร็จ'
    } finally {
      loading.value = false
    }
  }
})

async function save() {
  saving.value = true
  error.value = ''
  const payload = {
    person: {
      first_name: form.person.first_name,
      last_name: form.person.last_name,
      display_name: form.person.display_name || null,
      gender: form.person.gender || null,
    },
    position: form.position || null,
    department_uuid: form.department_uuid || null,
    phone: form.phone || null,
    email: form.email || null,
  }
  try {
    const result = editing.value
      ? await staffService.update(route.params.uuid, payload)
      : await staffService.create({
          ...payload,
          person_type: form.person_type,
          staff_code: form.staff_code,
        })
    router.push(`/staff/${result.uuid}`)
  } catch (err) {
    error.value = err.response?.data?.message || 'บันทึกข้อมูลไม่สำเร็จ กรุณาตรวจสอบข้อมูลและสิทธิ์'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-3xl p-6">
    <RouterLink to="/staff" class="text-sm text-blue-700">← กลับรายการบุคลากร</RouterLink>
    <h1 class="mb-1 mt-4 text-2xl font-bold">
      {{ editing ? 'แก้ไขข้อมูลบุคลากร' : 'เพิ่มบุคลากร' }}
    </h1>
    <p class="mb-6 text-sm text-gray-500">
      ข้อมูลนี้เป็นทะเบียนบุคลากร ไม่ใช่บัญชีสำหรับเข้าสู่ระบบ
    </p>
    <p v-if="error" role="alert" class="mb-4 rounded-lg bg-red-50 p-3 text-red-700">{{ error }}</p>
    <form class="space-y-5 rounded-xl border bg-white p-6" @submit.prevent="save">
      <fieldset :disabled="loading || saving" class="space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
          <label v-if="!editing" class="text-sm"
            >ประเภทบุคลากร<select
              v-model="form.person_type"
              class="mt-1 w-full rounded-lg border p-2"
            >
              <option value="TEACHER">ครู</option>
              <option value="STAFF">บุคลากร</option>
            </select></label
          >
          <label class="text-sm"
            >รหัสบุคลากร
            <input
              v-model.trim="form.staff_code"
              required
              maxlength="255"
              :disabled="editing"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >ชื่อ
            <input
              v-model.trim="form.person.first_name"
              required
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >นามสกุล
            <input
              v-model.trim="form.person.last_name"
              required
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >ชื่อที่แสดง
            <input
              v-model.trim="form.person.display_name"
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label v-if="!editing" class="text-sm"
            >รหัสบุคคล (ถ้ามี)
            <input
              v-model.trim="form.person.person_code"
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >ตำแหน่ง
            <input
              v-model.trim="form.position"
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >แผนก/หน่วยงาน<select
              v-model="form.department_uuid"
              class="mt-1 w-full rounded-lg border p-2"
            >
              <option value="">ไม่ระบุ</option>
              <option
                v-for="department in departments"
                :key="department.uuid"
                :value="department.uuid"
              >
                {{ department.name }}
              </option>
            </select></label
          >
          <label class="text-sm"
            >โทรศัพท์
            <input
              v-model.trim="form.phone"
              maxlength="50"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >อีเมล
            <input
              v-model.trim="form.email"
              type="email"
              maxlength="255"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
          <label class="text-sm"
            >เพศ (ถ้ามี)
            <input
              v-model.trim="form.person.gender"
              maxlength="50"
              class="mt-1 w-full rounded-lg border p-2"
          /></label>
        </div>
        <p v-if="editing" class="text-xs text-gray-500">
          รหัสบุคลากร ประเภท และสถานะเป็นข้อมูลกำกับที่แก้ไขผ่านขั้นตอนเฉพาะ
        </p>
      </fieldset>
      <div class="flex justify-end gap-2">
        <RouterLink to="/staff" class="rounded-lg border px-4 py-2">ยกเลิก</RouterLink
        ><button
          :disabled="saving || loading"
          class="rounded-lg bg-blue-600 px-5 py-2 text-white disabled:opacity-50"
        >
          {{ saving ? 'กำลังบันทึก…' : 'บันทึกข้อมูล' }}
        </button>
      </div>
    </form>
  </main>
</template>
