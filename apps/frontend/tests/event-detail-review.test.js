import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import EventListView from '@/views/events/EventListView.vue'
import EventDetailView from '@/views/events/EventDetailView.vue'

describe('EVT-017: Event detail/review UI', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/events', name: 'events.index', component: EventListView },
        { path: '/events/:uuid', name: 'events.show', component: EventDetailView },
      ],
    })
    await router.push('/events')
    await router.isReady()
  })

  describe('EventListView', () => {
    it('shows a permission-denied message without event.view', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-1', username: 'nobody', permissions: [] }

      const wrapper = mount(EventListView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์เข้าถึงประวัติเหตุการณ์นี้')
    })

    it('lists events and links each row to its detail page', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-2', username: 'officer', permissions: ['event.view'] }

      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [
            {
              uuid: 'evt-1',
              event_type: 'FACE_RECOGNIZED',
              occurred_at: '2026-09-23T09:00:00Z',
              person: { uuid: 'p-1', display_name: 'Jane Doe' },
              site: { uuid: 's-1', name: 'Main Campus' },
              camera: { uuid: 'c-1', name: 'Gate 1' },
              current_decision: { decision: 'ALLOW' },
            },
          ],
          meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
        },
      })

      const wrapper = mount(EventListView, { global: { plugins: [router] } })
      await flushPromises()

      expect(getSpy).toHaveBeenCalledWith('/events', {
        params: {
          decision: undefined,
          event_type: undefined,
          from: undefined,
          to: undefined,
          page: 1,
          per_page: 20,
        },
      })
      expect(wrapper.text()).toContain('Jane Doe')
      expect(wrapper.text()).toContain('ALLOW')

      await wrapper.find('tbody tr').trigger('click')
      await flushPromises()
      expect(router.currentRoute.value.name).toBe('events.show')
      expect(router.currentRoute.value.params.uuid).toBe('evt-1')
    })

    it('the review-queue button filters to decision=REVIEW', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-3', username: 'officer2', permissions: ['event.view'] }

      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [],
          meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
        },
      })

      const wrapper = mount(EventListView, { global: { plugins: [router] } })
      await flushPromises()

      await wrapper.find('button').trigger('click') // "ดูคิวรอตรวจสอบ" is the first button
      await flushPromises()

      expect(getSpy).toHaveBeenLastCalledWith('/events', {
        params: {
          decision: 'REVIEW',
          event_type: undefined,
          from: undefined,
          to: undefined,
          page: 1,
          per_page: 20,
        },
      })
      expect(wrapper.text()).toContain('คิวรอตรวจสอบ')
    })
  })

  describe('EventDetailView', () => {
    async function mountDetail(decisions, overrides = {}) {
      await router.push('/events/evt-1')
      await router.isReady()

      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: {
            uuid: 'evt-1',
            event_type: 'UNKNOWN_PERSON',
            occurred_at: '2026-09-23T09:00:00Z',
            person: null,
            site: { uuid: 's-1', name: 'Main Campus' },
            zone: null,
            camera: { uuid: 'c-1', name: 'Gate 1' },
            face_score: null,
            liveness_score: null,
            current_decision: decisions[decisions.length - 1],
            decisions,
            ...overrides,
          },
        },
      })

      const wrapper = mount(EventDetailView, { global: { plugins: [router] } })
      await flushPromises()
      return wrapper
    }

    it('shows a permission-denied message without event.view', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-4', username: 'nobody', permissions: [] }

      await router.push('/events/evt-1')
      await router.isReady()
      const wrapper = mount(EventDetailView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์เข้าถึงรายละเอียดเหตุการณ์นี้')
    })

    it('renders the full decision timeline, most-original-first', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-5', username: 'officer3', permissions: ['event.view'] }

      const wrapper = await mountDetail([
        {
          uuid: 'd-1',
          decision: 'REVIEW',
          reason_code: 'NO_MATCHING_POLICY',
          decision_source: 'SYSTEM',
          created_at: '2026-09-23T09:00:00Z',
        },
        {
          uuid: 'd-2',
          decision: 'ALLOW',
          reason_code: 'OFFICER_OVERRIDE',
          decision_source: 'OFFICER',
          decided_by: { username: 'admin' },
          created_at: '2026-09-23T09:05:00Z',
        },
      ])

      expect(wrapper.text()).toContain('ประวัติการตัดสินใจ (2)')
      expect(wrapper.text()).toContain('NO_MATCHING_POLICY')
      expect(wrapper.text()).toContain('OFFICER_OVERRIDE')
      expect(wrapper.text()).toContain('admin')
    })

    it('shows a REVIEW candidate as a suggestion without assigning the event person', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-review', username: 'reviewer', permissions: ['event.view'] }

      const wrapper = await mountDetail(
        [
          {
            uuid: 'd-review',
            decision: 'REVIEW',
            reason_code: 'FACE_REVIEW_REQUIRED',
            decision_source: 'SYSTEM',
            created_at: '2026-09-23T09:00:00Z',
          },
        ],
        {
          person: null,
          face_score: 0.72,
          review_candidate: {
            uuid: 'person-review',
            name: 'เด็กหญิง ทดสอบ',
            student_code: 'ST-001',
          },
        },
      )

      expect(wrapper.text()).toContain('ไม่ทราบตัวตน')
      expect(wrapper.text()).toContain('ผู้สมัครที่ระบบเสนอให้ตรวจสอบ: เด็กหญิง ทดสอบ')
      expect(wrapper.text()).toContain('รหัสนักเรียน ST-001')
      expect(wrapper.text()).toContain('0.72')
    })

    describe('EVT-026: recognition reasons', () => {
      const systemDecision = [
        {
          uuid: 'd-1',
          decision: 'UNKNOWN',
          reason_code: 'RECOGNITION_UNKNOWN',
          decision_source: 'POLICY',
          created_at: '2026-09-23T09:00:00Z',
        },
      ]

      it('explains that a node-rejected face was never compared, in Thai, with the raw code', async () => {
        const authStore = useAuthStore()
        authStore.user = { uuid: 'u-r1', username: 'officer', permissions: ['event.view'] }

        const wrapper = await mountDetail(systemDecision, {
          recognition: {
            classification: 'UNKNOWN',
            rejected_locally: true,
            reasons: ['BAD_POSE', 'IMAGE_BLUR'],
          },
        })

        const section = wrapper.find('[data-testid="recognition-reasons"]')
        expect(section.exists()).toBe(true)
        expect(wrapper.find('[data-testid="rejected-locally-note"]').text()).toContain(
          'ไม่ได้นำไปเปรียบเทียบ',
        )
        expect(section.text()).toContain('มุมใบหน้าไม่ตรง')
        expect(section.text()).toContain('BAD_POSE')
        expect(section.text()).toContain('ภาพเบลอ')
        expect(section.text()).toContain('IMAGE_BLUR')
      })

      it('shows matching reasons without the "never compared" note', async () => {
        const authStore = useAuthStore()
        authStore.user = { uuid: 'u-r2', username: 'officer', permissions: ['event.view'] }

        const wrapper = await mountDetail(systemDecision, {
          face_score: 0.41,
          recognition: {
            classification: 'UNKNOWN',
            rejected_locally: false,
            reasons: ['NO_CANDIDATE_ABOVE_THRESHOLD'],
          },
        })

        expect(wrapper.find('[data-testid="rejected-locally-note"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="recognition-reasons"]').text()).toContain(
          'เปรียบเทียบแล้ว ไม่มีผู้ใดคล้ายถึงเกณฑ์',
        )
      })

      it('shows an unrecognised code as sent and hides the section when there is nothing to show', async () => {
        const authStore = useAuthStore()
        authStore.user = { uuid: 'u-r3', username: 'officer', permissions: ['event.view'] }

        const withUnknown = await mountDetail(systemDecision, {
          recognition: {
            classification: 'UNKNOWN',
            rejected_locally: false,
            reasons: ['SOMETHING_NEW'],
          },
        })
        expect(withUnknown.find('[data-testid="recognition-reasons"]').text()).toContain(
          'SOMETHING_NEW',
        )

        const empty = await mountDetail(systemDecision, {
          recognition: { classification: null, rejected_locally: null, reasons: [] },
        })
        expect(empty.find('[data-testid="recognition-reasons"]').exists()).toBe(false)

        const legacy = await mountDetail(systemDecision) // older API response: no recognition key
        expect(legacy.find('[data-testid="recognition-reasons"]').exists()).toBe(false)
      })
    })

    it('hides the review form without event.review', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-6', username: 'viewer', permissions: ['event.view'] }

      const wrapper = await mountDetail([
        {
          uuid: 'd-1',
          decision: 'REVIEW',
          reason_code: 'NO_MATCHING_POLICY',
          decision_source: 'SYSTEM',
          created_at: '2026-09-23T09:00:00Z',
        },
      ])

      expect(wrapper.text()).not.toContain('ตรวจสอบโดยเจ้าหน้าที่')
    })

    it('submits a review with the optimistic-concurrency token and refreshes the timeline', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'u-7',
        username: 'officer4',
        permissions: ['event.view', 'event.review'],
      }

      const wrapper = await mountDetail([
        {
          uuid: 'd-1',
          decision: 'REVIEW',
          reason_code: 'NO_MATCHING_POLICY',
          decision_source: 'SYSTEM',
          created_at: '2026-09-23T09:00:00Z',
        },
      ])

      const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
        data: { data: { uuid: 'd-2', decision: 'ALLOW' } },
      })
      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: {
            uuid: 'evt-1',
            event_type: 'UNKNOWN_PERSON',
            occurred_at: '2026-09-23T09:00:00Z',
            person: null,
            site: { uuid: 's-1', name: 'Main Campus' },
            zone: null,
            camera: { uuid: 'c-1', name: 'Gate 1' },
            current_decision: {
              uuid: 'd-2',
              decision: 'ALLOW',
              reason_code: 'OFFICER_OVERRIDE',
              decision_source: 'OFFICER',
              created_at: '2026-09-23T09:05:00Z',
            },
            decisions: [
              {
                uuid: 'd-1',
                decision: 'REVIEW',
                reason_code: 'NO_MATCHING_POLICY',
                decision_source: 'SYSTEM',
                created_at: '2026-09-23T09:00:00Z',
              },
              {
                uuid: 'd-2',
                decision: 'ALLOW',
                reason_code: 'OFFICER_OVERRIDE',
                decision_source: 'OFFICER',
                created_at: '2026-09-23T09:05:00Z',
              },
            ],
          },
        },
      })

      await wrapper.find('input[type="text"]').setValue('OFFICER_OVERRIDE')
      await wrapper
        .findAll('button')
        .find((b) => b.text().includes('ยืนยันผลการตรวจสอบ'))
        .trigger('click')
      await flushPromises()

      expect(postSpy).toHaveBeenCalledWith('/events/evt-1/review', {
        decision: 'ALLOW',
        reason_code: 'OFFICER_OVERRIDE',
        reason_text: undefined,
        expected_decision_uuid: 'd-1',
      })
      expect(wrapper.text()).toContain('บันทึกผลการตรวจสอบเรียบร้อยแล้ว')
      expect(wrapper.text()).toContain('ประวัติการตัดสินใจ (2)')
    })

    it('requires a reason_code before submitting', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'u-8',
        username: 'officer5',
        permissions: ['event.view', 'event.review'],
      }

      const wrapper = await mountDetail([
        {
          uuid: 'd-1',
          decision: 'REVIEW',
          reason_code: 'NO_MATCHING_POLICY',
          decision_source: 'SYSTEM',
          created_at: '2026-09-23T09:00:00Z',
        },
      ])

      const postSpy = vi.spyOn(api, 'post')
      await wrapper
        .findAll('button')
        .find((b) => b.text().includes('ยืนยันผลการตรวจสอบ'))
        .trigger('click')
      await flushPromises()

      expect(postSpy).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('กรุณาระบุรหัสเหตุผล')
    })

    it('handles a 409 conflict by reloading the event instead of leaving stale state', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'u-9',
        username: 'officer6',
        permissions: ['event.view', 'event.review'],
      }

      const wrapper = await mountDetail([
        {
          uuid: 'd-1',
          decision: 'REVIEW',
          reason_code: 'NO_MATCHING_POLICY',
          decision_source: 'SYSTEM',
          created_at: '2026-09-23T09:00:00Z',
        },
      ])

      vi.spyOn(api, 'post').mockRejectedValue({
        status: 409,
        code: 'EVENT_REVIEW_CONFLICT',
        message:
          'This event was already reviewed by someone else since you loaded it. Refresh and try again.',
      })
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: {
            uuid: 'evt-1',
            event_type: 'UNKNOWN_PERSON',
            occurred_at: '2026-09-23T09:00:00Z',
            person: null,
            site: { uuid: 's-1', name: 'Main Campus' },
            zone: null,
            camera: { uuid: 'c-1', name: 'Gate 1' },
            current_decision: {
              uuid: 'd-2',
              decision: 'DENY',
              reason_code: 'IDENTITY_MISMATCH',
              decision_source: 'OFFICER',
              created_at: '2026-09-23T09:04:00Z',
            },
            decisions: [
              {
                uuid: 'd-1',
                decision: 'REVIEW',
                reason_code: 'NO_MATCHING_POLICY',
                decision_source: 'SYSTEM',
                created_at: '2026-09-23T09:00:00Z',
              },
              {
                uuid: 'd-2',
                decision: 'DENY',
                reason_code: 'IDENTITY_MISMATCH',
                decision_source: 'OFFICER',
                created_at: '2026-09-23T09:04:00Z',
              },
            ],
          },
        },
      })

      await wrapper.find('input[type="text"]').setValue('OFFICER_OVERRIDE')
      await wrapper
        .findAll('button')
        .find((b) => b.text().includes('ยืนยันผลการตรวจสอบ'))
        .trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('ถูกตรวจสอบโดยผู้อื่นไปแล้ว')
      expect(getSpy).toHaveBeenCalled()
      expect(wrapper.text()).toContain('DENY')
    })
  })
})
