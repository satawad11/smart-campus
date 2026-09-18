<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { rolesService } from '@/services/roles'
import StatusBadge from '@/components/common/StatusBadge.vue'
import RoleTypeBadge from '@/components/roles/RoleTypeBadge.vue'

const route = useRoute()
const authStore = useAuthStore()

const role = ref(null)
const catalog = ref([])
const initialCodes = ref([])
const selectedCodes = ref(new Set())
const initialLoading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const isSuperAdminRole = computed(() => role.value?.code === 'SUPER_ADMIN')
const isReadOnly = computed(() => !authStore.can('role.manage') || isSuperAdminRole.value)

const moduleLabels = {
  dashboard: 'แดชบอร์ดและการแสดงผลรวม (Dashboard)',
  student: 'ข้อมูลนักเรียนและบุคคล (Student & Person)',
  academic: 'ข้อมูลหลักสูตรและวิชาการ (Academic Master Data)',
  face: 'การจดจำใบหน้าและชีวมิติ (Face & Biometrics)',
  camera: 'กล้องวงจรปิดและสตรีม (Camera & Streams)',
  ai_node: 'โหนดประมวลผล AI (AI Nodes)',
  monitor: 'การเฝ้าระวังแบบสด (Live Monitoring)',
  event: 'เหตุการณ์ความปลอดภัย (Security Events)',
  policy: 'นโยบายและกฎความปลอดภัย (Security Policies)',
  report: 'รายงานและการส่งออกข้อมูล (Reports & Export)',
  user: 'การจัดการผู้ใช้งาน (User Management)',
  role: 'การจัดการบทบาทและสิทธิ์ (Role & Permission Management)',
  audit: 'บันทึกการตรวจสอบความปลอดภัย (Security Audit Logs)',
  system: 'การตั้งค่าและระบบหลัก (System & Infrastructure)',
}

// Group catalog permissions by module
const groupedPermissions = computed(() => {
  const groups = {}
  for (const perm of catalog.value) {
    const mod = perm.module || 'other'
    if (!groups[mod]) {
      groups[mod] = []
    }
    groups[mod].push(perm)
  }
  return groups
})

// Dirty State
const isDirty = computed(() => {
  if (isSuperAdminRole.value) return false
  const currentArr = Array.from(selectedCodes.value).sort()
  const initArr = [...initialCodes.value].sort()
  if (currentArr.length !== initArr.length) return true
  for (let i = 0; i < currentArr.length; i++) {
    if (currentArr[i] !== initArr[i]) return true
  }
  return false
})

const selectedCount = computed(() => selectedCodes.value.size)

const errorTranslations = {
  SUPER_ADMIN_PERMISSIONS_REQUIRED: 'บทบาท SUPER_ADMIN ต้องได้รับสิทธิ์การใช้งานทั้งหมดของระบบ',
  SYSTEM_ROLE_PROTECTED: 'บทบาทระบบนี้ได้รับการคุ้มครอง',
  FORBIDDEN: 'คุณไม่มีสิทธิ์ในการแก้ไขตารางสิทธิ์ของบทบาทนี้',
  ROLE_NOT_FOUND: 'ไม่พบข้อมูลบทบาท',
}

async function loadData() {
  initialLoading.value = true
  errorMessage.value = ''

  try {
    const uuid = route.params.uuid
    const [roleData, catalogData] = await Promise.all([
      rolesService.get(uuid),
      rolesService.permissions(),
    ])

    role.value = roleData
    catalog.value = catalogData || []

    const currentPerms = roleData.permissions || []
    initialCodes.value = [...currentPerms]

    if (roleData.code === 'SUPER_ADMIN') {
      // Super admin must have all permissions from the catalog
      const allCodes = catalog.value.map((p) => p.code)
      selectedCodes.value = new Set(allCodes)
    } else {
      selectedCodes.value = new Set(currentPerms)
    }
  } catch (err) {
    errorMessage.value = err.message || 'ไม่สามารถโหลดข้อมูลตารางสิทธิ์ได้'
  } finally {
    initialLoading.value = false
  }
}

function togglePermission(code) {
  if (isReadOnly.value) return
  if (selectedCodes.value.has(code)) {
    selectedCodes.value.delete(code)
  } else {
    selectedCodes.value.add(code)
  }
  // Trigger Vue reactivity for Set
  selectedCodes.value = new Set(selectedCodes.value)
}

function selectAllInModule(moduleName) {
  if (isReadOnly.value) return
  const perms = groupedPermissions.value[moduleName] || []
  for (const p of perms) {
    selectedCodes.value.add(p.code)
  }
  selectedCodes.value = new Set(selectedCodes.value)
}

