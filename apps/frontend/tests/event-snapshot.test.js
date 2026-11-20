import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import EventSnapshotPanel from '@/components/events/EventSnapshotPanel.vue'
import AlertToasts from '@/components/notifications/AlertToasts.vue'
import EventDetailView from '@/views/events/EventDetailView.vue'
import FaceEnrollmentView from '@/views/face/FaceEnrollmentView.vue'

const EVENT_UUID = '11111111-2222-4333-8444-555555555555'
const IMAGE = { data: { data: { content_type: 'image/jpeg', image_base64: 'AAAA' } } }

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/events', name: 'events.index', component: { template: '<div />' } },
      { path: '/events/:uuid', name: 'events.show', component: EventDetailView },
      { path: '/faces', name: 'faces.index', component: { template: '<div />' } },
      { path: '/faces/enroll', name: 'faces.enroll', component: FaceEnrollmentView },
    ],
  })
}

function setPermissions(permissions) {
  useAuthStore().user = { uuid: 'u-1', username: 'officer', permissions }
}

function alertFor(type = 'EVENT_UNKNOWN_PERSON') {
  return {
    uuid: 'n-1',
    type,
    title: 'Unknown person detected',
    data: { event_uuid: EVENT_UUID, camera_name: 'ประตูหน้า' },
    read_at: null,
    created_at: '2026-11-20T08:00:00Z',
  }
}

