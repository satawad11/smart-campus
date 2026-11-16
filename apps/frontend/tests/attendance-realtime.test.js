import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import AttendanceView from '@/views/reports/AttendanceView.vue'
import { useNotificationsStore } from '@/stores/notifications'

const listen = vi.fn()
const privateChannel = vi.fn(() => ({ listen }))
const leave = vi.fn()
let echoFails = false

vi.mock('@/services/echo', () => ({
  getEcho: () => {
    if (echoFails) throw new Error('VITE_REVERB_APP_KEY is not set')
    return { private: privateChannel, leave }
  },
  disconnectEcho: vi.fn(),
}))

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      {
        path: '/persons/:uuid/timeline',
        name: 'persons.timeline',
        component: { template: '<div />' },
      },
    ],
  })
}

describe('Attendance page (automatic time in / time out)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('loads today and shows time in, time out and people not yet out', async () => {
    const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          {
            person: { uuid: 'p1', name: 'Nok Suk', person_type: 'STUDENT', code: '6512345' },
            time_in: '2026-11-16T07:42:00+07:00',
            time_in_camera: 'ประตูหน้า',
            time_out: '2026-11-16T16:31:00+07:00',
            time_out_camera: 'ประตูหลัง',
            sightings: 2,
          },
          {
            person: { uuid: 'p2', name: 'ครูสมชาย', person_type: 'TEACHER', code: 'T01' },
            time_in: '2026-11-16T08:10:00+07:00',
            time_in_camera: 'ประตูหน้า',
            time_out: null,
            time_out_camera: null,
            sightings: 1,
          },
        ],
        meta: { date: '2026-11-16', total: 2 },
      },
    })

    const wrapper = mount(AttendanceView, { global: { plugins: [makeRouter()] } })
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/reports/attendance', {
      params: expect.objectContaining({ date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/) }),
    })
    const rows = wrapper.findAll('[data-test="attendance-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Nok Suk')
    expect(rows[0].text()).toContain('ผู้เรียน')
    expect(rows[0].text()).toContain('ประตูหลัง')
    expect(rows[1].text()).toContain('ครู')
    expect(rows[1].text()).toContain('ยังไม่พบออก')
    expect(wrapper.find('[data-test="attendance-csv"]').attributes('disabled')).toBeUndefined()
  })

  it('shows an empty state and disables CSV when nobody was recorded', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [], meta: { total: 0 } } })

    const wrapper = mount(AttendanceView, { global: { plugins: [makeRouter()] } })
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่มีการบันทึกเวลาเข้า-ออกในวันที่เลือก')
    expect(wrapper.find('[data-test="attendance-csv"]').attributes('disabled')).toBeDefined()
  })
})

describe('Real-time alerts over the user channel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    vi.useFakeTimers()
    listen.mockClear()
    privateChannel.mockClear()
    leave.mockClear()
    echoFails = false
  })

  afterEach(() => {
    useNotificationsStore().stop()
    vi.useRealTimers()
  })

  it('subscribes to user.{uuid} and refreshes the inbox the moment a push arrives', async () => {
    const getSpy = vi
      .spyOn(api, 'get')
      .mockResolvedValue({ data: { data: [], meta: { unread_count: 0 } } })
    const store = useNotificationsStore()

    store.start('user-123')
    await flushPromises()

    expect(privateChannel).toHaveBeenCalledWith('user.user-123')
    expect(listen).toHaveBeenCalledWith('.notification.created', expect.any(Function))
    expect(store.realtime).toBe(true)

    getSpy.mockResolvedValue({
      data: {
        data: [
          {
            uuid: 'n1',
            type: 'EVENT_UNKNOWN_PERSON',
            data: { camera_name: 'ประตูหน้า' },
            read_at: null,
          },
        ],
        meta: { unread_count: 1 },
      },
    })
    const onPush = listen.mock.calls[0][1]
    onPush({ uuid: 'n1' })
    await flushPromises()

    // No waiting for the 15 s poll.
    expect(store.alerts.map((a) => a.uuid)).toEqual(['n1'])

    store.stop()
    expect(leave).toHaveBeenCalledWith('user.user-123')
  })

  it('falls back to polling when the realtime client cannot start', async () => {
    echoFails = true
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [], meta: { unread_count: 0 } } })
    const store = useNotificationsStore()

    store.start('user-123')
    await flushPromises()

    expect(store.realtime).toBe(false)
    expect(api.get).toHaveBeenCalledWith('/notifications', { params: { per_page: 20 } })
  })
})
