import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useNotificationsStore, POLL_INTERVAL_MS } from '@/stores/notifications'
import NotificationBell from '@/components/notifications/NotificationBell.vue'
import AlertToasts from '@/components/notifications/AlertToasts.vue'

function notification(uuid, type = 'EVENT_UNKNOWN_PERSON', extra = {}) {
  return {
    uuid,
    type,
    title: 'Unknown person detected',
    body: null,
    data: {
      event_uuid: `evt-${uuid}`,
      camera_code: 'CAM-0001',
      camera_name: 'ประตูหน้า',
      occurred_at: '2026-11-16T12:00:00Z',
    },
    read_at: null,
    created_at: '2026-11-16T12:00:01Z',
    ...extra,
  }
}

function inbox(list) {
  return {
    data: {
      data: list,
      meta: { unread_count: list.filter((n) => !n.read_at).length },
    },
  }
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/events/:uuid', name: 'events.show', component: { template: '<div />' } },
      { path: '/unknown-persons', component: { template: '<div />' } },
    ],
  })
}

describe('EVT-022: unknown-person alerts', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    vi.useFakeTimers()
    try {
      localStorage.setItem('smart-campus.alert-sound', 'off')
    } catch {
      // jsdom without storage: sound stays on, playAlertSound is a no-op without AudioContext
    }
  })

  afterEach(() => {
    useNotificationsStore().stop()
    vi.useRealTimers()
  })

  it('counts notifications already waiting at sign-in without popping them up', async () => {
    vi.spyOn(api, 'get').mockResolvedValue(inbox([notification('a'), notification('b')]))
    const store = useNotificationsStore()

    store.start()
    await flushPromises()

    expect(store.unreadCount).toBe(2)
    expect(store.items).toHaveLength(2)
    expect(store.alerts).toHaveLength(0)
  })

  it('pops up alerts for unknown and review events that arrive while the console is open', async () => {
    const getSpy = vi.spyOn(api, 'get').mockResolvedValue(inbox([notification('a')]))
    const store = useNotificationsStore()
    store.start()
    await flushPromises()

    getSpy.mockResolvedValue(
      inbox([notification('c', 'EVENT_REVIEW_REQUIRED'), notification('b'), notification('a')]),
    )
    vi.advanceTimersByTime(POLL_INTERVAL_MS)
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/notifications', { params: { per_page: 20 } })
    expect(store.unreadCount).toBe(3)
    expect(store.alerts.map((a) => a.uuid)).toEqual(['c', 'b'])
  })

  it('stops polling and clears state when the session is gone', async () => {
    const getSpy = vi.spyOn(api, 'get').mockRejectedValue({ status: 401 })
    const store = useNotificationsStore()
    store.start()
    await flushPromises()

    vi.advanceTimersByTime(POLL_INTERVAL_MS * 3)
    await flushPromises()

    expect(getSpy).toHaveBeenCalledTimes(1)
    expect(store.unreadCount).toBe(0)
  })

  it('bell shows the unread count and opens the event when an item is clicked', async () => {
    vi.spyOn(api, 'get').mockResolvedValue(inbox([notification('a')]))
    const patchSpy = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: {} } })
    const router = makeRouter()
    await router.push('/')
    await router.isReady()

    const store = useNotificationsStore()
    store.start()
    await flushPromises()

    const wrapper = mount(NotificationBell, { global: { plugins: [router] } })
    expect(wrapper.find('[data-test="notification-count"]').text()).toBe('1')

    await wrapper.find('[data-test="notification-bell"]').trigger('click')
    const item = wrapper.find('[data-test="notification-item"]')
    expect(item.text()).toContain('พบบุคคลไม่ทราบตัวตน')
    expect(item.text()).toContain('กล้อง ประตูหน้า')

    await item.trigger('click')
    await flushPromises()

    expect(patchSpy).toHaveBeenCalledWith('/notifications/a/read')
    expect(router.currentRoute.value.fullPath).toBe('/events/evt-a')
    expect(store.unreadCount).toBe(0)
    expect(wrapper.find('[data-test="notification-count"]').exists()).toBe(false)
  })

  it('read-all clears the count', async () => {
    vi.spyOn(api, 'get').mockResolvedValue(inbox([notification('a'), notification('b')]))
    const postSpy = vi
      .spyOn(api, 'post')
      .mockResolvedValue({ data: { data: { updated_count: 2 } } })
    const router = makeRouter()
    const store = useNotificationsStore()
    store.start()
    await flushPromises()

    const wrapper = mount(NotificationBell, { global: { plugins: [router] } })
    await wrapper.find('[data-test="notification-bell"]').trigger('click')
    await wrapper.find('[data-test="notification-read-all"]').trigger('click')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith('/notifications/read-all')
    expect(store.unreadCount).toBe(0)
  })

  it('alert toast shows where it happened and can be dismissed', async () => {
    const router = makeRouter()
    const store = useNotificationsStore()
    store.alerts = [notification('x')]

    const wrapper = mount(AlertToasts, { global: { plugins: [router] } })
    const toast = wrapper.find('[data-test="alert-toast"]')
    expect(toast.text()).toContain('พบบุคคลไม่ทราบตัวตน')
    expect(toast.text()).toContain('กล้อง ประตูหน้า')

    await wrapper.find('[data-test="alert-dismiss"]').trigger('click')
    expect(wrapper.find('[data-test="alert-toast"]').exists()).toBe(false)
  })
})