describe('EVT-025: event face image', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  describe('EventSnapshotPanel', () => {
    it('loads the image only when the operator asks for it', async () => {
      setPermissions(['event.view', 'camera.live'])
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue(IMAGE)

      const wrapper = mount(EventSnapshotPanel, {
        props: { eventUuid: EVENT_UUID, available: true },
      })
      await flushPromises()
      expect(getSpy).not.toHaveBeenCalled()
      expect(wrapper.find('[data-test="event-snapshot-image"]').exists()).toBe(false)

      await wrapper.find('[data-test="event-snapshot-load"]').trigger('click')
      await flushPromises()

      expect(getSpy).toHaveBeenCalledWith(`/events/${EVENT_UUID}/snapshot`)
      const img = wrapper.find('[data-test="event-snapshot-image"]')
      expect(img.attributes('src')).toBe('data:image/jpeg;base64,AAAA')

      await wrapper.find('[data-test="event-snapshot-hide"]').trigger('click')
      expect(wrapper.find('[data-test="event-snapshot-image"]').exists()).toBe(false)
    })

    it('says so when the event has no image and never calls the API', async () => {
      setPermissions(['event.view', 'camera.live'])
      const getSpy = vi.spyOn(api, 'get')

      const wrapper = mount(EventSnapshotPanel, {
        props: { eventUuid: EVENT_UUID, available: false, autoLoad: true },
      })
      await flushPromises()

      expect(wrapper.find('[data-test="event-snapshot-none"]').exists()).toBe(true)
      expect(getSpy).not.toHaveBeenCalled()
    })

    it('hides the button without a permission that shows faces', async () => {
      setPermissions(['event.view'])
      const getSpy = vi.spyOn(api, 'get')

      const wrapper = mount(EventSnapshotPanel, {
        props: { eventUuid: EVENT_UUID, available: true, autoLoad: true },
      })
      await flushPromises()

      expect(wrapper.find('[data-test="event-snapshot-forbidden"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="event-snapshot-load"]').exists()).toBe(false)
      expect(getSpy).not.toHaveBeenCalled()
    })

    it('shows an error when the server refuses', async () => {
      setPermissions(['event.view', 'face.view'])
      vi.spyOn(api, 'get').mockRejectedValue({
        status: 404,
        code: 'EVENT_SNAPSHOT_NOT_AVAILABLE',
        message: 'x',
      })

      const wrapper = mount(EventSnapshotPanel, {
        props: { eventUuid: EVENT_UUID, available: true },
      })
      await wrapper.find('[data-test="event-snapshot-load"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-test="event-snapshot-image"]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('AAAA')
    })
  })

  describe('alert actions', () => {
    it('"ดูภาพ" marks the alert read and opens the event with the image', async () => {
      setPermissions(['event.view', 'camera.live'])
      const patchSpy = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: {} } })
      vi.spyOn(api, 'get').mockResolvedValue({
        data: { data: { uuid: EVENT_UUID, decisions: [] } },
      })
      const router = makeRouter()
      const store = useNotificationsStore()
      store.alerts = [alertFor()]

      const wrapper = mount(AlertToasts, { global: { plugins: [router] } })
      await wrapper.find('[data-test="alert-view-image"]').trigger('click')
      await flushPromises()

      expect(patchSpy).toHaveBeenCalledWith('/notifications/n-1/read')
      expect(router.currentRoute.value.name).toBe('events.show')
      expect(router.currentRoute.value.query.image).toBe('1')
    })

    it('"ลงทะเบียนใบหน้า" opens the normal enrollment page and creates nothing', async () => {
      setPermissions(['event.view', 'face.enroll'])
      const patchSpy = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: {} } })
      const postSpy = vi.spyOn(api, 'post')
      const router = makeRouter()
      useNotificationsStore().alerts = [alertFor()]

      const wrapper = mount(AlertToasts, {
        global: { plugins: [router], stubs: { FaceEnrollmentView: true } },
      })
      await wrapper.find('[data-test="alert-enroll"]').trigger('click')
      await flushPromises()

      expect(patchSpy).toHaveBeenCalledWith('/notifications/n-1/read')
      expect(postSpy).not.toHaveBeenCalled()
      expect(router.currentRoute.value.name).toBe('faces.enroll')
      expect(router.currentRoute.value.query.event).toBe(EVENT_UUID)
    })

    it('hides image and enrollment actions without the permissions', async () => {
      setPermissions(['monitor.view', 'event.view'])
      useNotificationsStore().alerts = [alertFor()]

      const wrapper = mount(AlertToasts, { global: { plugins: [makeRouter()] } })

      expect(wrapper.find('[data-test="alert-view-image"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="alert-enroll"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="alert-ignore"]').exists()).toBe(true)
    })

    it('a denied (known) person alert offers no enrollment', async () => {
      setPermissions(['event.view', 'face.enroll'])
      useNotificationsStore().alerts = [alertFor('EVENT_DENIED')]

      const wrapper = mount(AlertToasts, { global: { plugins: [makeRouter()] } })

      expect(wrapper.find('[data-test="alert-enroll"]').exists()).toBe(false)
    })
  })

  describe('event detail', () => {
    it('shows the image panel and starts enrollment for an unknown event', async () => {
      setPermissions(['event.view', 'camera.live', 'face.enroll'])
      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: {
            uuid: EVENT_UUID,
            event_type: 'UNKNOWN_PERSON',
            occurred_at: '2026-11-20T08:00:00Z',
            person: null,
            snapshot_available: true,
            current_decision: { decision: 'UNKNOWN' },
            decisions: [],
          },
        },
      })
      const router = makeRouter()
      await router.push(`/events/${EVENT_UUID}`)
      await router.isReady()

      const wrapper = mount(EventDetailView, {
        global: { plugins: [router], stubs: { FaceEnrollmentView: true } },
      })
      await flushPromises()

      expect(wrapper.find('[data-test="event-snapshot-load"]').exists()).toBe(true)
      await wrapper.find('[data-test="event-start-enrollment"]').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('faces.enroll')
      expect(router.currentRoute.value.query.event).toBe(EVENT_UUID)
    })

    it('offers no enrollment for an event with a known person', async () => {
      setPermissions(['event.view', 'face.enroll'])
      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: {
            uuid: EVENT_UUID,
            event_type: 'FACE_RECOGNIZED',
            occurred_at: '2026-11-20T08:00:00Z',
            person: { uuid: 'p-1', display_name: 'Jane' },
            snapshot_available: false,
            current_decision: { decision: 'DENY' },
            decisions: [],
          },
        },
      })
      const router = makeRouter()
      await router.push(`/events/${EVENT_UUID}`)
      await router.isReady()

      const wrapper = mount(EventDetailView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.find('[data-test="event-snapshot-none"]').exists()).toBe(true)
      expect(wrapper.find('[data-test="event-start-enrollment"]').exists()).toBe(false)
    })
  })

  describe('enrollment page opened from an event', () => {
    it('shows the event image as a reference but still requires a person and consent', async () => {
      setPermissions(['face.enroll', 'event.view', 'face.view'])
      const postSpy = vi.spyOn(api, 'post')
      const router = makeRouter()
      await router.push({ name: 'faces.enroll', query: { event: EVENT_UUID } })
      await router.isReady()

      const wrapper = mount(FaceEnrollmentView, {
        global: {
          plugins: [router],
          stubs: { WebcamCapture: true, FaceQualityIndicators: true },
        },
      })
      await flushPromises()

      const source = wrapper.find('[data-test="enroll-source-event"]')
      expect(source.exists()).toBe(true)
      expect(source.text()).toContain('ภาพอ้างอิงเท่านั้น')
      expect(source.find('[data-test="event-snapshot-load"]').exists()).toBe(true)
      expect(postSpy).not.toHaveBeenCalled()
    })

    it('ignores a malformed event parameter', async () => {
      setPermissions(['face.enroll', 'event.view', 'face.view'])
      const router = makeRouter()
      await router.push({ name: 'faces.enroll', query: { event: 'not-a-uuid' } })
      await router.isReady()

      const wrapper = mount(FaceEnrollmentView, {
        global: { plugins: [router], stubs: { WebcamCapture: true, FaceQualityIndicators: true } },
      })
      await flushPromises()

      expect(wrapper.find('[data-test="enroll-source-event"]').exists()).toBe(false)
    })
  })
})
