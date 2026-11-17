import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import router from '@/router'
import api from '@/services/api'
import facesService from '@/services/faces'
import { personsService } from '@/services/students'
import { useAuthStore } from '@/stores/auth'
import FaceScanView from '@/views/face/FaceScanView.vue'
import FaceEnrollmentView from '@/views/face/FaceEnrollmentView.vue'
import FaceQualityIndicators from '@/components/face/FaceQualityIndicators.vue'
import WebcamCapture from '@/components/face/WebcamCapture.vue'

/** Stand-in for the webcam: capture() returns a JPEG blob, quality events are driven by tests. */
const WebcamStub = defineComponent({
  name: 'WebcamCapture',
  emits: ['quality'],
  setup(_, { expose }) {
    expose({ capture: () => Promise.resolve(new Blob(['x'], { type: 'image/jpeg' })) })
    return () => h('div', { 'data-test': 'webcam-stub' })
  },
})

const GOOD_CHECK = {
  face_count: 1,
  faces: [{ x1: 10, y1: 10, x2: 100, y2: 120, confidence: 0.98 }],
  image_width: 640,
  image_height: 480,
  accepted: true,
  rejection_reasons: [],
  quality: { brightness_score: 0.8, blur_score: 0.9, face_size_score: 0.7, pose_score: 0.95 },
}

const MATCH_RESULT = {
  classification: 'MATCH',
  reasons: ['HIGH_SIMILARITY_MATCH'],
  matched: {
    similarity: 0.91,
    face_profile_uuid: 'fp-1',
    person: { uuid: 'p-1', display_name: 'สมชาย ใจดี', student_code: '65001' },
  },
  candidates: [
    {
      rank: 1,
      similarity: 0.91,
      face_profile_uuid: 'fp-1',
      person: { uuid: 'p-1', display_name: 'สมชาย ใจดี' },
    },
  ],
  liveness: { status: 'REAL', score: 0.93, is_live: true },
  thresholds: { match_threshold: 0.8, review_threshold: 0.6 },
  model: { code: 'yunet-sface', version: '2021dec' },
}

