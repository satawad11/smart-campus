import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { useAuthStore } from '@/stores/auth'
import CameraDetailView from '@/views/cameras/CameraDetailView.vue'
import CameraLiveView from '@/components/cameras/CameraLiveView.vue'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: { uuid: 'cam-1' } }),
}))

const CAMERA = {
  uuid: 'cam-1',
  camera_code: 'CAM-0001',
  name: 'd',
  camera_type: null,
  ip_address: '10.10.30.101',
  stream_protocol: 'rtsp',
  onvif_url: null,
  has_rtsp_url: true,
  has_credentials: true,
  face_detection_enabled: true,
  face_recognition_enabled: true,
  liveness_enabled: false,
  snapshot_enabled: true,
  roi: null,
  status: 'ACTIVE',
  site: { uuid: 'site-1', code: 'MAIN', name: 'Smart Campus' },
  zone: { uuid: 'zone-1', code: 'GATE', name: 'ประตูหลัก' },
  ai_node: { uuid: 'node-1', node_code: 'NODE-1', name: 'ประตู' },
}

const SNAPSHOT = { data: { content_type: 'image/jpeg', image_base64: 'AAAA', source: 'ffmpeg' } }

function setPermissions(permissions) {
  const auth = useAuthStore()
  auth.user = { uuid: 'u-1', username: 'admin', permissions }
}

function buttonByText(wrapper, text) {
  return wrapper.findAll('button').find((b) => b.text() === text)
}

function tabButton(wrapper, label) {
  return wrapper.findAll('button').find((b) => b.text() === label)
}

describe('Camera detail: edit after installation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    vi.spyOn(camerasService, 'getCamera').mockResolvedValue({ data: { ...CAMERA } })
    vi.spyOn(aiNodesService, 'listNodes').mockResolvedValue({
      data: [
        { uuid: 'node-1', node_code: 'NODE-1', name: 'ประตู' },
        { uuid: 'node-2', node_code: 'NODE-2', name: 'อาคาร 2' },
      ],
    })
  })

  it('shows the edit button only with camera.update', async () => {
    setPermissions(['camera.view'])
    const wrapper = mount(CameraDetailView)
    await flushPromises()
    expect(buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง')).toBeUndefined()

    setPermissions(['camera.view', 'camera.update'])
    const wrapper2 = mount(CameraDetailView)
    await flushPromises()
    expect(buttonByText(wrapper2, 'แก้ไขข้อมูลกล้อง')).toBeDefined()
  })

  it('sends edited fields and omits blank secrets (keeps existing credentials)', async () => {
    setPermissions(['camera.view', 'camera.update'])
    const update = vi
      .spyOn(camerasService, 'updateCamera')
      .mockResolvedValue({ data: { ...CAMERA, name: 'Front gate', ip_address: '10.10.30.102' } })
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง').trigger('click')
    await flushPromises()
    const form = wrapper.find('[data-testid="camera-edit-form"]')
    expect(form.exists()).toBe(true)
    expect(form.find('input[name="name"]').element.value).toBe('d')

    await form.find('input[name="name"]').setValue('Front gate')
    await form.find('input[name="ip_address"]').setValue('10.10.30.102')
    await form.find('select[name="ai_node_uuid"]').setValue('node-2')
    await form.trigger('submit')
    await flushPromises()

    expect(update).toHaveBeenCalledTimes(1)
    const [uuid, payload] = update.mock.calls[0]
    expect(uuid).toBe('cam-1')
    expect(payload).toMatchObject({
      name: 'Front gate',
      ip_address: '10.10.30.102',
      ai_node_uuid: 'node-2',
    })
    expect(payload).not.toHaveProperty('rtsp_url')
    expect(payload).not.toHaveProperty('username')
    expect(payload).not.toHaveProperty('password')
    // Immutable keys are never sent.
    expect(payload).not.toHaveProperty('camera_code')
    expect(payload).not.toHaveProperty('site_uuid')
    expect(payload).not.toHaveProperty('zone_uuid')
    expect(payload).not.toHaveProperty('status')

    expect(wrapper.find('[data-testid="camera-edit-form"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('10.10.30.102')
  })

  it('replaces the RTSP URL and credentials when typed, or clears credentials explicitly', async () => {
    setPermissions(['camera.view', 'camera.update'])
    const update = vi.spyOn(camerasService, 'updateCamera').mockResolvedValue({ data: CAMERA })
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง').trigger('click')
    let form = wrapper.find('[data-testid="camera-edit-form"]')
    await form.find('input[name="rtsp_url"]').setValue('rtsp://10.10.30.101:554/stream2')
    await form.find('input[name="username"]').setValue('viewer')
    await form.find('input[name="password"]').setValue('s3cret')
    await form.trigger('submit')
    await flushPromises()
    expect(update.mock.calls[0][1]).toMatchObject({
      rtsp_url: 'rtsp://10.10.30.101:554/stream2',
      username: 'viewer',
      password: 's3cret',
    })

    await buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง').trigger('click')
    form = wrapper.find('[data-testid="camera-edit-form"]')
    await form.find('input[name="clear_credentials"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()
    expect(update.mock.calls[1][1]).toMatchObject({ username: null, password: null })
  })

  it('shows validation errors from the server and keeps the form open', async () => {
    setPermissions(['camera.view', 'camera.update'])
    vi.spyOn(camerasService, 'updateCamera').mockRejectedValue({
      status: 422,
      code: 'VALIDATION_ERROR',
      fields: { ip_address: ['ที่อยู่ไอพีไม่ถูกต้อง'] },
    })
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง').trigger('click')
    const form = wrapper.find('[data-testid="camera-edit-form"]')
    await form.find('input[name="ip_address"]').setValue('not-an-ip')
    await form.trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-testid="camera-edit-form"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('ที่อยู่ไอพีไม่ถูกต้อง')
  })

  it('requires a name before calling the API', async () => {
    setPermissions(['camera.view', 'camera.update'])
    const update = vi.spyOn(camerasService, 'updateCamera')
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await buttonByText(wrapper, 'แก้ไขข้อมูลกล้อง').trigger('click')
    const form = wrapper.find('[data-testid="camera-edit-form"]')
    await form.find('input[name="name"]').setValue('   ')
    await form.trigger('submit')
    await flushPromises()

    expect(update).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('กรุณาระบุชื่อกล้อง')
  })
})

describe('Camera detail: live view tab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    vi.spyOn(camerasService, 'getCamera').mockResolvedValue({ data: { ...CAMERA } })
  })

  it('shows a live frame with camera.live', async () => {
    setPermissions(['camera.view', 'camera.live'])
    const snap = vi.spyOn(camerasService, 'captureSnapshot').mockResolvedValue(SNAPSHOT)
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await tabButton(wrapper, 'ภาพตัวอย่าง').trigger('click')
    await flushPromises()

    expect(snap).toHaveBeenCalledWith('cam-1')
    expect(wrapper.find('[data-testid="live-frame"]').attributes('src')).toBe(
      'data:image/jpeg;base64,AAAA',
    )
    wrapper.unmount()
  })

  it('never requests frames without camera.live', async () => {
    setPermissions(['camera.view'])
    const snap = vi.spyOn(camerasService, 'captureSnapshot')
    const wrapper = mount(CameraDetailView)
    await flushPromises()

    await tabButton(wrapper, 'ภาพตัวอย่าง').trigger('click')
    await flushPromises()

    expect(snap).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('ไม่มีสิทธิ์ดูภาพสดจากกล้อง')
  })
})

