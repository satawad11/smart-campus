import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import eventsService from '@/services/events'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import LiveMonitorView from '@/views/monitor/LiveMonitorView.vue'
import EventDetailView from '@/views/events/EventDetailView.vue'
import EventListView from '@/views/events/EventListView.vue'
import AlertToasts from '@/components/notifications/AlertToasts.vue'

/** GATE-007 (ACR-VEH-01): Smart Gate decisions in the Live Monitor, events, review and alerts. */

const listeners = {}
const channel = {
  listen: vi.fn((name, cb) => {
    listeners[name] = cb
    return channel
  }),
  stopListening: vi.fn(() => channel),
}

vi.mock('@/services/echo', () => ({
  getEcho: () => ({ private: () => channel, leave: vi.fn() }),
  disconnectEcho: vi.fn(),
}))

const GATE_PASS = {
  uuid: 'pass-1',
  match_result: 'PLATE_ONLY',
  gate: { uuid: 'gate-1', code: 'G1', name: 'ประตูหน้า' },
  face_event_uuid: null,
  plate_event_uuid: 'evt-plate',
  plate: { plate_display: '1กข 1234', plate_province_name: 'กรุงเทพมหานคร' },
}

const GATE_DECISION = {
  uuid: 'dec-gate',
  decision: 'REVIEW',
  reason_code: 'GATE_PLATE_ONLY',
  decision_source: 'POLICY',
  gate_pass: GATE_PASS,
  created_at: '2026-11-20T07:45:20Z',
}

const PLATE_ONLY_EVENT = {
  uuid: 'evt-plate',
  event_type: 'PLATE_RECOGNIZED',
  occurred_at: '2026-11-20T07:45:00Z',
  person: null,
  camera: { uuid: 'cam-1', name: 'กล้องป้าย' },
  zone: null,
  site: { uuid: 'site-1', name: 'วิทยาลัย' },
  plate: {
    plate_display: '1กข 1234',
    plate_province_name: 'กรุงเทพมหานคร',
    vehicle_status: 'ACTIVE',
  },
  current_decision: GATE_DECISION,
  decisions: [GATE_DECISION],
}

let router

beforeEach(async () => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
  Object.keys(listeners).forEach((k) => delete listeners[k])
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div />' } },
      { path: '/events/:uuid', name: 'events.show', component: EventDetailView },
      { path: '/vehicles/:uuid', name: 'vehicles.show', component: { template: '<div />' } },
    ],
  })
  await router.push('/')
  await router.isReady()
})

function apiMock({ events = [] } = {}) {
  return vi.spyOn(api, 'get').mockImplementation((url, config) => {
    if (url === '/features')
      return Promise.resolve({ data: { data: { alpr: true, vehicle: true } } })
    if (url === '/cameras') {
      return Promise.resolve({
        data: {
          data: [{ uuid: 'cam-1', name: 'กล้องหน้า', site: { uuid: 'site-1', name: 'วิทยาลัย' } }],
        },
      })
    }
    if (url === '/events' && !config?.params?.decision) {
      return Promise.resolve({ data: { data: events, meta: null } })
    }
    return Promise.resolve({ data: { data: [], meta: null } })
  })
}

