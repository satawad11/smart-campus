<script setup>
import { useRouter } from 'vue-router'
import { useNotificationsStore, notificationLabel } from '@/stores/notifications'

/**
 * On-screen alerts for new UNKNOWN / DENY notifications (EVT-022). They stay
 * until the operator opens or dismisses them, so an unattended screen still
 * shows what happened.
 */
const store = useNotificationsStore()
const router = useRouter()

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString('th-TH')
}

async function view(alert) {
  await store.markRead(alert)
  const eventUuid = alert.data?.event_uuid
  if (eventUuid) router.push({ name: 'events.show', params: { uuid: eventUuid } })
}
</script>

<template>
  <div
    class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
    aria-live="assertive"
  >
    <div
      v-for="alert in store.alerts"
      :key="alert.uuid"
      data-test="alert-toast"
      role="alert"
      class="pointer-events-auto rounded-xl border-l-4 bg-surface p-4 shadow-lg ring-1 ring-gray-200"
      :class="alert.type === 'EVENT_UNKNOWN_PERSON' ? 'border-red-500' : 'border-amber-500'"
    >
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-900">{{ notificationLabel(alert) }}</p>
          <p
            v-if="alert.data?.camera_name || alert.data?.camera_code"
            class="truncate text-xs text-gray-600"
          >
            กล้อง {{ alert.data.camera_name || alert.data.camera_code }}
          </p>
          <p class="text-xs text-gray-400">
            {{ formatTime(alert.data?.occurred_at || alert.created_at) }}
          </p>
        </div>
        <button
          type="button"
          data-test="alert-dismiss"
          class="text-gray-400 hover:text-gray-700"
          aria-label="ปิดการแจ้งเตือน"
          @click="store.dismissAlert(alert.uuid)"
        >
          ✕
        </button>
      </div>
      <button
        v-if="alert.data?.event_uuid"
        type="button"
        data-test="alert-view"
        class="mt-2 text-xs font-medium text-blue-600 hover:text-blue-800"
        @click="view(alert)"
      >
        ดูเหตุการณ์
      </button>
    </div>
  </div>
</template>