describe('CameraLiveView refresh loop', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('refreshes frames sequentially and stops on unmount', async () => {
    const snap = vi.spyOn(camerasService, 'captureSnapshot').mockResolvedValue(SNAPSHOT)
    const wrapper = mount(CameraLiveView, { props: { cameraUuid: 'cam-1', intervalMs: 1000 } })

    await vi.advanceTimersByTimeAsync(0)
    expect(snap).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1000)
    expect(snap).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1000)
    expect(snap).toHaveBeenCalledTimes(3)

    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(5000)
    expect(snap).toHaveBeenCalledTimes(3)
  })

  it('stops after repeated failures and shows the reason', async () => {
    const snap = vi
      .spyOn(camerasService, 'captureSnapshot')
      .mockRejectedValue({ status: 422, code: 'CAMERA_UNREACHABLE' })
    const wrapper = mount(CameraLiveView, { props: { cameraUuid: 'cam-1', intervalMs: 1000 } })

    await vi.advanceTimersByTimeAsync(10000)
    expect(snap).toHaveBeenCalledTimes(3)
    expect(wrapper.text()).toContain('เชื่อมต่อกล้องไม่ได้')
    expect(buttonByText(wrapper, 'ดูภาพสด')).toBeDefined()
    wrapper.unmount()
  })

  it('stops on 403 without retrying', async () => {
    const snap = vi
      .spyOn(camerasService, 'captureSnapshot')
      .mockRejectedValue({ status: 403, code: 'PERMISSION_DENIED' })
    const wrapper = mount(CameraLiveView, { props: { cameraUuid: 'cam-1', intervalMs: 1000 } })

    await vi.advanceTimersByTimeAsync(10000)
    expect(snap).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('auto-stops after the maximum viewing time', async () => {
    const snap = vi.spyOn(camerasService, 'captureSnapshot').mockResolvedValue(SNAPSHOT)
    const wrapper = mount(CameraLiveView, {
      props: { cameraUuid: 'cam-1', intervalMs: 1000, maxDurationMs: 3000 },
    })

    await vi.advanceTimersByTimeAsync(10000)
    const calls = snap.mock.calls.length
    expect(calls).toBeLessThanOrEqual(4)
    expect(wrapper.text()).toContain('หยุดดูภาพสดอัตโนมัติ')
    await vi.advanceTimersByTimeAsync(10000)
    expect(snap.mock.calls.length).toBe(calls)
    wrapper.unmount()
  })

  it('restarts against the new camera when the selection changes', async () => {
    const snap = vi.spyOn(camerasService, 'captureSnapshot').mockResolvedValue(SNAPSHOT)
    const wrapper = mount(CameraLiveView, { props: { cameraUuid: 'cam-1', intervalMs: 1000 } })
    await vi.advanceTimersByTimeAsync(0)
    await wrapper.setProps({ cameraUuid: 'cam-2' })
    await vi.advanceTimersByTimeAsync(0)
    expect(snap).toHaveBeenLastCalledWith('cam-2')
    wrapper.unmount()
  })
})
