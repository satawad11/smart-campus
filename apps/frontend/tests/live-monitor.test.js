import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import LiveMonitorView from '@/views/monitor/LiveMonitorView.vue'

const listenMock = vi.fn().mockReturnThis()
const stopListeningMock = vi.fn().mockReturnThis()
const leaveMock = vi.fn()
const privateChannelMock = { name: '', listen: listenMock, stopListening: stopListeningMock }

let echoFailure = null

vi.mock('@/services/echo', () => ({
  getEcho: () => {
    if (echoFailure) throw echoFailure
    return {
      private: (name) => {
        privateChannelMock.name = `private-${name}`
        return privateChannelMock
      },
      leave: leaveMock,
    }
  },
  disconnectEcho: vi.fn(),
}))

describe('EVT-016: Live Monitor UI', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    listenMock.mockClear()
    stopListeningMock.mockClear()
    leaveMock.mockClear()
    echoFailure = null

    router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/monitor', name: 'monitor.live', component: LiveMonitorView }],
    })
    await router.push('/monitor')
    await router.isReady()
  })

  it('shows a permission-denied message without monitor.view', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-1', username: 'nobody', permissions: [] }

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์เข้าถึงศูนย์เฝ้าระวังนี้')
  })

  it('explains the missing site picker when camera.view is absent', async () => {
    const authStore = useAuthStore()
    authStore.user = { uuid: 'u-2', username: 'monitor-only', permissions: ['monitor.view'] }

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่มีสิทธิ์ camera.view')
  })

  it('derives sites from cameras, subscribes to the realtime channel, and loads the feed + KPIs', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      uuid: 'u-3',
      username: 'officer',
      permissions: ['monitor.view', 'camera.view', 'event.view'],
    }

    const getSpy = vi.spyOn(api, 'get').mockImplementation((url, config) => {
      if (url === '/cameras') {
        return Promise.resolve({
          data: {
            data: [
              { uuid: 'cam-1', name: 'Gate 1', site: { uuid: 'site-1', name: 'Main Campus' } },
              { uuid: 'cam-2', name: 'Gate 2', site: { uuid: 'site-1', name: 'Main Campus' } },
            ],
            meta: { pagination: { current_page: 1, per_page: 100, total: 2, last_page: 1 } },
          },
        })
      }
      if (url === '/events' && config?.params?.decision) {
        return Promise.resolve({
          data: {
            data: [],
            meta: { pagination: { current_page: 1, per_page: 1, total: 3, last_page: 1 } },
          },
        })
      }
      if (url === '/events') {
        return Promise.resolve({
          data: {
            data: [
              {
                uuid: 'evt-1',
                event_type: 'FACE_RECOGNIZED',
                occurred_at: '2026-09-23T09:00:00Z',
                person: { uuid: 'p-1', display_name: 'Jane Doe' },
                camera: { uuid: 'cam-1', name: 'Gate 1' },
                zone: null,
                current_decision: { decision: 'ALLOW', reason_code: 'STUDENT_ACTIVE' },
              },
            ],
            meta: { pagination: { current_page: 1, per_page: 50, total: 1, last_page: 1 } },
          },
        })
      }
      return Promise.resolve({ data: { data: [], meta: null } })
    })

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(getSpy).toHaveBeenCalledWith('/cameras', { params: { per_page: 100 } })
    expect(wrapper.find('select').element.value).toBe('site-1')

    // Subscribed to the site-scoped private channel and listens for pushes.
    expect(privateChannelMock.name).toBe('private-site.site-1')
    expect(listenMock).toHaveBeenCalledWith('.event.decided', expect.any(Function))

    // Historical backfill rendered.
    expect(wrapper.text()).toContain('Jane Doe')
    expect(wrapper.text()).toContain('ALLOW')

    // KPI counts loaded (mocked to 3 for every decision).
    expect(wrapper.text()).toContain('3')
  })

  it('pushes a realtime broadcast onto the feed without a page reload', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      uuid: 'u-4',
      username: 'officer2',
      permissions: ['monitor.view', 'camera.view', 'event.view'],
    }

    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/cameras') {
        return Promise.resolve({
          data: {
            data: [
              { uuid: 'cam-1', name: 'Gate 1', site: { uuid: 'site-1', name: 'Main Campus' } },
            ],
            meta: { pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 } },
          },
        })
      }
      return Promise.resolve({
        data: {
          data: [],
          meta: { pagination: { current_page: 1, per_page: 1, total: 0, last_page: 1 } },
        },
      })
    })

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    const handler = listenMock.mock.calls.find((call) => call[0] === '.event.decided')[1]
    handler({
      event: {
        uuid: 'evt-live-1',
        event_type: 'UNKNOWN_PERSON',
        occurred_at: new Date().toISOString(),
        camera_name: 'Gate 1',
        person_display_name: null,
      },
      decision: { decision: 'UNKNOWN', reason_code: null },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('ไม่ทราบตัวตน')
    expect(wrapper.text()).toContain('UNKNOWN')

    // A matched student with no display_name shows the fallback name, group, code and score.
    handler({
      event: {
        uuid: 'evt-live-2',
        event_type: 'FACE_RECOGNIZED',
        occurred_at: new Date().toISOString(),
        camera_name: 'Gate 1',
        person_display_name: null,
        person_name: 'Mali Dee',
        person_type: 'STUDENT',
        person_code: '6599999',
        face_score: 0.523,
      },
      decision: { decision: 'ALLOW', reason_code: 'STUDENT_ACTIVE' },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Mali Dee')
    const meta = wrapper.find('[data-test="person-meta"]').text()
    expect(meta).toContain('ผู้เรียน')
    expect(meta).toContain('6599999')
    expect(meta).toContain('ความเหมือน 0.52')
  })

  it('unsubscribes from the channel on unmount', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      uuid: 'u-5',
      username: 'officer3',
      permissions: ['monitor.view', 'camera.view', 'event.view'],
    }

    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/cameras') {
        return Promise.resolve({
          data: {
            data: [
              { uuid: 'cam-1', name: 'Gate 1', site: { uuid: 'site-1', name: 'Main Campus' } },
            ],
            meta: { pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 } },
          },
        })
      }
      return Promise.resolve({
        data: {
          data: [],
          meta: { pagination: { current_page: 1, per_page: 1, total: 0, last_page: 1 } },
        },
      })
    })

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    wrapper.unmount()

    expect(stopListeningMock).toHaveBeenCalledWith('.event.decided')
    expect(leaveMock).toHaveBeenCalledWith('site.site-1')
  })
  it('shows a live frame for the selected camera only with camera.live', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      uuid: 'u-5',
      username: 'officer',
      permissions: ['monitor.view', 'camera.view', 'camera.live'],
    }

    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/cameras') {
        return Promise.resolve({
          data: {
            data: [{ uuid: 'cam-1', name: 'Gate 1', site: { uuid: 'site-1', name: 'Main' } }],
            meta: { pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 } },
          },
        })
      }
      return Promise.resolve({ data: { data: [], meta: null } })
    })
    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
      data: { data: { content_type: 'image/jpeg', image_base64: 'AAAA', source: 'ffmpeg' } },
    })

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(postSpy).not.toHaveBeenCalled()
    const cameraSelect = wrapper
      .findAll('select')
      .find((s) => s.findAll('option').some((o) => o.element.value === 'cam-1'))
    await cameraSelect.setValue('cam-1')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith('/cameras/cam-1/snapshot')
    expect(wrapper.find('[data-testid="live-frame"]').attributes('src')).toBe(
      'data:image/jpeg;base64,AAAA',
    )
    wrapper.unmount()
  })

  it('still lists sites and cameras when the realtime client cannot start', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      uuid: 'u-9',
      username: 'officer9',
      permissions: ['monitor.view', 'camera.view', 'event.view'],
    }
    echoFailure = new Error('VITE_REVERB_APP_KEY is not set')
    vi.spyOn(console, 'error').mockImplementation(() => {})

    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url === '/cameras') {
        return Promise.resolve({
          data: {
            data: [
              { uuid: 'cam-1', name: 'Gate 1', site: { uuid: 'site-1', name: 'Main Campus' } },
            ],
            meta: null,
          },
        })
      }
      return Promise.resolve({ data: { data: [], meta: null } })
    })

    const wrapper = mount(LiveMonitorView, { global: { plugins: [router] } })
    await flushPromises()
    await flushPromises()

    expect(wrapper.find('select').element.value).toBe('site-1')
    expect(wrapper.text()).toContain('Main Campus')
    expect(wrapper.text()).not.toContain('กำลังโหลด...')
    expect(wrapper.find('[data-test="realtime-error"]').exists()).toBe(true)
  })
})