describe('Face scanning (FACE-022)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('registers the scan route behind face.test', () => {
    const route = router.getRoutes().find((r) => r.name === 'faces.scan')
    expect(route.path).toBe('/faces/scan')
    expect(route.meta.permission).toBe('face.test')
  })

  it('sends only the image to check-quality and recognize', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { success: true, data: {} } })
    const blob = new Blob(['x'], { type: 'image/jpeg' })

    await facesService.checkQuality(blob)
    await facesService.recognize(blob)

    expect(post.mock.calls[0][0]).toBe('/faces/check-quality')
    expect(post.mock.calls[1][0]).toBe('/faces/recognize')
    for (const [, body] of post.mock.calls) {
      expect([...body.keys()]).toEqual(['image'])
    }
  })

  it('quality indicators explain why a frame is not usable', () => {
    const wrapper = mount(FaceQualityIndicators, {
      props: {
        check: {
          ...GOOD_CHECK,
          accepted: false,
          rejection_reasons: ['IMAGE_TOO_DARK', 'BAD_POSE'],
        },
      },
    })
    expect(wrapper.text()).toContain('พบ 1 คน')
    expect(wrapper.text()).toContain('มืดเกินไป')
    expect(wrapper.text()).toContain('หันหน้าตรงกล้อง')
  })

  it('webcam explains when the camera API is unavailable instead of failing', async () => {
    const wrapper = mount(WebcamCapture, { props: { analyze: false } })
    await wrapper.find('[data-test="camera-start"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toMatch(/ไม่รองรับการใช้กล้อง|การเชื่อมต่อแบบปลอดภัย/)
  })

  it('scan page shows the matched person after a manual scan', async () => {
    useAuthStore().user = { id: 'u', permissions: ['face.test'] }
    const recognize = vi.spyOn(facesService, 'recognize').mockResolvedValue({ data: MATCH_RESULT })

    const wrapper = mount(FaceScanView, { global: { stubs: { WebcamCapture: WebcamStub } } })
    await wrapper.find('[data-test="scan-now"]').trigger('click')
    await flushPromises()

    expect(recognize).toHaveBeenCalledTimes(1)
    const card = wrapper.find('[data-test="scan-result"]')
    expect(card.text()).toContain('พบในระบบ')
    expect(card.text()).toContain('สมชาย ใจดี')
    expect(card.text()).toContain('65001')
    expect(card.text()).toContain('91.0%')
    expect(card.text()).toContain('ใบหน้าจริง')
  })

  it('scan page auto-scans once a good face is stable', async () => {
    useAuthStore().user = { id: 'u', permissions: ['face.test'] }
    const recognize = vi
      .spyOn(facesService, 'recognize')
      .mockResolvedValue({
        data: { ...MATCH_RESULT, classification: 'UNKNOWN', matched: null, candidates: [] },
      })

    const wrapper = mount(FaceScanView, { global: { stubs: { WebcamCapture: WebcamStub } } })
    const cam = wrapper.findComponent(WebcamStub)
    cam.vm.$emit('quality', GOOD_CHECK)
    expect(recognize).not.toHaveBeenCalled()
    cam.vm.$emit('quality', GOOD_CHECK)
    await flushPromises()

    expect(recognize).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="scan-result"]').text()).toContain('ไม่พบในระบบ')
  })

  it('scan page is blocked without face.test', () => {
    useAuthStore().user = { id: 'u', permissions: ['face.view'] }
    const wrapper = mount(FaceScanView, { global: { stubs: { WebcamCapture: WebcamStub } } })
    expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์ใช้การสแกนใบหน้า')
  })

  it('enrollment: search a student, consent, auto-capture, enroll with source CAMERA', async () => {
    useAuthStore().user = { id: 'u', permissions: ['face.enroll', 'student.view'] }
    vi.spyOn(personsService, 'list').mockResolvedValue({
      data: [
        {
          uuid: '11111111-2222-4333-8444-555555555555',
          person_type: 'STUDENT',
          student_code: '65001',
          first_name: 'สมชาย',
          last_name: 'ใจดี',
        },
      ],
    })
    const enroll = vi
      .spyOn(facesService, 'enrollFace')
      .mockResolvedValue({
        data: {
          status: 'ACTIVE',
          quality_score: 0.9,
          model: { code: 'yunet-sface', version: '2021dec' },
        },
      })
    vi.spyOn(facesService, 'checkDuplicateReview').mockResolvedValue({
      data: { duplicate_found: false },
    })
    vi.spyOn(facesService, 'verifyEnrollment').mockResolvedValue({ data: { verified: true } })
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:preview')
    globalThis.URL.revokeObjectURL = vi.fn()
    vi.useFakeTimers()

    const wrapper = mount(FaceEnrollmentView, {
      global: { stubs: { WebcamCapture: WebcamStub } },
    })

    const search = wrapper.find('[data-test="person-search"]')
    await search.setValue('สมชาย')
    vi.advanceTimersByTime(350)
    vi.useRealTimers()
    await flushPromises()
    await wrapper.find('ul button').trigger('click')
    expect(wrapper.find('[data-test="selected-person"]').text()).toContain('สมชาย ใจดี')

    await wrapper.find('[data-test="consent"]').setValue(true)
    const cam = wrapper.findComponent(WebcamStub)
    for (let i = 0; i < 3; i++) cam.vm.$emit('quality', GOOD_CHECK)
    await flushPromises()

    await wrapper.find('[data-test="enroll"]').trigger('click')
    await flushPromises()

    expect(enroll).toHaveBeenCalledTimes(1)
    const [formData, personUuid] = enroll.mock.calls[0]
    expect(personUuid).toBe('11111111-2222-4333-8444-555555555555')
    expect(formData.get('source')).toBe('CAMERA')
    expect(formData.get('pose')).toBe('FRONT')
    expect(wrapper.text()).toContain('ลงทะเบียนใบหน้าสำเร็จ')
    expect(wrapper.text()).toContain('ผ่าน')
  })
})
