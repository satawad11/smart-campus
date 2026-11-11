<script setup>
import { computed, ref } from 'vue'
import { useRouter, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { roleLabel } from '@/constants/labels'
import ThemeToggle from '@/components/common/ThemeToggle.vue'

const router = useRouter()
const authStore = useAuthStore()
const sidebarOpen = ref(false)

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

const ICONS = {
  home: [
    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  ],
  monitor: [
    'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2zM12 3v2m0 14v2m9-9h-2M5 12H3',
  ],
  health: [
    'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
  ],
  events: [
    'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  ],
  unknown: [
    'M12 15a3 3 0 100-6 3 3 0 000 6zM12 15v4m-4-9.5C8 7 9.79 5 12 5s4 2 4 4.5M5.5 21h13a2 2 0 001.85-2.75l-1.315-3.5A2 2 0 0016.685 13H7.315a2 2 0 00-1.85 1.75l-1.315 3.5A2 2 0 005.5 21z',
  ],
  search: ['M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
  reports: [
    'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  ],
  students: [
    'M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222',
  ],
  users: [
    'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
  ],
  shield: [
    'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
  ],
  face: [
    'M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z',
  ],
  camera: [
    'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
  ],
  node: [
    'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
  ],
  settings: [
    'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
    'M15 12a3 3 0 11-6 0 3 3 0 016 0z',
  ],
  audit: [
    'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
  ],
  warning: [
    'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
  ],
  update: [
    'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
  ],
}

/**
 * Sidebar navigation. Visibility rules mirror the backend permission codes;
 * the backend remains the authority on every request.
 */
const NAV_SECTIONS = [
  {
    title: 'ภาพรวม',
    items: [
      { to: '/dashboard', label: 'แดชบอร์ด', icon: 'home' },
      {
        to: '/monitor',
        label: 'ศูนย์เฝ้าระวังแบบเรียลไทม์',
        icon: 'monitor',
        perm: 'monitor.view',
      },
      {
        to: '/health',
        label: 'ภาพรวมสถานะระบบ',
        icon: 'health',
        perm: ['camera.view', 'ai_node.view'],
      },
    ],
  },
  {
    title: 'เหตุการณ์และรายงาน',
    items: [
      { to: '/events', label: 'ประวัติเหตุการณ์ / คิวตรวจสอบ', icon: 'events', perm: 'event.view' },
      { to: '/unknown-persons', label: 'บุคคลไม่ทราบตัวตน', icon: 'unknown', perm: 'event.view' },
      { to: '/history', label: 'ค้นหาประวัติการเข้า-ออก', icon: 'search', perm: 'report.view' },
      { to: '/reports', label: 'รายงานและการส่งออก', icon: 'reports', perm: 'report.view' },
    ],
  },
  {
    title: 'บุคคลและสิทธิ์',
    items: [
      { to: '/students', label: 'จัดการข้อมูลนักเรียน', icon: 'students', perm: 'student.view' },
      { to: '/users', label: 'จัดการผู้ใช้งาน', icon: 'users', perm: 'user.view' },
      { to: '/roles', label: 'จัดการบทบาทและสิทธิ์', icon: 'shield', perm: 'role.view' },
      { to: '/faces', label: 'ข้อมูลใบหน้าชีวมิติ', icon: 'face', perm: 'face.view' },
      { to: '/faces/test', label: 'ทดสอบการรู้จำใบหน้า', icon: 'node', perm: 'face.test' },
    ],
  },
  {
    title: 'อุปกรณ์',
    items: [
      { to: '/cameras', label: 'จัดการกล้อง', icon: 'camera', perm: 'camera.view' },
      {
        to: '/ai-nodes',
        label: 'จัดการหน่วยประมวลผลปัญญาประดิษฐ์',
        icon: 'node',
        perm: 'ai_node.view',
      },
    ],
  },
  {
    title: 'ผู้ดูแลระบบ',
    items: [
      { to: '/settings', label: 'การตั้งค่าระบบ', icon: 'settings', perm: 'system.view' },
      { to: '/system/license', label: 'สิทธิ์การใช้งานระบบ', icon: 'shield', perm: 'system.view' },
      // ACR-OPS-01: SUPER_ADMIN only (system.update)
      { to: '/system/update', label: 'อัปเดตระบบ', icon: 'update', perm: 'system.update' },
      { to: '/audit-logs', label: 'บันทึกการตรวจสอบ', icon: 'audit', perm: 'audit.view' },
      {
        to: '/system/incidents',
        label: 'บันทึกเหตุขัดข้องของระบบ',
        icon: 'warning',
        perm: 'system.view',
      },
    ],
  },
]

function allowed(item) {
  if (!item.perm) return true
  const perms = Array.isArray(item.perm) ? item.perm : [item.perm]
  return perms.some((p) => authStore.can(p))
}

const visibleSections = computed(() =>
  NAV_SECTIONS.map((section) => ({ ...section, items: section.items.filter(allowed) })).filter(
    (section) => section.items.length > 0,
  ),
)

const displayName = computed(() => authStore.user?.display_name || authStore.user?.username || '')
const avatarInitial = computed(() => displayName.value.trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <div class="flex min-h-screen bg-canvas text-gray-900">
    <!-- Mobile backdrop -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-black/40 backdrop-blur-sm lg:hidden"
      aria-hidden="true"
      @click="sidebarOpen = false"
    ></div>

    <!-- Sidebar -->
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-72 shrink-0 flex-col border-r border-gray-200 bg-surface transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full'"
    >
      <div class="flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 px-5">
        <div
          class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-sm font-bold text-white shadow-md shadow-blue-600/25"
        >
          สอ
        </div>
        <div class="min-w-0 leading-tight">
          <p class="truncate text-[15px] font-bold tracking-tight text-gray-900">
            สถานศึกษาอัจฉริยะ
          </p>
          <p class="truncate text-xs text-gray-500">ความปลอดภัยและการเข้าออก</p>
        </div>
      </div>

      <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
        <div v-for="section in visibleSections" :key="section.title">
          <p class="mb-2 px-3 text-[11px] font-semibold tracking-wider text-gray-400">
            {{ section.title }}
          </p>
          <div class="space-y-0.5">
            <router-link
              v-for="item in section.items"
              :key="item.to"
              :to="item.to"
              class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900"
              active-class="nav-active"
              @click="sidebarOpen = false"
            >
              <svg
                class="h-5 w-5 shrink-0 opacity-80 group-hover:opacity-100"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  v-for="(d, i) in ICONS[item.icon]"
                  :key="i"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.8"
                  :d="d"
                />
              </svg>
              <span class="leading-snug">{{ item.label }}</span>
            </router-link>
          </div>
        </div>
      </nav>

      <!-- User profile in sidebar footer -->
      <div v-if="authStore.user" class="shrink-0 border-t border-gray-200 p-3">
        <div class="flex items-center gap-3 rounded-xl bg-gray-50 p-2.5">
          <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-semibold text-blue-700"
            aria-hidden="true"
          >
            {{ avatarInitial }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-900">
              {{ displayName }}
            </p>
            <p class="truncate text-xs text-gray-500">
              {{
                authStore.user.roles?.map((r) => roleLabel(r)).join(', ') || authStore.user.username
              }}
            </p>
          </div>
          <button
            @click="handleLogout"
            title="ออกจากระบบ"
            aria-label="ออกจากระบบ"
            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
              />
            </svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex min-w-0 flex-1 flex-col">
      <header
        class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-gray-200 bg-surface/85 px-4 backdrop-blur-md sm:px-6"
      >
        <div class="flex min-w-0 items-center gap-3">
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900 lg:hidden"
            aria-label="เปิดเมนู"
            @click="sidebarOpen = true"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16"
              />
            </svg>
          </button>
          <div class="hidden truncate text-sm font-medium text-gray-500 sm:block">
            ระบบบริหารจัดการความปลอดภัยและควบคุมการเข้าออก
          </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
          <ThemeToggle />
          <span class="hidden h-6 w-px bg-gray-200 md:block" aria-hidden="true"></span>
          <span class="hidden text-sm font-medium text-gray-700 md:inline">
            {{ authStore.user?.username }}
          </span>
          <router-link
            to="/account/security"
            class="hidden rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset transition sm:inline-flex"
            :class="
              authStore.user?.mfa_enabled
                ? 'bg-blue-50 text-blue-700 ring-blue-200 hover:bg-blue-100'
                : 'bg-amber-50 text-amber-700 ring-amber-200 hover:bg-amber-100'
            "
          >
            {{ authStore.user?.mfa_enabled ? 'ความปลอดภัยบัญชี' : 'ตั้งค่ายืนยันตัวตนสองชั้น' }}
          </router-link>
          <button
            @click="handleLogout"
            class="rounded-lg border border-gray-200 bg-surface px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500"
          >
            ออกจากระบบ
          </button>
        </div>
      </header>

      <main class="flex-1 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto w-full max-w-[1600px]">
          <RouterView />
        </div>
      </main>
    </div>
  </div>
</template>

<style scoped>
.nav-active {
  background-color: var(--color-blue-50);
  color: var(--text-color-blue-700);
  font-weight: 600;
  box-shadow: inset 3px 0 0 var(--color-blue-600);
}
</style>
