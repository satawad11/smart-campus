import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import api from '@/services/api'
import gatesService from '@/services/gates'
import { useAuthStore } from '@/stores/auth'
import { useFeaturesStore } from '@/stores/features'
import GateListView from '@/views/gates/GateListView.vue'
import GateDetailView from '@/views/gates/GateDetailView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

/** GATE-002 (ACR-VEH-01): Smart Gate list, create, settings and camera assignment. */

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: { uuid: 'gate-1' } }),
  RouterView: { template: '<div />' },
}))

const GATE = {
  uuid: 'gate-1',
  code: 'MAIN_IN',
  name: 'ประตูหน้า',
  direction: 'IN',
  correlation_window_seconds: 10,
  mode: 'FACE_PRIMARY',
  status: 'INACTIVE',
  notes: null,
  site: { uuid: 'site-1', name: 'วิทยาลัย' },
  zone: { uuid: 'zone-1', name: 'ลานจอดรถ' },
  cameras: [
    {
      uuid: 'cam-1',
      name: 'กล้องป้าย',
      camera_code: 'CAM-1',
      role: 'PLATE',
      status: 'ACTIVE',
      face_recognition_enabled: false,
      plate_recognition_enabled: false,
    },
  ],
}

const OPTIONS = [
  {
    uuid: 'site-1',
    name: 'วิทยาลัย',
    zones: [
      { uuid: 'zone-1', name: 'ลานจอดรถ' },
      { uuid: 'zone-2', name: 'ประตูหลัง' },
    ],
    cameras: [
      {
        uuid: 'cam-1',
        name: 'กล้องป้าย',
        camera_code: 'CAM-1',
        status: 'ACTIVE',
        face_recognition_enabled: false,
        plate_recognition_enabled: false,
        gate_uuid: 'gate-1',
      },
      {
        uuid: 'cam-2',
        name: 'กล้องหน้า',
        camera_code: 'CAM-2',
        status: 'ACTIVE',
        face_recognition_enabled: true,
        plate_recognition_enabled: false,
        gate_uuid: null,
      },
      {
        uuid: 'cam-3',
        name: 'กล้องของประตูอื่น',
        camera_code: 'CAM-3',
        status: 'ACTIVE',
        face_recognition_enabled: true,
        plate_recognition_enabled: false,
        gate_uuid: 'gate-9',
      },
    ],
  },
]

function setPermissions(permissions) {
  useAuthStore().user = { uuid: 'u-1', username: 'admin', permissions, roles: [] }
}

function setFeatures(flags) {
  const features = useFeaturesStore()
  features.flags = flags
  features.loaded = true
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
  push.mockReset()
})

describe('Gate list', () => {
  it('lists gates and creates a new one from site and zone choices', async () => {
    setPermissions(['gate.view', 'gate.manage'])
    vi.spyOn(gatesService, 'listGates').mockResolvedValue({
      data: [{ ...GATE, camera_count: 1 }],
      meta: null,
    })
    vi.spyOn(gatesService, 'getFormOptions').mockResolvedValue({ data: OPTIONS })
    const create = vi.spyOn(gatesService, 'createGate').mockResolvedValue({ data: GATE })

    const wrapper = mount(GateListView)
    await flushPromises()
    expect(wrapper.text()).toContain('ประตูหน้า')
    expect(wrapper.text()).toContain('ขาเข้า')

    await wrapper.find('[data-test="open-create"]').trigger('click')
    await flushPromises()
    await wrapper.find('#gate-site').setValue('site-1')
    await wrapper.find('#gate-zone').setValue('zone-1')
    await wrapper.find('#gate-code').setValue('MAIN_IN')
    await wrapper.find('#gate-name').setValue('ประตูหน้า')
    await wrapper.find('[data-test="create-form"]').trigger('submit')
    await flushPromises()

    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({
        site_uuid: 'site-1',
        zone_uuid: 'zone-1',
        code: 'MAIN_IN',
        correlation_window_seconds: 10,
      }),
    )
    expect(create.mock.calls[0][0]).not.toHaveProperty('mode')
    expect(push).toHaveBeenCalledWith({ name: 'gates.show', params: { uuid: 'gate-1' } })
  })

  it('hides the create button from viewers', async () => {
    setPermissions(['gate.view'])
    vi.spyOn(gatesService, 'listGates').mockResolvedValue({ data: [], meta: null })

    const wrapper = mount(GateListView)
    await flushPromises()

    expect(wrapper.find('[data-test="open-create"]').exists()).toBe(false)
  })
})

