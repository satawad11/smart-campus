import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import EventListView from '@/views/events/EventListView.vue'

// CCTV recognition display: a matched person shows by name, a REVIEW event shows the
// candidate the backend proposed (it has no confirmed person), and the similarity is shown.
describe('EventListView recognition display', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/events', name: 'events.index', component: EventListView },
        { path: '/events/:uuid', name: 'events.show', component: { template: '<div />' } },
      ],
    })
    await router.push('/events')
    await router.isReady()
    useAuthStore().user = { uuid: 'u', username: 'officer', permissions: ['event.view'] }
  })

  function mockEvents(events) {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: events,
        meta: { pagination: { current_page: 1, per_page: 20, total: events.length, last_page: 1 } },
      },
    })
  }

  const base = {
    occurred_at: '2026-11-23T10:30:53Z',
    site: { uuid: 's', name: 'Main' },
    camera: { uuid: 'c', name: 'Corridor' },
  }

  it('shows the matched person name (the API sends name, display_name may be null)', async () => {
    mockEvents([
      {
        ...base,
        uuid: 'e1',
        event_type: 'FACE_RECOGNIZED',
        face_score: 0.7784,
        person: { uuid: 'p', name: 'Kit Harington', display_name: null, person_type: 'STUDENT', status: 'ACTIVE' },
        current_decision: { decision: 'ALLOW' },
      },
    ])
    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('Kit Harington')
    expect(wrapper.text()).not.toContain('ไม่ทราบตัวตน')
    expect(wrapper.find('[data-test="face-score"]').text()).toContain('0.78')
  })

  it('shows the proposed candidate for a REVIEW event', async () => {
    mockEvents([
      {
        ...base,
        uuid: 'e2',
        event_type: 'FACE_RECOGNIZED',
        face_score: 0.426,
        person: null,
        review_candidate: { uuid: 'p2', name: 'Rose Leslie', student_code: 'S1' },
        current_decision: { decision: 'REVIEW' },
      },
    ])
    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-test="review-candidate"]').text()).toContain('Rose Leslie')
  })

  it('keeps an unknown face unknown and shows no candidate', async () => {
    mockEvents([
      {
        ...base,
        uuid: 'e3',
        event_type: 'UNKNOWN_PERSON',
        face_score: null,
        person: null,
        review_candidate: null,
        current_decision: { decision: 'UNKNOWN' },
      },
    ])
    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่ทราบตัวตน')
    expect(wrapper.find('[data-test="review-candidate"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="face-score"]').exists()).toBe(false)
  })
})
