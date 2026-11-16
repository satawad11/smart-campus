<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import locationsService from '@/services/locations'
import { apiErrorText, statusLabel } from '@/constants/labels'

/**
 * สถานที่และโซน: sites (buildings / campuses) and their zones (gates, areas).
 * Cameras, access policies and reports are attached to these. Previously only
 * `php artisan locations:provision` could create them.
 */
const authStore = useAuthStore()
const canManage = computed(() => authStore.can('system.manage'))

const ZONE_TYPES = {
  GATE: 'ประตู / ทางเข้า-ออก',
  BUILDING: 'อาคาร',
  AREA: 'พื้นที่',
  OTHER: 'อื่นๆ',
}

const sites = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await locationsService.listSites()
    sites.value = res.data || []
  } catch (err) {
    error.value = apiErrorText(err, 'โหลดสถานที่ไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

// ---- Dialog shared by site and zone add/edit ----
const dialog = ref(null) // { kind: 'site'|'zone', mode: 'create'|'edit', site, target }
const form = ref({})
const formError = ref('')
const fieldErrors = ref({})
const saving = ref(false)

function openSite(site = null) {
  dialog.value = { kind: 'site', mode: site ? 'edit' : 'create', target: site }
  form.value = site
    ? {
        code: site.code,
        name: site.name,
        address: site.address || '',
        timezone: site.timezone,
        status: site.status,
      }
    : { code: '', name: '', address: '', timezone: 'Asia/Bangkok', status: 'ACTIVE' }
  formError.value = ''
  fieldErrors.value = {}
}

function openZone(site, zone = null) {
  dialog.value = { kind: 'zone', mode: zone ? 'edit' : 'create', site, target: zone }
  form.value = zone
    ? { code: zone.code, name: zone.name, zone_type: zone.zone_type || '', status: zone.status }
    : { code: '', name: '', zone_type: 'GATE', status: 'ACTIVE' }
  formError.value = ''
  fieldErrors.value = {}
}

async function save() {
  const d = dialog.value
  const f = form.value
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    if (d.kind === 'site' && d.mode === 'create') {
      await locationsService.createSite({
        code: f.code,
        name: f.name,
        address: f.address || null,
        timezone: f.timezone,
      })
    } else if (d.kind === 'site') {
      await locationsService.updateSite(d.target.uuid, {
        name: f.name,
        address: f.address || null,
        timezone: f.timezone,
        status: f.status,
      })
    } else if (d.mode === 'create') {
      await locationsService.createZone(d.site.uuid, {
        code: f.code,
        name: f.name,
        zone_type: f.zone_type || null,
      })
    } else {
      await locationsService.updateZone(d.target.uuid, {
        name: f.name,
        zone_type: f.zone_type || null,
        status: f.status,
      })
    }
    dialog.value = null
    await load()
  } catch (err) {
    fieldErrors.value = err?.fields || {}
    formError.value = apiErrorText(err, 'บันทึกไม่สำเร็จ')
  } finally {
    saving.value = false
  }
}

function fieldError(name) {
  const v = fieldErrors.value[name]
  return Array.isArray(v) ? v[0] : v
}

function badge(status) {
  return status === 'ACTIVE' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-6 p-6">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b pb-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">สถานที่และโซน</h1>
        <p class="text-sm text-gray-500">
          อาคาร/วิทยาเขต และโซนภายใน (เช่น ประตูหน้า) ที่ใช้ผูกกับกล้องและรายงาน
        </p>
      </div>
      <button
        v-if="canManage"
        type="button"
        data-test="site-add"
        class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
        @click="openSite()"
      >
        + เพิ่มสถานที่
      </button>
    </div>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <p v-if="!loading && sites.length === 0 && !error" class="py-10 text-center text-gray-400">
      ยังไม่มีสถานที่ — กด "เพิ่มสถานที่" เพื่อเริ่ม
    </p>

    <div
      v-for="site in sites"
      :key="site.uuid"
      data-test="site-card"
      class="space-y-3 rounded-xl border border-gray-200 bg-surface p-5"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-gray-900">
            {{ site.name }}
            <span class="ml-1 font-mono text-xs text-gray-400">{{ site.code }}</span>
          </h2>
          <p class="text-xs text-gray-500">
            {{ site.address || 'ไม่ระบุที่อยู่' }} · เขตเวลา {{ site.timezone }}
          </p>
        </div>
        <div class="flex items-center gap-3">
          <span
            class="rounded-full px-2 py-0.5 text-xs font-semibold"
            :class="badge(site.status)"
            >{{ statusLabel(site.status) }}</span
          >
          <button
            v-if="canManage"
            type="button"
            data-test="site-edit"
            class="text-sm text-blue-600 hover:text-blue-800"
            @click="openSite(site)"
          >
            แก้ไข
          </button>
        </div>
      </div>

      <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="text-left text-xs text-gray-500">
          <tr>
            <th class="py-2">โซน</th>
            <th class="py-2">ประเภท</th>
            <th class="py-2 text-right">กล้อง</th>
            <th class="py-2">สถานะ</th>
            <th v-if="canManage" class="py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="zone in site.zones" :key="zone.uuid" data-test="zone-row">
            <td class="py-2">
              {{ zone.name }} <span class="font-mono text-xs text-gray-400">{{ zone.code }}</span>
            </td>
            <td class="py-2">{{ ZONE_TYPES[zone.zone_type] || zone.zone_type || '-' }}</td>
            <td class="py-2 text-right">{{ zone.cameras_count }}</td>
            <td class="py-2">
              <span
                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                :class="badge(zone.status)"
                >{{ statusLabel(zone.status) }}</span
              >
            </td>
            <td v-if="canManage" class="py-2 text-right">
              <button
                type="button"
                data-test="zone-edit"
                class="text-sm text-blue-600 hover:text-blue-800"
                @click="openZone(site, zone)"
              >
                แก้ไข
              </button>
            </td>
          </tr>
          <tr v-if="site.zones.length === 0">
            <td colspan="5" class="py-3 text-center text-xs text-gray-400">ยังไม่มีโซน</td>
          </tr>
        </tbody>
      </table>

      <button
        v-if="canManage"
        type="button"
        data-test="zone-add"
        class="text-sm text-blue-600 hover:text-blue-800"
        @click="openZone(site)"
      >
        + เพิ่มโซน
      </button>
    </div>

    <div
      v-if="dialog"
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4"
      @click.self="dialog = null"
    >
      <form
        data-test="location-form"
        class="w-full max-w-md space-y-4 rounded-xl bg-surface p-6 shadow-xl"
        @submit.prevent="save"
      >
        <h2 class="text-lg font-semibold text-gray-900">
          {{ dialog.mode === 'create' ? 'เพิ่ม' : 'แก้ไข'
          }}{{ dialog.kind === 'site' ? 'สถานที่' : 'โซน' }}
          <span v-if="dialog.kind === 'zone'" class="text-sm font-normal text-gray-500"
            >— {{ dialog.site.name }}</span
          >
        </h2>

        <label class="block text-sm text-gray-700">
          รหัส (ตัวอักษรอังกฤษ ตัวเลข - _)
          <input
            v-model="form.code"
            data-test="loc-code"
            type="text"
            required
            :disabled="dialog.mode === 'edit'"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm disabled:bg-gray-100"
          />
          <span v-if="fieldError('code')" class="block text-xs text-red-600">{{
            fieldError('code')
          }}</span>
        </label>

        <label class="block text-sm text-gray-700">
          ชื่อ
          <input
            v-model="form.name"
            data-test="loc-name"
            type="text"
            required
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          />
        </label>

        <template v-if="dialog.kind === 'site'">
          <label class="block text-sm text-gray-700">
            ที่อยู่
            <textarea
              v-model="form.address"
              rows="2"
              class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
            />
          </label>
          <label class="block text-sm text-gray-700">
            เขตเวลา
            <input
              v-model="form.timezone"
              type="text"
              class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
            />
            <span v-if="fieldError('timezone')" class="block text-xs text-red-600">{{
              fieldError('timezone')
            }}</span>
          </label>
        </template>

        <label v-else class="block text-sm text-gray-700">
          ประเภทโซน
          <select
            v-model="form.zone_type"
            data-test="loc-zone-type"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          >
            <option v-for="(label, value) in ZONE_TYPES" :key="value" :value="value">
              {{ label }}
            </option>
          </select>
        </label>

        <label v-if="dialog.mode === 'edit'" class="block text-sm text-gray-700">
          สถานะ
          <select
            v-model="form.status"
            data-test="loc-status"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
          >
            <option value="ACTIVE">ใช้งาน</option>
            <option value="INACTIVE">ปิดใช้งาน</option>
          </select>
        </label>

        <p v-if="formError" class="text-sm text-red-600">{{ formError }}</p>

        <div class="flex justify-end gap-2">
          <button type="button" class="rounded-lg border px-4 py-2 text-sm" @click="dialog = null">
            ยกเลิก
          </button>
          <button
            type="submit"
            data-test="loc-save"
            :disabled="saving"
            class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