function clearAllInModule(moduleName) {
  if (isReadOnly.value) return
  const perms = groupedPermissions.value[moduleName] || []
  for (const p of perms) {
    selectedCodes.value.delete(p.code)
  }
  selectedCodes.value = new Set(selectedCodes.value)
}

function resetChanges() {
  selectedCodes.value = new Set(initialCodes.value)
  errorMessage.value = ''
}

async function handleSave() {
  if (isReadOnly.value) return

  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const payloadCodes = Array.from(selectedCodes.value)
    const updated = await rolesService.updatePermissions(route.params.uuid, payloadCodes)

    role.value = updated
    initialCodes.value = [...(updated.permissions || payloadCodes)]
    selectedCodes.value = new Set(initialCodes.value)

    successMessage.value = `บันทึกตารางสิทธิ์สำหรับบทบาท ${updated.name} สำเร็จ (${payloadCodes.length} สิทธิ์)`
  } catch (err) {
    errorMessage.value =
      errorTranslations[err.code] || err.message || 'เกิดข้อผิดพลาดในการบันทึกตารางสิทธิ์'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (route.query.created === 'true') {
    successMessage.value = 'สร้างบทบาทใหม่สำเร็จ กรุณากำหนดสิทธิ์การใช้งานด้านล่าง'
  }
  loadData()
})
</script>

