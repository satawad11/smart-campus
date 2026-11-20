import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import facesService from '@/services/faces'
import api from '@/services/api'
import FaceEnrollmentView from '@/views/face/FaceEnrollmentView.vue'
import FaceProfileListView from '@/views/face/FaceProfileListView.vue'
import RecognitionTestView from '@/views/face/RecognitionTestView.vue'
import { useAuthStore } from '@/stores/auth'

describe('Face Biometrics UI (FACE-016, FACE-017, FACE-018)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('registers face biometrics routes in Vue router', () => {
    const routeNames = router.getRoutes().map((r) => r.name)
    expect(routeNames).toContain('faces.index')
    expect(routeNames).toContain('faces.enroll')
    expect(routeNames).toContain('faces.test')
    expect(routeNames).toContain('faces.show')
  })

  it('facesService formats requests correctly without exposing embeddings or numeric ids', async () => {
    const getSpy = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [] } })
    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: { success: true } })

    await facesService.listProfiles({ status: 'ACTIVE' })
    expect(getSpy).toHaveBeenCalledWith('/faces', { params: { status: 'ACTIVE' } })

    await facesService.getProfile('profile-uuid-123')
    expect(getSpy).toHaveBeenCalledWith('/faces/profile-uuid-123')

    await facesService.revokeProfile('profile-uuid-123', 'Biometric consent withdrawn')
    expect(postSpy).toHaveBeenCalledWith('/faces/profile-uuid-123/revoke', {
      reason: 'Biometric consent withdrawn',
    })
  })

  it('renders FaceEnrollmentView with required fields and permission checks', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 'user-uuid', permissions: ['face.enroll'] }

    const wrapper = mount(FaceEnrollmentView, {
      global: {
        plugins: [router],
      },
    })

    expect(wrapper.text()).toContain('ลงทะเบียนใบหน้า')
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('บุคคลและภาพตัวอย่าง')
  })

  it('renders FaceProfileListView with status filter and table headers', async () => {
    const authStore = useAuthStore()
    authStore.user = {
      id: 'user-uuid',
      permissions: ['face.view', 'face.revoke', 'face.reenroll', 'face.enroll'],
    }

    vi.spyOn(api, 'get').mockResolvedValue({
      data: {
        data: [
          {
            face_profile_uuid: 'prof-1',
            person_uuid: 'pers-1',
            person: { uuid: 'pers-1', name: 'สมชาย ใจดี', student_code: '650001' },
            status: 'ACTIVE',
            quality_score: 0.95,
            enrolled_at: '2026-09-20T12:00:00Z',
            model: { code: 'facenet512', version: '1.0.0' },
          },
        ],
        meta: { current_page: 1, last_page: 1, total: 1 },
      },
    })

    const wrapper = mount(FaceProfileListView, {
      global: {
        plugins: [router],
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('ข้อมูลใบหน้า')
    expect(wrapper.text()).toContain('สมชาย ใจดี')
    expect(wrapper.text()).toContain('650001')
    expect(wrapper.find('select').exists()).toBe(true)
  })

  it('renders RecognitionTestView with probe parameters and diagnostic controls', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 'user-uuid', permissions: ['face.test'] }

    const wrapper = mount(RecognitionTestView, {
      global: {
        plugins: [router],
      },
    })

    expect(wrapper.text()).toContain('ทดสอบการจดจำใบหน้า')
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect(wrapper.find('input[type="number"]').exists()).toBe(true)
  })

  it('maps the API response and filters candidates by the displayed similarity minimum', async () => {
    const authStore = useAuthStore()
    authStore.user = { id: 'user-uuid', permissions: ['face.test'] }

    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: vi.fn(() => 'blob:probe'),
    })
    const runTest = vi.spyOn(facesService, 'runRecognitionTest').mockResolvedValue({
      data: {
        model: { code: 'yunet-sface', version: '2021dec' },
        quality_score: 0.91,
        candidate_count: 2,
        candidates: [
          {
            candidate_person_uuid: 'person-match-uuid',
            candidate_face_profile_uuid: 'profile-match-uuid',
            rank: 1,
            similarity: 1,
            distance: 0,
          },
          {
            candidate_person_uuid: 'person-below-threshold-uuid',
            candidate_face_profile_uuid: 'profile-below-threshold-uuid',
            rank: 2,
            similarity: 0.1274,
            distance: 0.8726,
          },
        ],
      },
    })

    const wrapper = mount(RecognitionTestView, { global: { plugins: [router] } })
    const fileInput = wrapper.find('input[type="file"]')
    Object.defineProperty(fileInput.element, 'files', {
      configurable: true,
      value: [new File(['face-sample'], 'probe.jpg', { type: 'image/jpeg' })],
    })
    await fileInput.trigger('change')
    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(runTest).toHaveBeenCalledOnce()
    expect(Array.from(runTest.mock.calls[0][0].keys())).toEqual(['image', 'top_k'])
    expect(wrapper.text()).toContain('yunet-sface / 2021dec')
    expect(wrapper.text()).toContain('91.0%')
    expect(wrapper.text()).toContain('บุคคล: person-match-uuid')
    expect(wrapper.text()).toContain('โปรไฟล์: profile-match-uuid')
    expect(wrapper.text()).not.toContain('person-below-threshold-uuid')
    expect(wrapper.text()).toContain('100.00%')
    expect(wrapper.text()).not.toContain('NaN')
  })
})
