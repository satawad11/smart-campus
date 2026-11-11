import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import CameraCreateView from '@/views/cameras/CameraCreateView.vue'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

const OPTIONS = {
  data: {
    sites: [
      {
        uuid: 'site-1',
        code: 'MAIN',
        name: 'พื้นที่หลัก',
        zones: [{ uuid: 'zone-1', code: 'GATE', name: 'ประตูหน้า' }],
      },
    ],
    suggested_camera_code: 'CAM-0003',
    can_provision_default_location: true,
  },
}

function buttonByText(wrapper, text) {
  return wrapper.findAll('button').find((b) => b.text() === text)
}

describe('Add camera: auto fields and pre-save preview', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    push.mockReset()
    vi.spyOn(aiNodesService, 'listNodes').mockResolvedValue({ data: [] })
  })

  it('prefills site, zone and camera code — no UUIDs to type', async () => {
    vi.spyOn(camerasService, 'getFormOptions').mockResolvedValue(OPTIONS)
    const wrapper = mount(CameraCreateView)
    await flushPromises()

    expect(wrapper.find('[data-testid="site-select"]').element.value).toBe('site-1')
    expect(wrapper.find('[data-testid="zone-select"]').element.value).toBe('zone-1')
    expect(wrapper.find('[data-testid="camera-code"]').element.value).toBe('CAM-0003')
    expect(wrapper.text()).not.toContain('UUID')
  })

  it('explains auto-provisioning when no site exists and sends blanks', async () => {
    vi.spyOn(camerasService, 'getFormOptions').mockResolvedValue({
      data: { sites: [], suggested_camera_code: 'CAM-0001', can_provision_default_location: true },
    })
    const create = vi
      .spyOn(camerasService, 'createCamera')
      .mockResolvedValue({ data: { uuid: 'cam-1' } })
    const wrapper = mount(CameraCreateView)
    await flushPromises()

    expect(wrapper.find('[data-testid="auto-location-note"]').exists()).toBe(true)

    await wrapper.find('input[placeholder="เช่น ประตูหน้า"]').setValue('ประตูหน้า')
    for (let i = 0; i < 3; i++) {
      await buttonByText(wrapper, 'ถัดไป').trigger('click')
    }
    await buttonByText(wrapper, 'บันทึกกล้อง').trigger('click')
    await flushPromises()

    const payload = create.mock.calls[0][0]
    expect(payload.site_uuid).toBeNull()
    expect(payload.zone_uuid).toBeNull()
    expect(payload.camera_code).toBe('CAM-0001')
    expect(push).toHaveBeenCalledWith({ name: 'cameras.show', params: { uuid: 'cam-1' } })
  })

  it('requires a camera name before moving on', async () => {
    vi.spyOn(camerasService, 'getFormOptions').mockResolvedValue(OPTIONS)
    const wrapper = mount(CameraCreateView)
    await flushPromises()

    await buttonByText(wrapper, 'ถัดไป').trigger('click')
    expect(wrapper.find('[data-testid="step-error"]').text()).toContain('ชื่อกล้อง')
  })

  it('shows the camera image on the review step before saving', async () => {
    vi.spyOn(camerasService, 'getFormOptions').mockResolvedValue(OPTIONS)
    const previewStream = vi.spyOn(camerasService, 'previewStream').mockResolvedValue({
      data: { content_type: 'image/jpeg', image_base64: 'AAAA', source: 'CAMERA' },
    })
    const create = vi.spyOn(camerasService, 'createCamera')
    const wrapper = mount(CameraCreateView)
    await flushPromises()

    await wrapper.find('input[placeholder="เช่น ประตูหน้า"]').setValue('Gate')
    await buttonByText(wrapper, 'ถัดไป').trigger('click')
    await wrapper
      .find('input[placeholder="rtsp://camera-host:554/stream1"]')
      .setValue('rtsp://10.0.0.5/s1')
    await buttonByText(wrapper, 'ถัดไป').trigger('click')
    await buttonByText(wrapper, 'ถัดไป').trigger('click')
    await flushPromises()

    expect(previewStream).toHaveBeenCalledWith({
      rtsp_url: 'rtsp://10.0.0.5/s1',
      username: null,
      password: null,
    })
    const img = wrapper.find('[data-testid="review-preview-image"]')
    expect(img.attributes('src')).toBe('data:image/jpeg;base64,AAAA')
    expect(create).not.toHaveBeenCalled()
  })

  it('shows a Thai reason when the preview fails', async () => {
    vi.spyOn(camerasService, 'getFormOptions').mockResolvedValue(OPTIONS)
    vi.spyOn(camerasService, 'previewStream').mockRejectedValue({ code: 'CAMERA_AUTH_FAILED' })
    const wrapper = mount(CameraCreateView)
    await flushPromises()

    await wrapper.find('input[placeholder="เช่น ประตูหน้า"]').setValue('Gate')
    await buttonByText(wrapper, 'ถัดไป').trigger('click')
    await wrapper
      .find('input[placeholder="rtsp://camera-host:554/stream1"]')
      .setValue('rtsp://10.0.0.5/s1')
    await wrapper.find('[data-testid="preview-button"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="preview-error"]').text()).toContain(
      'รหัสผ่านกล้องไม่ถูกต้อง',
    )
  })
})