<template>
  <div class="max-w-6xl mx-auto space-y-6">
    <!-- Breadcrumb & Header -->
    <div class="sm:flex sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center space-x-2 text-sm text-gray-500 mb-1">
          <router-link to="/roles" class="hover:text-blue-600"> จัดการบทบาท </router-link>
          <span>/</span>
          <router-link :to="`/roles/${route.params.uuid}`" class="hover:text-blue-600">
            {{ role?.name || route.params.uuid }}
          </router-link>
          <span>/</span>
          <span class="text-gray-900 font-medium">ตารางสิทธิ์</span>
        </div>
        <div class="flex items-center gap-3">
          <h1 class="text-2xl font-bold text-gray-900">ตารางสิทธิ์: {{ role?.name }}</h1>
          <RoleTypeBadge v-if="role" :system-role="role.system_role" />
          <StatusBadge v-if="role?.status" :status="role.status" />
        </div>
      </div>

      <!-- Action Buttons Bar -->
      <div v-if="role && !isReadOnly" class="mt-4 sm:mt-0 flex items-center gap-3">
        <button
          type="button"
          :disabled="!isDirty || saving"
          @click="resetChanges"
          class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          รีเซ็ตการแก้ไข
        </button>

        <button
          type="button"
          :disabled="!isDirty || saving"
          @click="handleSave"
          class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition"
        >
          <svg
            v-if="saving"
            class="mr-2 h-4 w-4 animate-spin text-white"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            ></circle>
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            ></path>
          </svg>
          <span>{{ saving ? 'กำลังบันทึก...' : 'บันทึกตารางสิทธิ์' }}</span>
        </button>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <div
      v-if="successMessage"
      class="rounded-md bg-green-50 p-4 text-sm text-green-800 border border-green-200 flex items-center justify-between"
      role="status"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-5 w-5 text-green-600"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"
          />
        </svg>
        <span>{{ successMessage }}</span>
      </div>
      <button @click="successMessage = ''" class="text-green-600 hover:text-green-800">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M6 18L18 6M6 6l12 12"
          />
        </svg>
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="rounded-md bg-red-50 p-4 text-sm text-red-800 border border-red-200 flex items-center justify-between"
      role="alert"
    >
      <div class="flex items-center">
        <svg
          class="mr-2 h-5 w-5 text-red-600"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <span>{{ errorMessage }}</span>
      </div>
      <button @click="errorMessage = ''" class="text-red-600 hover:text-red-800">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M6 18L18 6M6 6l12 12"
          />
        </svg>
      </button>
    </div>

    <!-- SUPER_ADMIN Locked Notice -->
    <div
      v-if="isSuperAdminRole"
      class="rounded-md bg-indigo-50 p-4 text-sm text-indigo-800 border border-indigo-200"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-indigo-600 mr-2 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
          />
        </svg>
        <span>
          <strong>ตารางสิทธิ์ SUPER_ADMIN ถูกล็อก:</strong> บทบาท SUPER_ADMIN
          จำเป็นต้องมีสิทธิ์ครบทุกรายการในระบบเสมอ
          ระบบจึงล็อกสิทธิ์ทุกรายการไว้เป็นค่าเริ่มต้นและไม่สามารถยกเลิกได้
        </span>
      </div>
    </div>

    <!-- Read-Only Mode Banner -->
    <div
      v-else-if="!authStore.can('role.manage')"
      class="rounded-md bg-gray-100 p-4 text-sm text-gray-700 border border-gray-200"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-gray-500 mr-2 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <span>
          <strong>โหมดดูอย่างเดียว (Read-Only):</strong>
          คุณไม่มีสิทธิ์ในการแก้ไขตารางสิทธิ์ (จำเป็นต้องมีสิทธิ์
          <code>role.manage</code>)
        </span>
      </div>
    </div>

    <!-- Disabled Role Warning Banner -->
    <div
      v-if="role?.status === 'DISABLED'"
      class="rounded-md bg-amber-50 p-4 text-sm text-amber-800 border border-amber-200"
    >
      <div class="flex">
        <svg
          class="h-5 w-5 text-amber-600 mr-2 shrink-0"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
        <span>
          <strong>บทบาทนี้ปิดใช้งานอยู่:</strong>
          การกำหนดสิทธิ์ในหน้านี้จะยังคงถูกบันทึกไว้ในฐานข้อมูล
          แต่จะไม่มีผลต่อผู้ใช้งานจริงจนกว่าบทบาทนี้จะถูกเปิดใช้งาน (ACTIVE)
        </span>
      </div>
    </div>

    <!-- Sticky Summary Bar -->
    <div
      class="rounded-lg bg-white p-4 shadow flex items-center justify-between border-l-4"
      :class="isDirty ? 'border-amber-500' : 'border-indigo-500'"
    >
      <div class="text-sm">
        <span class="font-medium text-gray-900">จำนวนสิทธิ์ที่เลือก:</span>
        <span class="ml-1.5 font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded">
          {{ selectedCount }} / {{ catalog.length }}
        </span>
        <span v-if="isDirty" class="ml-3 text-xs font-semibold text-amber-600">
          (มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก)
        </span>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="initialLoading" class="rounded-lg bg-white p-12 text-center text-gray-500 shadow">
      <svg class="mx-auto h-8 w-8 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
        <circle
          class="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          stroke-width="4"
        ></circle>
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        ></path>
      </svg>
      <p class="mt-2 text-sm">กำลังโหลดแคตตาล็อกสิทธิ์...</p>
    </div>

    <!-- Grouped Permission Matrix -->
    <div v-else class="space-y-6">
      <div
        v-for="(perms, moduleName) in groupedPermissions"
        :key="moduleName"
        class="overflow-hidden rounded-lg bg-white shadow"
      >
        <!-- Module Header -->
        <div
          class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-2"
        >
          <div>
            <h2 class="text-base font-semibold text-gray-900">
              {{ moduleLabels[moduleName] || moduleName }}
            </h2>
            <p class="text-xs text-gray-500">
              กลุ่มสิทธิ์: {{ moduleName }} ({{ perms.length }} สิทธิ์)
            </p>
          </div>

          <!-- Bulk Selection for this module -->
          <div v-if="!isReadOnly" class="flex items-center space-x-2 text-xs">
            <button
              type="button"
              @click="selectAllInModule(moduleName)"
              class="rounded bg-white px-2.5 py-1 font-medium text-indigo-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
            >
              เลือกทั้งหมดในกลุ่ม
            </button>
            <button
              type="button"
              @click="clearAllInModule(moduleName)"
              class="rounded bg-white px-2.5 py-1 font-medium text-gray-600 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
            >
              ยกเลิกทั้งหมด
            </button>
          </div>
        </div>

        <!-- Permissions List in Module -->
        <div class="divide-y divide-gray-100 p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
          <div
            v-for="p in perms"
            :key="p.code"
            @click="togglePermission(p.code)"
            class="flex items-start space-x-3 rounded-lg p-3 transition border"
            :class="[
              selectedCodes.has(p.code)
                ? 'bg-indigo-50/50 border-indigo-200'
                : 'bg-white border-gray-200 hover:bg-gray-50',
              isReadOnly ? 'cursor-default' : 'cursor-pointer',
            ]"
          >
            <div class="flex h-5 items-center">
              <input
                :id="`perm-${p.code}`"
                type="checkbox"
                :checked="selectedCodes.has(p.code)"
                :disabled="isReadOnly"
                @click.stop="togglePermission(p.code)"
                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-75"
              />
            </div>
            <div class="min-w-0 flex-1 text-sm">
              <label
                :for="`perm-${p.code}`"
                class="font-medium text-gray-900 select-none"
                :class="isReadOnly ? 'cursor-default' : 'cursor-pointer'"
              >
                {{ p.name }}
              </label>
              <div class="mt-0.5 font-mono text-xs text-indigo-600 select-all">
                {{ p.code }}
              </div>
              <p v-if="p.description" class="mt-1 text-xs text-gray-500">
                {{ p.description }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
