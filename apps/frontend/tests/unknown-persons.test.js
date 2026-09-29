import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import UnknownPersonsView from '@/views/events/UnknownPersonsView.vue'
import EventDetailView from '@/views/events/EventDetailView.vue'

describe('EVT-018: Unknown Persons page', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/unknown-persons', name: 'unknown-persons.index', component: UnknownPersonsView },
        { path: '/events/:uuid', name: 'events.show', component: EventDetailView },
      ],
    })
    await router.push('/unknown-persons')
    await router.isReady()
  })

  it('shows a permission-denied message without event.view', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-1', username: 'nobody', permissions: [] }

    const wrapper = mount(UnknownPersonsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์เข้าถึงหน้านี้')
  })

  it('lists only UNKNOWN_PERSON events, filtering server-side by event_type', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-2', username: 'officer', permissions: ['event.view'] }

    const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          {
            uuid: 'evt-1',
            event_type: 'UNKNOWN_PERSON',
            occurred_at: '2026-09-23T09:00:00Z',
            site: { uuid: 's-1', name: 'Main Campus' },
            camera: { uuid: 'c-1', name: 'Gate 1' },
            current_decision: { decision: 'UNKNOWN' },
          },
        ],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      },
    })

    const wrapper = mount(UnknownPersonsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/events', {
      params: { event_type: 'UNKNOWN_PERSON', from: undefined, to: undefined, page: 1, per_page: 20 },
    })
    expect(wrapper.text()).toContain('Gate 1')
    expect(wrapper.text()).toContain('UNKNOWN')
  })

  it('counts only rows still at the default UNKNOWN decision as unresolved', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-3', username: 'officer2', permissions: ['event.view'] }

    vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          { uuid: 'evt-1', event_type: 'UNKNOWN_PERSON', occurred_at: '2026-09-23T09:00:00Z', site: null, camera: null, current_decision: { decision: 'UNKNOWN' } },
          { uuid: 'evt-2', event_type: 'UNKNOWN_PERSON', occurred_at: '2026-09-23T09:01:00Z', site: null, camera: null, current_decision: { decision: 'ALLOW' } },
        ],
        meta: { pagination: { current_page: 1, per_page: 20, total: 2, last_page: 1 } },
      },
    })

    const wrapper = mount(UnknownPersonsView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('ยังไม่ได้ตรวจสอบในหน้านี้')
    expect(wrapper.find('.text-amber-600').text()).toBe('1')
  })

  it('clicking a row navigates to the event detail page', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-4', username: 'officer3', permissions: ['event.view'] }

    vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          { uuid: 'evt-9', event_type: 'UNKNOWN_PERSON', occurred_at: '2026-09-23T09:00:00Z', site: null, camera: null, current_decision: { decision: 'UNKNOWN' } },
        ],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      },
    })

    const wrapper = mount(UnknownPersonsView, { global: { plugins: [router] } })
    await flushPromises()

    await wrapper.find('tbody tr').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('events.show')
    expect(router.currentRoute.value.params.uuid).toBe('evt-9')
  })
})
