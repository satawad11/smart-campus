<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import camerasService from '@/services/cameras'
import { visitorsService } from '@/services/visitors'
import { apiErrorText } from '@/constants/labels'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const enabled = ref(false)
const flagsLoaded = ref(false)
const visitors = ref([])
const visits = ref([])
const sites = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const success = ref('')
const personForm = ref({
  first_name: '',
  last_name: '',
  person_type: 'VISITOR',
  organization: '',
  phone: '',
})
const visitForm = ref({
  visitor_person_uuid: '',
  site_uuid: '',
  purpose: '',
  valid_from: '',
  valid_until: '',
})
const canManage = computed(() => auth.can('visitor.manage') && auth.can('visit.manage'))
function bangkokDate() {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

const todayRange = (() => {
  const date = bangkokDate()
  return {
    from: new Date(`${date}T00:00:00+07:00`).toISOString(),
    to: new Date(`${date}T23:59:59.999+07:00`).toISOString(),
  }
})()

function bangkokInputToIso(value) {
  return value ? new Date(`${value}:00+07:00`).toISOString() : value
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const [people, today, cameraResponse] = await Promise.all([
      visitorsService.list({ per_page: 100 }),
      visitorsService.visits({ ...todayRange, per_page: 100 }),
      auth.can('camera.view')
        ? camerasService.listCameras({ per_page: 100 })
        : Promise.resolve({ data: [] }),
    ])
    visitors.value = people.data || []
    visits.value = today.data || []
    const byUuid = new Map()
    for (const camera of cameraResponse.data || [])
      if (camera.site) byUuid.set(camera.site.uuid, camera.site)
    sites.value = [...byUuid.values()]
    if (!visitForm.value.site_uuid && sites.value.length)
      visitForm.value.site_uuid = sites.value[0].uuid
  } catch (e) {
    error.value = apiErrorText(e, 'โหลดข้อมูลผู้มาติดต่อไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

async function checkFeature() {
  try {
    const response = await api.get('/features')
    const features = response.data?.data || {}
    enabled.value =
      features.visitor === true ||
      features.visitor === 1 ||
      features.visitor === 'true' ||
      features.FEATURE_VISITOR_ENABLED === true
  } catch {
    enabled.value = false
  } finally {
    flagsLoaded.value = true
  }
  if (enabled.value) await load()
}

async function createVisitor() {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    const created = await visitorsService.create({
      ...personForm.value,
      first_name: personForm.value.first_name.trim(),
      last_name: personForm.value.last_name.trim(),
    })
    visitForm.value.visitor_person_uuid = created.person.uuid
    personForm.value = {
      first_name: '',
      last_name: '',
      person_type: 'VISITOR',
      organization: '',
      phone: '',
    }
    success.value = 'บันทึกข้อมูลผู้มาติดต่อแล้ว'
    await load()
  } catch (e) {
    error.value = apiErrorText(e, 'บันทึกข้อมูลผู้มาติดต่อไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

async function scheduleVisit() {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await visitorsService.createVisit({
      ...visitForm.value,
      valid_from: bangkokInputToIso(visitForm.value.valid_from),
      valid_until: bangkokInputToIso(visitForm.value.valid_until),
    })
    success.value = 'สร้างรายการนัดหมายแล้ว'
    visitForm.value.purpose = ''
    await load()
  } catch (e) {
    error.value = apiErrorText(e, 'สร้างรายการนัดหมายไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

async function transition(visit, action) {
  busy.value = true
  error.value = ''
  success.value = ''
  try {
    await visitorsService[action](visit.uuid)
    success.value =
      action === 'checkIn'
        ? 'บันทึกเวลาเข้าพื้นที่แล้ว'
        : action === 'checkOut'
          ? 'บันทึกเวลาออกแล้ว'
          : 'ยกเลิกนัดหมายแล้ว'
    await load()
  } catch (e) {
    error.value = apiErrorText(e, 'ปรับปรุงสถานะการเข้าพื้นที่ไม่สำเร็จ')
  } finally {
    busy.value = false
  }
}

function dateTime(value) {
  return value ? new Date(value).toLocaleString('th-TH', { timeZone: 'Asia/Bangkok' }) : '-'
}
onMounted(checkFeature)
</script>

<template>
  <main class="mx-auto max-w-7xl space-y-6 p-6">
    <header>
      <h1 class="text-2xl font-bold text-gray-900">ผู้มาติดต่อและรายการเข้าออก</h1>
      <p class="mt-1 text-sm text-gray-500">
        จัดการผู้มาติดต่อ นัดหมาย และบันทึกการเข้าออกที่จุดรักษาการณ์
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
    <div
      v-if="!flagsLoaded"
      class="rounded-lg border bg-surface p-8 text-center text-sm text-gray-500"
    >
      กำลังตรวจสอบการเปิดใช้ฟังก์ชัน…
    </div>
    <div
      v-else-if="!enabled"
      role="status"
      class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-900"
    >
      <h2 class="font-semibold">ฟังก์ชันผู้มาติดต่อยังปิดอยู่</h2>
      <p class="mt-1 text-sm">
        ระบบยังคงป้องกัน API ฝั่งเซิร์ฟเวอร์ไว้ กรุณาให้ผู้ดูแลเปิด feature flag `visitor`
        หลังอนุมัติใช้งาน
      </p>
    </div>
    <template v-else>
      <div v-if="canManage" class="grid gap-5 xl:grid-cols-2">
        <form class="space-y-3 rounded-xl border bg-surface p-5" @submit.prevent="createVisitor">
          <h2 class="font-semibold">ลงทะเบียนผู้มาติดต่อ</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm"
              >ชื่อ<input
                v-model="personForm.first_name"
                required
                maxlength="255"
                class="mt-1 w-full rounded border px-3 py-2" /></label
            ><label class="text-sm"
              >นามสกุล<input
                v-model="personForm.last_name"
                required
                maxlength="255"
                class="mt-1 w-full rounded border px-3 py-2"
            /></label>
          </div>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm"
              >ประเภท<select
                v-model="personForm.person_type"
                class="mt-1 w-full rounded border px-3 py-2"
              >
                <option value="VISITOR">ผู้มาติดต่อ</option>
                <option value="CONTRACTOR">ผู้รับเหมา</option>
              </select></label
            ><label class="text-sm"
              >หน่วยงาน<input
                v-model="personForm.organization"
                maxlength="255"
                class="mt-1 w-full rounded border px-3 py-2"
            /></label>
          </div>
          <label class="block text-sm"
            >เบอร์โทรศัพท์<input
              v-model="personForm.phone"
              maxlength="50"
              class="mt-1 w-full rounded border px-3 py-2" /></label
          ><button
            :disabled="busy"
            class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
          >
            บันทึกผู้มาติดต่อ
          </button>
        </form>
        <form class="space-y-3 rounded-xl border bg-surface p-5" @submit.prevent="scheduleVisit">
          <h2 class="font-semibold">สร้างนัดหมาย</h2>
          <label class="block text-sm"
            >ผู้มาติดต่อ<select
              v-model="visitForm.visitor_person_uuid"
              required
              class="mt-1 w-full rounded border px-3 py-2"
            >
              <option value="">เลือกผู้มาติดต่อ</option>
              <option v-for="v in visitors" :key="v.person.uuid" :value="v.person.uuid">
                {{ v.person.name }} · {{ v.person.person_type }}
              </option>
            </select></label
          ><label class="block text-sm"
            >สถานที่<select
              v-model="visitForm.site_uuid"
              required
              class="mt-1 w-full rounded border px-3 py-2"
            >
              <option value="">เลือกสถานที่</option>
              <option v-for="site in sites" :key="site.uuid" :value="site.uuid">
                {{ site.name }}
              </option>
            </select></label
          ><label class="block text-sm"
            >วัตถุประสงค์<input
              v-model="visitForm.purpose"
              required
              maxlength="2000"
              class="mt-1 w-full rounded border px-3 py-2"
          /></label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm"
              >เริ่ม<input
                v-model="visitForm.valid_from"
                type="datetime-local"
                required
                class="mt-1 w-full rounded border px-3 py-2" /></label
            ><label class="text-sm"
              >สิ้นสุด<input
                v-model="visitForm.valid_until"
                type="datetime-local"
                required
                class="mt-1 w-full rounded border px-3 py-2"
            /></label>
          </div>
          <p v-if="!sites.length" class="text-xs text-amber-700">
            ไม่พบสถานที่จากกล้องที่คุณมีสิทธิ์ดู กรุณาตรวจสิทธิ์กล้อง/สถานที่ก่อนสร้างนัดหมาย
          </p>
          <button
            :disabled="busy || !sites.length"
            class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
          >
            สร้างนัดหมาย
          </button>
        </form>
      </div>
      <section class="overflow-hidden rounded-xl border bg-surface">
        <div class="flex items-center justify-between border-b p-4">
          <div>
            <h2 class="font-semibold">รายการเข้าพื้นที่วันนี้</h2>
            <p class="text-xs text-gray-500">แสดงผลตามสิทธิ์และขอบเขตสถานที่ของบัญชีผู้ใช้</p>
          </div>
          <button class="rounded border px-3 py-2 text-sm" :disabled="loading" @click="load">
            {{ loading ? 'กำลังโหลด…' : 'รีเฟรช' }}
          </button>
        </div>
        <div v-if="visits.length" class="divide-y">
          <article
            v-for="visit in visits"
            :key="visit.uuid"
            class="flex flex-wrap items-center justify-between gap-4 p-4"
          >
            <div class="min-w-[220px] flex-1">
              <p class="font-medium">
                {{ visit.visitor?.name || 'ไม่ทราบชื่อ' }}
                <span class="text-xs text-gray-500">{{ visit.visitor?.person_type }}</span>
              </p>
              <p class="mt-1 text-sm text-gray-600">
                {{ visit.purpose }} · {{ visit.site?.name || '-' }}
              </p>
              <p class="mt-1 text-xs text-gray-500">
                {{ dateTime(visit.valid_from) }} – {{ dateTime(visit.valid_until) }}
                <span v-if="visit.checked_in_at"> · เข้า {{ dateTime(visit.checked_in_at) }}</span
                ><span v-if="visit.checked_out_at">
                  · ออก {{ dateTime(visit.checked_out_at) }}</span
                >
              </p>
            </div>
            <div class="flex items-center gap-2">
              <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold">{{
                visit.status
              }}</span
              ><button
                v-if="canManage && visit.status === 'SCHEDULED'"
                :disabled="busy"
                class="rounded bg-green-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                @click="transition(visit, 'checkIn')"
              >
                เช็กอิน</button
              ><button
                v-if="canManage && visit.status === 'CHECKED_IN'"
                :disabled="busy"
                class="rounded bg-blue-600 px-3 py-2 text-xs text-white disabled:opacity-50"
                @click="transition(visit, 'checkOut')"
              >
                เช็กเอาต์</button
              ><button
                v-if="canManage && visit.status === 'SCHEDULED'"
                :disabled="busy"
                class="rounded border border-red-200 px-3 py-2 text-xs text-red-700 disabled:opacity-50"
                @click="transition(visit, 'cancel')"
              >
                ยกเลิก</button
              ><router-link
                v-if="visit.visitor?.uuid"
                :to="{ name: 'faces.enroll', query: { person: visit.visitor.uuid } }"
                class="text-xs text-blue-700"
                >ลงทะเบียนใบหน้า</router-link
              >
            </div>
          </article>
        </div>
        <p v-else class="p-8 text-center text-sm text-gray-500">วันนี้ยังไม่มีรายการเข้าพื้นที่</p>
      </section>
    </template>
  </main>
</template>
