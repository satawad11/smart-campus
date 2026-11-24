import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import eventsService from '@/services/events'
import { useAuthStore } from '@/stores/auth'
import LiveMonitorView from '@/views/monitor/LiveMonitorView.vue'
import EventDetailView from '@/views/events/EventDetailView.vue'
import EventListView from '@/views/events/EventListView.vue'

/** VEH-012 (ACR-VEH-01): plate reads in the Live Monitor, event detail and event list. */

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

const PLATE_EVENT = {
  uuid: 'evt-plate',
  event_type: 'PLATE_RECOGNIZED',
  occurred_at: '2026-11-17T07:45:00Z',
  person: null,
  camera: { uuid: 'cam-1', name: 'ประตูรถ' },
  zone: null,
  site: { uuid: 'site-1', name: 'วิทยาลัย' },
  current_decision: null,
  decisions: [],
  plate: {
    plate_number: '1กข1234',
    plate_display: '1กข 1234',
    plate_province: 'TH-10',
    plate_province_name: 'กรุงเทพมหานคร',
    raw_text: '1กข-1234',
    ocr_score: 0.97,
    vehicle_uuid: 'veh-1',
    vehicle_status: 'BLOCKED',
  },
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

function apiMock({ flags = { alpr: true }, events = [] } = {}) {
  return vi.spyOn(api, 'get').mockImplementation((url, config) => {
    if (url === '/features') return Promise.resolve({ data: { data: flags } })
    if (url === '/cameras') {
      return Promise.resolve({
        data: {
          data: [{ uuid: 'cam-1', name: 'ประตูรถ', site: { uuid: 'site-1', name: 'วิทยาลัย' } }],
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

  it('shows backfilled plate reads with a plate badge instead of a decision', async () => {
    apiMock({ events: [PLATE_EVENT] })
    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(wrapper.text()).toContain('1กข 1234 กรุงเทพมหานคร')
    expect(wrapper.find('[data-test="plate-badge"]').text()).toBe('รถระงับ')
  })

  it('listens to plate.read and adds pushed reads to the feed', async () => {
    apiMock()
    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(channel.listen).toHaveBeenCalledWith('.plate.read', expect.any(Function))
    listeners['.plate.read']({
      event: { uuid: 'evt-2', event_type: 'PLATE_UNKNOWN', occurred_at: '2026-11-17T08:00:00Z' },
      plate: { plate_display: '9ฮฮ 9999', vehicle_status: null },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('9ฮฮ 9999')
    expect(wrapper.find('[data-test="plate-badge"]').text()).toBe('รถไม่ได้ลงทะเบียน')
  })
})

describe('Event detail', () => {
  it('shows the plate section, links to the vehicle, and offers no review or decisions', async () => {
    useAuthStore().user = {
      uuid: 'u',
      username: 'officer',
      permissions: ['event.view', 'event.review', 'vehicle.view'],
    }
    apiMock()
    vi.spyOn(eventsService, 'getEvent').mockResolvedValue({ data: PLATE_EVENT })
    await router.push('/events/evt-plate')

    const wrapper = mount(EventDetailView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('h1').text()).toContain('1กข 1234')
    expect(wrapper.find('[data-test="plate-section"]').text()).toContain('ระงับ')
    expect(wrapper.text()).not.toContain('ประวัติการตัดสินใจ')
    expect(wrapper.findAll('select').length).toBe(0)

    const push = vi.spyOn(router, 'push')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'ดูข้อมูลรถ')
      .trigger('click')
    expect(push).toHaveBeenCalledWith({ name: 'vehicles.show', params: { uuid: 'veh-1' } })
  })
})

describe('Event list', () => {
  it('searches by plate while ALPR is on and shows the plate in the row', async () => {
    useAuthStore().user = { uuid: 'u', username: 'officer', permissions: ['event.view'] }
    apiMock()
    const list = vi
      .spyOn(eventsService, 'listEvents')
      .mockResolvedValue({ data: [PLATE_EVENT], meta: null })

    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('1กข 1234')
    await wrapper.find('#plate-filter').setValue('1กข-1234')
    await wrapper.find('#plate-filter').trigger('keyup.enter')
    await flushPromises()

    expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ plate: '1กข-1234' }))
  })

  it('hides the plate search while ALPR is off', async () => {
    useAuthStore().user = { uuid: 'u', username: 'officer', permissions: ['event.view'] }
    apiMock({ flags: { alpr: false } })
    vi.spyOn(eventsService, 'listEvents').mockResolvedValue({ data: [], meta: null })

    const wrapper = mount(EventListView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('#plate-filter').exists()).toBe(false)
  })
})
