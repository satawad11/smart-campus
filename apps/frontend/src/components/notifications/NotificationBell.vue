<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore, notificationLabel } from '@/stores/notifications'

/**
 * Header bell (EVT-022): unread count, the latest notifications, mark as read,
 * and the alert-sound switch. Clicking an item opens its event.
 */
const store = useNotificationsStore()
const router = useRouter()
const open = ref(false)
const root = ref(null)

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'medium' })
}

function where(notification) {
  const d = notification.data || {}
  return d.camera_name || d.camera_code || ''
}

async function openNotification(notification) {
  await store.markRead(notification)
  open.value = false
  const eventUuid = notification.data?.event_uuid
  if (eventUuid) router.push({ name: 'events.show', params: { uuid: eventUuid } })
}

function onDocumentClick(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      data-test="notification-bell"
      class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 hover:text-gray-900"
      :aria-label="`การแจ้งเตือน (${store.unreadCount} ยังไม่อ่าน)`"
      @click="open = !open"
    >
      <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0m6 0H9"
        />
      </svg>
      <span
        v-if="store.unreadCount > 0"
        data-test="notification-count"
        class="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-red-600 px-1 text-center text-[10px] font-semibold leading-[1.1rem] text-white"
        >{{ store.unreadCount > 99 ? '99+' : store.unreadCount }}</span
      >
    </button>

    <div
      v-if="open"
      data-test="notification-panel"
      class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-xl border border-gray-200 bg-surface shadow-lg"
    >
      <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
        <span class="text-sm font-semibold text-gray-900">การแจ้งเตือน</span>
        <div class="flex items-center gap-3 text-xs">
          <button
            type="button"
            data-test="notification-sound"
            class="text-gray-500 hover:text-gray-900"
            @click="store.toggleSound()"
          >
            {{ store.soundEnabled ? 'ปิดเสียง' : 'เปิดเสียง' }}
          </button>
          <button
            v-if="store.unreadCount > 0"
            type="button"
            data-test="notification-read-all"
            class="text-blue-600 hover:text-blue-800"
            @click="store.markAllRead()"
          >
            อ่านทั้งหมด
          </button>
        </div>
      </div>

      <ul v-if="store.items.length > 0" class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
        <li v-for="n in store.items" :key="n.uuid">
          <button
            type="button"
            data-test="notification-item"
            class="flex w-full gap-3 px-4 py-3 text-left hover:bg-gray-50"
            :class="n.read_at ? '' : 'bg-blue-50/40'"
            @click="openNotification(n)"
          >
            <span
              class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
              :class="n.read_at ? 'bg-transparent' : 'bg-red-500'"
            ></span>
            <span class="min-w-0">
              <span class="block text-sm font-medium text-gray-900">{{
                notificationLabel(n)
              }}</span>
              <span v-if="where(n)" class="block truncate text-xs text-gray-600">
                กล้อง {{ where(n) }}
              </span>
              <span class="block text-xs text-gray-400">
                {{ formatTime(n.data?.occurred_at || n.created_at) }}
              </span>
            </span>
          </button>
        </li>
      </ul>
      <p v-else class="px-4 py-6 text-center text-sm text-gray-400">ไม่มีการแจ้งเตือน</p>

      <router-link
        to="/unknown-persons"
        class="block border-t border-gray-100 px-4 py-2.5 text-center text-xs font-medium text-blue-600 hover:bg-gray-50"
        @click="open = false"
      >
        ดูบุคคลไม่ทราบตัวตนทั้งหมด
      </router-link>
    </div>
  </div>
</template>