describe('Live Monitor', () => {
  beforeEach(() => {
    useAuthStore().user = {
      uuid: 'u',
      username: 'officer',
      permissions: ['monitor.view', 'camera.view', 'event.view'],
    }
  })

  it('updates the face row in place when the gate decision arrives', async () => {
    apiMock()
    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    const face = {
      uuid: 'evt-face',
      event_type: 'FACE_RECOGNIZED',
      occurred_at: '2026-11-20T07:45:00Z',
      person_name: 'สมชาย ใจดี',
    }
    listeners['.event.decided']({
      event: face,
      decision: { uuid: 'd1', decision: 'ALLOW', reason_code: 'STUDENT_ACTIVE' },
      gate_pass: null,
    })
    listeners['.event.decided']({
      event: face,
      decision: { uuid: 'd2', decision: 'ALLOW', reason_code: 'STUDENT_ACTIVE' },
      gate_pass: { ...GATE_PASS, match_result: 'MISMATCH', face_event_uuid: 'evt-face' },
    })
    await flushPromises()

    const rows = wrapper.findAll('li.py-3')
    expect(rows.length).toBe(1)
    const gate = rows[0].find('[data-test="gate-pass"]')
    expect(gate.text()).toContain('ประตูหน้า')
    expect(gate.find('[data-test="gate-match"]').text()).toBe('ไม่ใช่รถของบุคคลนี้')
    expect(gate.find('[data-test="gate-plate"]').text()).toContain('1กข 1234')
  })

  it('turns a plate read into a decided row when its plate-only gate decision arrives', async () => {
    apiMock()
    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    const event = {
      uuid: 'evt-plate',
      event_type: 'PLATE_RECOGNIZED',
      occurred_at: '2026-11-20T07:45:00Z',
    }
    listeners['.plate.read']({
      event,
      plate: { plate_display: '1กข 1234', vehicle_status: 'ACTIVE' },
    })
    listeners['.event.decided']({ event, decision: GATE_DECISION, gate_pass: GATE_PASS })
    await flushPromises()

    expect(wrapper.findAll('li.py-3').length).toBe(1)
    expect(wrapper.find('[data-test="plate-badge"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('REVIEW')
    expect(wrapper.text()).toContain('1กข 1234')
    expect(wrapper.find('[data-test="gate-match"]').text()).toBe('เห็นเฉพาะป้ายทะเบียน')
  })

  it('shows the gate block on backfilled events', async () => {
    apiMock({ events: [PLATE_ONLY_EVENT] })
    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(wrapper.find('[data-test="gate-pass"]').text()).toContain('ประตูหน้า')
  })
})

describe('Event detail', () => {
  it('shows the gate pass and lets an officer review a plate-only gate decision', async () => {
    useAuthStore().user = {
      uuid: 'u',
      username: 'officer',
      permissions: ['event.view', 'event.review'],
    }
    apiMock()
    vi.spyOn(eventsService, 'getEvent').mockResolvedValue({ data: PLATE_ONLY_EVENT })
    await router.push('/events/evt-plate')

    const wrapper = mount(EventDetailView, { global: { plugins: [router] } })
    await flushPromises()

    const section = wrapper.find('[data-test="gate-section"]')
    expect(section.text()).toContain('ประตูหน้า')
    expect(section.text()).toContain('ไม่ได้สั่งเปิดไม้กั้น')
    expect(wrapper.text()).toContain('ประวัติการตัดสินใจ (1)')
    expect(wrapper.text()).toContain('ตรวจสอบโดยเจ้าหน้าที่')
    expect(wrapper.find('[data-test="plate-badge"]').exists()).toBe(false)
  })
})

describe('Review queue', () => {
  it('shows the gate block next to the decision', async () => {
    useAuthStore().user = { uuid: 'u', username: 'officer', permissions: ['event.view'] }
    apiMock()
    vi.spyOn(eventsService, 'listEvents').mockResolvedValue({
      data: [PLATE_ONLY_EVENT],
      meta: null,
    })

    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-test="gate-pass"]').text()).toContain('เห็นเฉพาะป้ายทะเบียน')
  })
})

describe('Alert toast', () => {
  it('says which gate, what it saw and which plate', async () => {
    const store = useNotificationsStore()
    store.alerts = [
      {
        uuid: 'n1',
        type: 'EVENT_REVIEW_REQUIRED',
        created_at: '2026-11-20T07:45:20Z',
        data: {
          event_uuid: 'evt-plate',
          gate_name: 'ประตูหน้า',
          match_result: 'PLATE_ONLY',
          plate_display: '1กข 1234',
        },
      },
    ]

    const wrapper = mount(AlertToasts, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-test="alert-gate"]').text()).toBe(
      'จุดเข้าออก ประตูหน้า · เห็นเฉพาะป้ายทะเบียน · 1กข 1234',
    )
  })
})