describe('Gate detail', () => {
  it('warns about camera switches and saves the edited camera set', async () => {
    setPermissions(['gate.view', 'gate.manage'])
    vi.spyOn(gatesService, 'getGate').mockResolvedValue({ data: GATE })
    vi.spyOn(gatesService, 'getFormOptions').mockResolvedValue({ data: OPTIONS })
    const replace = vi
      .spyOn(gatesService, 'replaceCameras')
      .mockResolvedValue({ data: { ...GATE, cameras: [] } })

    const wrapper = mount(GateDetailView)
    await flushPromises()

    expect(wrapper.text()).toContain('กล้องนี้ยังไม่ได้เปิดการอ่านป้ายทะเบียน')
    const addOptions = wrapper.findAll('#add-camera option').map((o) => o.attributes('value'))
    expect(addOptions).toContain('cam-2')
    expect(addOptions).not.toContain('cam-3')

    await wrapper.find('#add-camera').setValue('cam-2')
    await wrapper.find('[data-test="add-camera"]').trigger('click')
    await wrapper.find('[data-test="save-cameras"]').trigger('click')
    await flushPromises()

    expect(replace).toHaveBeenCalledWith('gate-1', [
      { camera_uuid: 'cam-1', role: 'PLATE' },
      { camera_uuid: 'cam-2', role: 'FACE' },
    ])
  })

  it('shows the Thai message when activation needs a face camera', async () => {
    setPermissions(['gate.view', 'gate.manage'])
    vi.spyOn(gatesService, 'getGate').mockResolvedValue({ data: GATE })
    vi.spyOn(gatesService, 'getFormOptions').mockResolvedValue({ data: OPTIONS })
    vi.spyOn(gatesService, 'updateGate').mockRejectedValue({
      status: 422,
      code: 'GATE_NEEDS_FACE_CAMERA',
    })

    const wrapper = mount(GateDetailView)
    await flushPromises()
    await wrapper.find('[data-test="edit-settings"]').trigger('click')
    await wrapper.find('#gate-status').setValue('ACTIVE')
    expect(wrapper.text()).toContain('ต้องมีกล้องจับใบหน้าอย่างน้อย 1 ตัวก่อนเปิดใช้งาน')

    await wrapper.find('[data-test="settings-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('จุดเข้าออกที่เปิดใช้งานต้องมีกล้องจับใบหน้าอย่างน้อย 1 ตัว')
  })

  it('is read-only for viewers and does not ask for form options', async () => {
    setPermissions(['gate.view'])
    vi.spyOn(gatesService, 'getGate').mockResolvedValue({ data: GATE })
    const options = vi.spyOn(gatesService, 'getFormOptions')

    const wrapper = mount(GateDetailView)
    await flushPromises()

    expect(options).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="edit-settings"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-cameras"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('อ่านป้ายทะเบียน')
  })
})

describe('Menu', () => {
  it('shows จุดเข้าออกอัจฉริยะ only with gate.view and the vehicle module on', () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: {} } })
    const layoutText = () =>
      mount(AdminLayout, {
        global: { stubs: { 'router-link': { template: '<a><slot /></a>' }, RouterView: true } },
      }).text()

    setPermissions(['gate.view'])
    setFeatures({ vehicle: false })
    expect(layoutText()).not.toContain('จุดเข้าออกอัจฉริยะ')

    setFeatures({ vehicle: true })
    expect(layoutText()).toContain('จุดเข้าออกอัจฉริยะ')

    setPermissions(['vehicle.view'])
    expect(layoutText()).not.toContain('จุดเข้าออกอัจฉริยะ')
  })
})
