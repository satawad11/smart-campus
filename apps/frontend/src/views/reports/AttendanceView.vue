<script setup>
import { ref, onMounted } from 'vue'
import attendanceService from '@/services/attendance'
import { apiErrorText } from '@/constants/labels'
import { statusLabel } from '@/constants/labels'

/**
 * บันทึกเวลาเข้า-ออก: first and last confirmed (ALLOW) recognition of each person
 * for one day, recorded automatically by the cameras. Nobody types times in.
 */
const TYPE_LABELS = {
  STUDENT: 'ผู้เรียน',
  TEACHER: 'ครู',
  STAFF: 'บุคลากร',
  PARENT: 'ผู้ปกครอง',
  VISITOR: 'ผู้มาติดต่อ',
  CONTRACTOR: 'ผู้รับเหมา',
}

function todayLocal() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

const filters = ref({ date: todayLocal(), person_type: '', keyword: '' })
const rows = ref([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await attendanceService.daily({
      date: filters.value.date,
      person_type: filters.value.person_type || undefined,
      keyword: filters.value.keyword || undefined,
    })
    rows.value = res.data || []
  } catch (err) {
    rows.value = []
    error.value = apiErrorText(err, 'โหลดข้อมูลเวลาเข้า-ออกไม่สำเร็จ')
  } finally {
    loading.value = false
  }
}

function time(iso) {
  if (!iso) return '-'
  return new Date(iso).toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })
}

function typeLabel(type) {
  return TYPE_LABELS[type] || type || '-'
}

function csvCell(value) {
  const text = String(value ?? '')
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

function downloadCsv() {
  const header = [
    'รหัส',
    'ชื่อ',
    'ประเภท',
    'สถานะ',
    'เวลาเข้า',
    'กล้องเข้า',
    'เวลาออก',
    'กล้องออก',
    'จำนวนครั้งที่พบ',
  ]
  const lines = rows.value.map((r) =>
    [
      r.person.code,
      r.person.name,
      typeLabel(r.person.person_type),
      statusLabel(r.person.status),
      time(r.time_in),
      r.time_in_camera,
      time(r.time_out),
      r.time_out_camera,
      r.sightings,
    ]
      .map(csvCell)
      .join(','),
  )
  // BOM so Excel opens Thai text correctly.
  const blob = new Blob(['﻿' + [header.join(','), ...lines].join('\n')], {
    type: 'text/csv;charset=utf-8',
  })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = `attendance-${filters.value.date}.csv`
  link.click()
  URL.revokeObjectURL(link.href)
}

onMounted(load)
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900">บันทึกเวลาเข้า-ออก</h1>
      <p class="text-sm text-gray-500">
        บันทึกอัตโนมัติจากกล้อง: เวลาเข้า = ครั้งแรกที่ระบบยืนยันใบหน้าในวันนั้น, เวลาออก =
        ครั้งสุดท้าย (นับเฉพาะเหตุการณ์ที่ผลเป็น ALLOW)
      </p>
    </div>

    <form
      class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-surface p-4"
      @submit.prevent="load"
    >
      <label class="text-sm text-gray-600">
        วันที่
        <input
          v-model="filters.date"
          data-test="attendance-date"
          type="date"
          class="mt-1 block rounded-lg border px-3 py-2 text-sm"
        />
      </label>
      <label class="text-sm text-gray-600">
        ประเภท
        <select
          v-model="filters.person_type"
          class="mt-1 block rounded-lg border px-3 py-2 text-sm"
        >
          <option value="">ทั้งหมด</option>
          <option v-for="(label, value) in TYPE_LABELS" :key="value" :value="value">
            {{ label }}
          </option>
        </select>
      </label>
      <label class="min-w-[200px] flex-1 text-sm text-gray-600">
        ค้นหา
        <input
          v-model="filters.keyword"
          type="text"
          placeholder="ชื่อ หรือ รหัส"
          class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm"
        />
      </label>
      <button
        type="submit"
        class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"
        :disabled="loading"
      >
        {{ loading ? 'กำลังโหลด...' : 'แสดง' }}
      </button>
      <button
        type="button"
        data-test="attendance-csv"
        class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        :disabled="rows.length === 0"
        @click="downloadCsv"
      >
        ดาวน์โหลด CSV
      </button>
    </form>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-surface">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
          <tr>
            <th class="px-4 py-3">รหัส</th>
            <th class="px-4 py-3">ชื่อ</th>
            <th class="px-4 py-3">ประเภท</th>
            <th class="px-4 py-3">สถานะ</th>
            <th class="px-4 py-3">เวลาเข้า</th>
            <th class="px-4 py-3">เวลาออก</th>
            <th class="px-4 py-3 text-right">พบ (ครั้ง)</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="row in rows" :key="row.person.uuid" data-test="attendance-row">
            <td class="px-4 py-3 font-mono text-xs">{{ row.person.code || '-' }}</td>
            <td class="px-4 py-3">
              <router-link
                :to="{ name: 'persons.timeline', params: { uuid: row.person.uuid } }"
                class="text-blue-700 hover:underline"
              >
                {{ row.person.name }}
              </router-link>
            </td>
            <td class="px-4 py-3">{{ typeLabel(row.person.person_type) }}</td>
            <td class="px-4 py-3">{{ statusLabel(row.person.status) }}</td>
            <td class="px-4 py-3">
              {{ time(row.time_in) }}
              <span class="block text-xs text-gray-400">{{ row.time_in_camera }}</span>
            </td>
            <td class="px-4 py-3">
              <template v-if="row.time_out">
                {{ time(row.time_out) }}
                <span class="block text-xs text-gray-400">{{ row.time_out_camera }}</span>
              </template>
              <span v-else class="text-xs text-amber-600">ยังไม่พบออก</span>
            </td>
            <td class="px-4 py-3 text-right">{{ row.sightings }}</td>
          </tr>
          <tr v-if="!loading && rows.length === 0">
            <td colspan="7" class="px-4 py-10 text-center text-gray-400">
              ไม่มีการบันทึกเวลาเข้า-ออกในวันที่เลือก
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
