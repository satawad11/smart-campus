<script setup>
import { useRouter, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

async function handleLogout() {
  await authStore.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex min-h-screen bg-gray-50 text-gray-900">
    <!-- Sidebar -->
    <aside class="w-64 shrink-0 border-r border-gray-200 bg-white flex flex-col justify-between">
      <div class="p-4">
        <div class="mb-6 flex items-center space-x-2">
          <div
            class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white font-bold"
          >
            SC
          </div>
          <span class="text-lg font-bold text-gray-900">Smart Campus</span>
        </div>
        <nav class="space-y-1">
          <router-link
            to="/dashboard"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
              />
            </svg>
            แดชบอร์ด
          </router-link>

          <router-link
            v-if="authStore.can('monitor.view')"
            to="/monitor"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2zM12 3v2m0 14v2m9-9h-2M5 12H3"
              />
            </svg>
            ศูนย์เฝ้าระวังแบบเรียลไทม์
          </router-link>

          <router-link
            v-if="authStore.can('event.view')"
            to="/events"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            ประวัติเหตุการณ์ / คิวตรวจสอบ
          </router-link>

          <router-link
            v-if="authStore.can('event.view')"
            to="/unknown-persons"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 15a3 3 0 100-6 3 3 0 000 6zM12 15v4m-4-9.5C8 7 9.79 5 12 5s4 2 4 4.5M5.5 21h13a2 2 0 001.85-2.75l-1.315-3.5A2 2 0 0016.685 13H7.315a2 2 0 00-1.85 1.75l-1.315 3.5A2 2 0 005.5 21z"
              />
            </svg>
            บุคคลไม่ทราบตัวตน
          </router-link>

          <router-link
            v-if="authStore.can('report.view')"
            to="/history"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
              />
            </svg>
            ค้นหาประวัติการเข้า-ออก
          </router-link>

          <router-link
            v-if="authStore.can('report.view')"
            to="/reports"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            รายงานและการส่งออก
          </router-link>

          <router-link
            v-if="authStore.can('student.view')"
            to="/students"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"
              />
            </svg>
            จัดการข้อมูลนักเรียน
          </router-link>

          <router-link
            v-if="authStore.can('user.view')"
            to="/users"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
              />
            </svg>
            จัดการผู้ใช้งาน
          </router-link>

          <router-link
            v-if="authStore.can('role.view')"
            to="/roles"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
              />
            </svg>
            จัดการบทบาทและสิทธิ์
          </router-link>
          <router-link
            v-if="authStore.can('face.view')"
            to="/faces"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            ข้อมูลใบหน้าชีวมิติ
          </router-link>

          <router-link
            v-if="authStore.can('camera.view')"
            to="/cameras"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"
              />
            </svg>
            จัดการกล้อง
          </router-link>

          <router-link
            v-if="authStore.can('ai_node.view')"
            to="/ai-nodes"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
              />
            </svg>
            จัดการ AI Node
          </router-link>

          <router-link
            v-if="authStore.can('camera.view') || authStore.can('ai_node.view')"
            to="/health"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"
              />
            </svg>
            ภาพรวมสถานะระบบ
          </router-link>

          <router-link
            v-if="authStore.can('face.test')"
            to="/faces/test"
            class="flex items-center rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-900"
            active-class="bg-blue-50 text-blue-700 font-semibold"
          >
            <svg
              class="mr-3 h-5 w-5 shrink-0"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
              />
            </svg>
            ทดสอบการรู้จำใบหน้า
          </router-link>
        </nav>
      </div>

      <!-- User profile in sidebar footer -->
      <div v-if="authStore.user" class="border-t border-gray-200 p-4">
        <div class="flex items-center justify-between">
          <div class="truncate">
            <p class="truncate text-sm font-medium text-gray-900">
              {{ authStore.user.display_name || authStore.user.username }}
            </p>
            <p class="truncate text-xs text-gray-500">
              {{
                authStore.user.roles?.map((r) => r.name || r).join(', ') || authStore.user.username
              }}
            </p>
          </div>
          <button
            @click="handleLogout"
            title="ออกจากระบบ"
            class="ml-2 rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500"
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
    <div class="flex flex-1 flex-col overflow-hidden">
      <header class="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-6">
        <div class="text-sm font-medium text-gray-500">
          ระบบบริหารจัดการความปลอดภัยและควบคุมการเข้าออก
        </div>
        <div class="flex items-center space-x-4">
          <span class="text-sm text-gray-700">
            {{ authStore.user?.username }}
          </span>
          <button
            @click="handleLogout"
            class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
          >
            ออกจากระบบ
          </button>
        </div>
      </header>

      <main class="flex-1 overflow-y-auto p-6">
        <RouterView />
      </main>
    </div>
  </div>
</template>
