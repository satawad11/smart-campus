import { ref } from 'vue'
import { defineStore } from 'pinia'
import notificationsService from '@/services/notifications'
import { getEcho } from '@/services/echo'

/**
 * Notification inbox + live alerts (EVT-022).
 *
 * The backend writes a notification for every monitor.view user when a
 * recognition event is UNKNOWN, DENY or needs REVIEW (EVT-012). This store
 * polls the inbox, keeps the unread count for the header bell, and turns
 * notifications that arrive after the first load into on-screen alerts
 * (with an optional sound). Notifications already waiting at sign-in are only
 * counted, never popped up all at once.
 */

export const POLL_INTERVAL_MS = 15000
const MAX_ALERTS = 5
const SOUND_KEY = 'smart-campus.alert-sound'

/** Types that pop up as an alert; everything else only lands in the inbox. */
export const ALERT_TYPES = ['EVENT_UNKNOWN_PERSON', 'EVENT_DENIED']

export const NOTIFICATION_LABELS = {
  EVENT_UNKNOWN_PERSON: 'พบบุคคลไม่ทราบตัวตน',
  EVENT_DENIED: 'ปฏิเสธการเข้าออก',
  EVENT_REVIEW_REQUIRED: 'มีเหตุการณ์รอตรวจสอบ',
}

export function notificationLabel(notification) {
  return NOTIFICATION_LABELS[notification?.type] || notification?.title || 'การแจ้งเตือน'
}

function readSoundPreference() {
  try {
    return localStorage.getItem(SOUND_KEY) !== 'off'
  } catch {
    return true
  }
}

function playAlertSound() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext
    if (!AudioCtx) return
    const ctx = new AudioCtx()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.value = 880
    gain.gain.setValueAtTime(0.2, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6)
    osc.connect(gain).connect(ctx.destination)
    osc.start()
    osc.stop(ctx.currentTime + 0.6)
    osc.onended = () => ctx.close()
  } catch {
    // Browsers may block audio before the first user interaction; the visual alert still shows.
  }
}

export const useNotificationsStore = defineStore('notifications', () => {
  const items = ref([])
  const unreadCount = ref(0)
  const alerts = ref([])
  const soundEnabled = ref(readSoundPreference())

  let timer = null
  let baselineLoaded = false
  const seen = new Set()

  async function refresh() {
    try {
      const res = await notificationsService.list({ per_page: 20 })
      const list = res.data || []
      items.value = list
      unreadCount.value = res.meta?.unread_count ?? list.filter((n) => !n.read_at).length

      const fresh = list.filter((n) => !seen.has(n.uuid))
      list.forEach((n) => seen.add(n.uuid))

      if (baselineLoaded) {
        const newAlerts = fresh.filter((n) => !n.read_at && ALERT_TYPES.includes(n.type))
        if (newAlerts.length > 0) {
          alerts.value = [...newAlerts, ...alerts.value].slice(0, MAX_ALERTS)
          if (soundEnabled.value) playAlertSound()
        }
      }
      baselineLoaded = true
    } catch (err) {
      // Signed out or session expired: stop polling instead of retrying forever.
      if (err?.status === 401 || err?.response?.status === 401) stop()
    }
  }

  // Realtime push (private channel user.{uuid}): a new notification triggers an
  // immediate refresh, so the alert shows within about a second. Polling stays on
  // as the fallback when the socket or the Reverb key is unavailable.
  let pushChannelName = ''
  const realtime = ref(false)

  function subscribe(userUuid) {
    if (!userUuid || pushChannelName) return
    try {
      getEcho()
        .private(`user.${userUuid}`)
        .listen('.notification.created', () => refresh())
      pushChannelName = `user.${userUuid}`
      realtime.value = true
    } catch (err) {
      console.warn('Realtime alerts unavailable, using polling:', err?.message || err)
    }
  }

  function unsubscribe() {
    if (!pushChannelName) return
    try {
      getEcho().leave(pushChannelName)
    } catch {
      // Socket already gone.
    }
    pushChannelName = ''
    realtime.value = false
  }

  function start(userUuid = null) {
    if (timer) return
    refresh()
    timer = setInterval(refresh, POLL_INTERVAL_MS)
    subscribe(userUuid)
  }

  function stop() {
    unsubscribe()
    if (timer) clearInterval(timer)
    timer = null
    baselineLoaded = false
    seen.clear()
    items.value = []
    unreadCount.value = 0
    alerts.value = []
  }

  function dismissAlert(uuid) {
    alerts.value = alerts.value.filter((a) => a.uuid !== uuid)
  }

  async function markRead(notification) {
    dismissAlert(notification.uuid)
    if (notification.read_at) return
    try {
      await notificationsService.markRead(notification.uuid)
      notification.read_at = new Date().toISOString()
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    } catch {
      // Not fatal: the next poll shows the real state.
    }
  }

  async function markAllRead() {
    try {
      await notificationsService.markAllRead()
      const now = new Date().toISOString()
      items.value.forEach((n) => {
        if (!n.read_at) n.read_at = now
      })
      unreadCount.value = 0
      alerts.value = []
    } catch {
      // Not fatal: the next poll shows the real state.
    }
  }

  function toggleSound() {
    soundEnabled.value = !soundEnabled.value
    try {
      localStorage.setItem(SOUND_KEY, soundEnabled.value ? 'on' : 'off')
    } catch {
      // Preference only lasts for this page load.
    }
  }

  return {
    items,
    unreadCount,
    alerts,
    soundEnabled,
    realtime,
    refresh,
    start,
    stop,
    dismissAlert,
    markRead,
    markAllRead,
    toggleSound,
  }
})
