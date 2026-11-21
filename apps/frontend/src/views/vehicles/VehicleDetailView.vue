<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import vehiclesService from '@/services/vehicles'
import {
  apiErrorText,
  isFeatureDisabled,
  ERROR_MESSAGES,
  VEHICLE_TYPE_LABELS,
  VEHICLE_STATUS_LABELS,
  VEHICLE_RELATION_LABELS,
} from '@/constants/labels'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const vehicle = ref(null)
const loading = ref(false)
const errorMessage = ref('')

const canManage = computed(() => authStore.can('vehicle.manage'))
const currentLinks = computed(() => (vehicle.value?.links || []).filter((l) => l.is_current))
const pastLinks = computed(() => (vehicle.value?.links || []).filter((l) => !l.is_current))

// Edit
const editing = ref(false)
const saving = ref(false)
const editError = ref('')
const editForm = reactive({
  vehicle_type: 'CAR',
  brand: '',
  model: '',
  color: '',
  status: 'ACTIVE',
  notes: '',
})

// Link a person
const linking = ref(false)
const linkError = ref('')
const peopleQuery = ref('')
const peopleResults = ref([])
const searching = ref(false)
const linkForm = reactive({ person: null, relation: 'OWNER', reason: '' })

async function fetchVehicle() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await vehiclesService.getVehicle(route.params.uuid)
    vehicle.value = res.data
  } catch (err) {
    errorMessage.value = isFeatureDisabled(err)
      ? ERROR_MESSAGES.FEATURE_DISABLED
      : apiErrorText(err, 'โหลดข้อมูลรถไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function startEdit() {
  Object.assign(editForm, {
    vehicle_type: vehicle.value.vehicle_type,
    brand: vehicle.value.brand || '',
    model: vehicle.value.model || '',
    color: vehicle.value.color || '',
    status: vehicle.value.status,
    notes: vehicle.value.notes || '',
  })
  editError.value = ''
  editing.value = true
}

async function saveEdit() {
  saving.value = true
  editError.value = ''
  try {
    const payload = Object.fromEntries(
      Object.entries(editForm).map(([k, v]) => [
        k,
        typeof v === 'string' && v.trim() === '' ? null : v,
      ]),
    )
    await vehiclesService.updateVehicle(vehicle.value.uuid, payload)
    editing.value = false
    await fetchVehicle()
  } catch (err) {
    editError.value = apiErrorText(err, 'บันทึกข้อมูลรถไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

async function searchPeople() {
  const q = peopleQuery.value.trim()
  if (q.length < 2) {
    peopleResults.value = []
    return
  }
  searching.value = true
  try {
    peopleResults.value = await vehiclesService.searchPeople(q, {
      students: authStore.can('student.view'),
      staff: authStore.can('staff.view'),
    })
  } finally {
    searching.value = false
  }
}

function pickPerson(person) {
  linkForm.person = person
  peopleResults.value = []
  peopleQuery.value = ''
}

async function linkPerson() {
  linkError.value = ''
  if (!linkForm.person) {
    linkError.value = 'กรุณาเลือกบุคคล'
    return
  }
  linking.value = true
  try {
    await vehiclesService.linkPerson(vehicle.value.uuid, {
      person_uuid: linkForm.person.uuid,
      relation: linkForm.relation,
      reason: linkForm.reason.trim() || null,
    })
    Object.assign(linkForm, { person: null, relation: 'OWNER', reason: '' })
    await fetchVehicle()
  } catch (err) {
    linkError.value = apiErrorText(err, 'ผูกบุคคลกับรถไม่สำเร็จ')
  } finally {
    linking.value = false
  }
}

async function endLink(link) {
  const reason = window.prompt('เหตุผลที่ยกเลิกการผูก (ไม่บังคับ)', '')
  if (reason === null) return
  linkError.value = ''
  try {
    await vehiclesService.endLink(vehicle.value.uuid, link.uuid, { reason: reason.trim() || null })
    await fetchVehicle()
  } catch (err) {
    linkError.value = apiErrorText(err, 'ยกเลิกการผูกไม่สำเร็จ')
  }
}

function formatDate(value) {
  return value ? new Date(value).toLocaleDateString('th-TH') : '-'
}

onMounted(fetchVehicle)
</script>

<template>
  <div class="vehicle-detail-view max-w-5xl mx-auto p-6 space-y-6">
    <button
      @click="router.push({ name: 'vehicles.index' })"
      class="text-sm text-blue-700 hover:underline"
    >
      ← กลับไปรายการยานพาหนะ
    </button>

    <div v-if="loading" class="text-center py-12 text-gray-500">กำลังโหลด...</div>
    <div
      v-else-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <template v-else-if="vehicle">
      <div class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4">
        <div class="flex flex-wrap justify-between items-start gap-4">
          <div>
            <h1 class="text-3xl font-bold text-gray-900" data-test="plate">
              {{ vehicle.plate_display }}
            </h1>
            <p class="text-gray-600">{{ vehicle.plate_province_name || vehicle.plate_province }}</p>
          </div>
          <span
            class="px-3 py-1 rounded-full text-sm font-semibold"
            :class="{
              'bg-red-100 text-red-800': vehicle.status === 'BLOCKED',
              'bg-green-100 text-green-800': vehicle.status === 'ACTIVE',
              'bg-gray-100 text-gray-700': vehicle.status === 'INACTIVE',
            }"
          >
            {{ VEHICLE_STATUS_LABELS[vehicle.status] || vehicle.status }}
          </span>
        </div>

        <dl v-if="!editing" class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
          <div>
            <dt class="text-gray-500">ประเภท</dt>
            <dd class="text-gray-900">
              {{ VEHICLE_TYPE_LABELS[vehicle.vehicle_type] || vehicle.vehicle_type }}
            </dd>
          </div>
          <div>
            <dt class="text-gray-500">ยี่ห้อ</dt>
            <dd class="text-gray-900">{{ vehicle.brand || '-' }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">รุ่น</dt>
            <dd class="text-gray-900">{{ vehicle.model || '-' }}</dd>
          </div>
          <div>
            <dt class="text-gray-500">สี</dt>
            <dd class="text-gray-900">{{ vehicle.color || '-' }}</dd>
          </div>
          <div class="col-span-2 md:col-span-4">
            <dt class="text-gray-500">หมายเหตุ</dt>
            <dd class="text-gray-900">{{ vehicle.notes || '-' }}</dd>
          </div>
        </dl>

        <form
          v-else
          @submit.prevent="saveEdit"
          class="grid grid-cols-1 md:grid-cols-3 gap-4"
          data-test="edit-form"
        >
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ประเภทรถ</label>
            <select
              v-model="editForm.vehicle_type"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-for="(label, code) in VEHICLE_TYPE_LABELS" :key="code" :value="code">
                {{ label }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">สถานะ</label>
            <select
              v-model="editForm.status"
              id="vehicle-status"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            >
              <option v-for="(label, code) in VEHICLE_STATUS_LABELS" :key="code" :value="code">
                {{ label }}
              </option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ยี่ห้อ</label>
            <input v-model="editForm.brand" class="w-full px-3 py-2 border rounded-lg text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">รุ่น</label>
            <input v-model="editForm.model" class="w-full px-3 py-2 border rounded-lg text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">สี</label>
            <input v-model="editForm.color" class="w-full px-3 py-2 border rounded-lg text-sm" />
          </div>
          <div class="md:col-span-3">
            <label class="block text-sm font-medium text-gray-700 mb-1">หมายเหตุ</label>
            <input v-model="editForm.notes" class="w-full px-3 py-2 border rounded-lg text-sm" />
          </div>
          <p v-if="editError" class="md:col-span-3 text-sm text-red-600">{{ editError }}</p>
          <div class="md:col-span-3 flex gap-2">
            <button
              type="submit"
              :disabled="saving"
              class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg disabled:opacity-50"
            >
              {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
            </button>
            <button
              type="button"
              @click="editing = false"
              class="px-4 py-2 text-sm bg-gray-100 rounded-lg"
            >
              ยกเลิก
            </button>
          </div>
        </form>

        <button
          v-if="canManage && !editing"
          @click="startEdit"
          class="px-4 py-2 text-sm bg-gray-100 hover:bg-gray-200 rounded-lg"
        >
          แก้ไขข้อมูลรถ
        </button>
      </div>

      <div class="bg-surface rounded-xl border border-gray-200 p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900">บุคคลที่ผูกกับรถ</h2>

        <p v-if="currentLinks.length === 0" class="text-sm text-gray-500">
          ยังไม่มีบุคคลที่ผูกกับรถคันนี้
        </p>
        <ul v-else class="divide-y divide-gray-100" data-test="current-links">
          <li
            v-for="link in currentLinks"
            :key="link.uuid"
            class="py-3 flex justify-between items-center gap-4"
          >
            <div>
              <div class="font-medium text-gray-900">{{ link.person?.display_name || '-' }}</div>
              <div class="text-xs text-gray-500">
                {{ VEHICLE_RELATION_LABELS[link.relation] || link.relation }} · ตั้งแต่
                {{ formatDate(link.effective_from) }}
                <span v-if="link.person?.person_code"> · {{ link.person.person_code }}</span>
              </div>
            </div>
            <button
              v-if="canManage"
              @click="endLink(link)"
              class="px-2.5 py-1 bg-red-50 hover:bg-red-100 text-red-700 rounded text-xs"
            >
              ยกเลิกการผูก
            </button>
          </li>
        </ul>

        <div v-if="canManage" class="border-t pt-4 space-y-3">
          <h3 class="text-sm font-semibold text-gray-700">ผูกบุคคลเพิ่ม</h3>
          <div v-if="linkForm.person" class="flex items-center gap-2 text-sm">
            <span class="px-2 py-1 bg-blue-50 text-blue-800 rounded">
              {{ linkForm.person.name }} ({{ linkForm.person.group }} {{ linkForm.person.code }})
            </span>
            <button @click="linkForm.person = null" class="text-xs text-gray-500 hover:underline">
              เปลี่ยน
            </button>
          </div>
          <div v-else class="relative">
            <input
              v-model="peopleQuery"
              @keyup.enter="searchPeople"
              id="people-search"
              placeholder="ค้นหานักเรียนหรือบุคลากร (ชื่อหรือรหัส) แล้วกด Enter"
              class="w-full px-3 py-2 border rounded-lg text-sm"
            />
            <p v-if="searching" class="text-xs text-gray-500 mt-1">กำลังค้นหา...</p>
            <ul
              v-if="peopleResults.length"
              class="mt-1 border rounded-lg divide-y bg-surface"
              data-test="people-results"
            >
              <li v-for="p in peopleResults" :key="p.uuid">
                <button
                  @click="pickPerson(p)"
                  class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50"
                >
                  {{ p.name }}
                  <span class="text-xs text-gray-500">({{ p.group }} {{ p.code }})</span>
                </button>
              </li>
            </ul>
          </div>
          <div class="flex flex-wrap gap-3 items-center">
            <select v-model="linkForm.relation" class="px-3 py-2 border rounded-lg text-sm">
              <option v-for="(label, code) in VEHICLE_RELATION_LABELS" :key="code" :value="code">
                {{ label }}
              </option>
            </select>
            <input
              v-model="linkForm.reason"
              placeholder="เหตุผล (ไม่บังคับ)"
              class="flex-1 min-w-[200px] px-3 py-2 border rounded-lg text-sm"
            />
            <button
              @click="linkPerson"
              :disabled="linking"
              class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
            >
              {{ linking ? 'กำลังบันทึก...' : 'ผูกกับรถ' }}
            </button>
          </div>
          <p v-if="linkError" class="text-sm text-red-600">{{ linkError }}</p>
        </div>

        <details v-if="pastLinks.length" class="text-sm">
          <summary class="cursor-pointer text-gray-600">
            ประวัติการผูกที่สิ้นสุดแล้ว ({{ pastLinks.length }})
          </summary>
          <ul class="mt-2 divide-y divide-gray-100">
            <li v-for="link in pastLinks" :key="link.uuid" class="py-2 text-gray-600">
              {{ link.person?.display_name || '-' }} ·
              {{ VEHICLE_RELATION_LABELS[link.relation] || link.relation }} ·
              {{ formatDate(link.effective_from) }} – {{ formatDate(link.effective_to) }}
            </li>
          </ul>
        </details>
      </div>
    </template>
  </div>
</template>
