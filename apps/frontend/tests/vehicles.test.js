import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import api from '@/services/api'
import vehiclesService from '@/services/vehicles'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import { useAuthStore } from '@/stores/auth'
import { useFeaturesStore } from '@/stores/features'
import VehicleListView from '@/views/vehicles/VehicleListView.vue'
import VehicleDetailView from '@/views/vehicles/VehicleDetailView.vue'
import CameraDetailView from '@/views/cameras/CameraDetailView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: { uuid: 'veh-1' } }),
  RouterView: { template: '<div />' },
}))

const VEHICLE = {
  uuid: 'veh-1',
  plate_number: '1กข1234',
  plate_display: '1กข 1234',
  plate_province: 'TH-10',
  plate_province_name: 'กรุงเทพมหานคร',
  vehicle_type: 'CAR',
  brand: 'Toyota',
  model: null,
  color: 'ขาว',
  status: 'ACTIVE',
  notes: null,
  links: [
    {
      uuid: 'link-1',
      relation: 'OWNER',
      is_current: true,
      effective_from: '2026-11-01T00:00:00Z',
      effective_to: null,
      person: { uuid: 'p-1', display_name: 'สมชาย ใจดี', person_code: 'S001' },
    },
    {
      uuid: 'link-0',
      relation: 'DRIVER',
      is_current: false,
      effective_from: '2025-01-01T00:00:00Z',
      effective_to: '2025-12-31T00:00:00Z',
      person: { uuid: 'p-0', display_name: 'คนเก่า', person_code: 'S000' },
    },
  ],
}

function setPermissions(permissions) {
  useAuthStore().user = { uuid: 'u-1', username: 'admin', permissions }
}

function setFeatures(flags) {
  const features = useFeaturesStore()
  features.flags = flags
  features.loaded = true
}

function featureDisabledError() {
  return {
    status: 403,
    code: 'UNKNOWN_ERROR',
    raw: { response: { data: { error_code: 'FEATURE_DISABLED' } } },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.restoreAllMocks()
  push.mockReset()
})

describe('features store', () => {
  it('fails closed when /features cannot be loaded', async () => {
    vi.spyOn(api, 'get').mockRejectedValue(new Error('offline'))
    const features = useFeaturesStore()
    await features.load()
    expect(features.isOn('vehicle')).toBe(false)
  })

  it('reads only literal true flags', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { vehicle: true, alpr: 'yes' } } })
    const features = useFeaturesStore()
    await features.load()
    expect(features.isOn('vehicle')).toBe(true)
    expect(features.isOn('alpr')).toBe(false)
  })
})

describe('Vehicle list', () => {
  it('lists vehicles with Thai labels and registers a new one', async () => {
    setPermissions(['vehicle.view', 'vehicle.manage'])
    vi.spyOn(vehiclesService, 'listVehicles').mockResolvedValue({
      data: [VEHICLE],
      meta: { pagination: { current_page: 1, last_page: 1, total: 1 } },
    })
    const create = vi
      .spyOn(vehiclesService, 'createVehicle')
      .mockResolvedValue({ data: { uuid: 'veh-2' } })

    const wrapper = mount(VehicleListView)
    await flushPromises()

    expect(wrapper.text()).toContain('จัดการยานพาหนะ')
    expect(wrapper.text()).toContain('1กข 1234')
    expect(wrapper.text()).toContain('กรุงเทพมหานคร')
    expect(wrapper.text()).toContain('รถยนต์')

    await wrapper
      .findAll('button')
      .find((b) => b.text().includes('ลงทะเบียนรถ'))
      .trigger('click')
    await wrapper.find('#plate_number').setValue('1กข 5678')
    await wrapper.find('#plate_province').setValue('กทม')
    await wrapper.find('[data-test="create-form"]').trigger('submit')
    await flushPromises()

    expect(create).toHaveBeenCalledWith(
      expect.objectContaining({ plate_number: '1กข 5678', plate_province: 'กทม', brand: null }),
    )
    expect(push).toHaveBeenCalledWith({ name: 'vehicles.show', params: { uuid: 'veh-2' } })
  })

  it('hides registration for view-only users and explains a disabled module', async () => {
    setPermissions(['vehicle.view'])
    vi.spyOn(vehiclesService, 'listVehicles').mockRejectedValue(featureDisabledError())

    const wrapper = mount(VehicleListView)
    await flushPromises()

    expect(wrapper.text()).toContain('โมดูลนี้ยังไม่เปิดใช้งาน')
    expect(wrapper.findAll('button').some((b) => b.text().includes('ลงทะเบียนรถ'))).toBe(false)
  })
})

describe('Vehicle detail', () => {
  it('shows current and past links and links a person found by search', async () => {
    setPermissions(['vehicle.view', 'vehicle.manage', 'student.view'])
    vi.spyOn(vehiclesService, 'getVehicle').mockResolvedValue({ data: VEHICLE })
    const search = vi
      .spyOn(vehiclesService, 'searchPeople')
      .mockResolvedValue([{ uuid: 'p-9', name: 'สมหญิง ดีมาก', code: '6601', group: 'นักเรียน' }])
    const link = vi.spyOn(vehiclesService, 'linkPerson').mockResolvedValue({ data: {} })

    const wrapper = mount(VehicleDetailView)
    await flushPromises()

    expect(wrapper.find('[data-test="plate"]').text()).toBe('1กข 1234')
    expect(wrapper.find('[data-test="current-links"]').text()).toContain('สมชาย ใจดี')
    expect(wrapper.find('[data-test="current-links"]').text()).toContain('เจ้าของ')
    expect(wrapper.text()).toContain('ประวัติการผูกที่สิ้นสุดแล้ว (1)')

    await wrapper.find('#people-search').setValue('สมหญิง')
    await wrapper.find('#people-search').trigger('keyup.enter')
    await flushPromises()
    expect(search).toHaveBeenCalledWith('สมหญิง', { students: true, staff: false })

    await wrapper.find('[data-test="people-results"] button').trigger('click')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'ผูกกับรถ')
      .trigger('click')
    await flushPromises()

    expect(link).toHaveBeenCalledWith('veh-1', {
      person_uuid: 'p-9',
      relation: 'OWNER',
      reason: null,
    })
  })

  it('ends a link after the prompt and does nothing when cancelled', async () => {
    setPermissions(['vehicle.view', 'vehicle.manage'])
    vi.spyOn(vehiclesService, 'getVehicle').mockResolvedValue({ data: VEHICLE })
    const end = vi.spyOn(vehiclesService, 'endLink').mockResolvedValue({ data: {} })
    const prompt = vi.spyOn(window, 'prompt')

    const wrapper = mount(VehicleDetailView)
    await flushPromises()
    const endButton = () => wrapper.findAll('button').find((b) => b.text() === 'ยกเลิกการผูก')

    prompt.mockReturnValueOnce(null)
    await endButton().trigger('click')
    await flushPromises()
    expect(end).not.toHaveBeenCalled()

    prompt.mockReturnValueOnce('ขายรถ')
    await endButton().trigger('click')
    await flushPromises()
    expect(end).toHaveBeenCalledWith('veh-1', 'link-1', { reason: 'ขายรถ' })
  })

  it('shows no edit or link controls to view-only users', async () => {
    setPermissions(['vehicle.view'])
    vi.spyOn(vehiclesService, 'getVehicle').mockResolvedValue({ data: VEHICLE })

    const wrapper = mount(VehicleDetailView)
    await flushPromises()

    const texts = wrapper.findAll('button').map((b) => b.text())
    expect(texts).not.toContain('แก้ไขข้อมูลรถ')
    expect(texts).not.toContain('ยกเลิกการผูก')
    expect(wrapper.find('#people-search').exists()).toBe(false)
  })
})

describe('Menu entry', () => {
  function layoutText() {
    return mount(AdminLayout, {
      global: { stubs: { 'router-link': { template: '<a><slot /></a>' }, RouterView: true } },
    }).text()
  }

  it('shows จัดการยานพาหนะ only with the permission and the module on', () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: {} } })
    const auth = useAuthStore()
    auth.user = { uuid: 'u-1', username: 'ผู้ดูแล', permissions: ['vehicle.view'], roles: [] }

    setFeatures({ vehicle: false })
    expect(layoutText()).not.toContain('จัดการยานพาหนะ')

    setFeatures({ vehicle: true })
    expect(layoutText()).toContain('จัดการยานพาหนะ')

    auth.user = { uuid: 'u-1', username: 'ผู้ดูแล', permissions: ['camera.view'], roles: [] }
    expect(layoutText()).not.toContain('จัดการยานพาหนะ')
  })
})

describe('Camera plate-reading switch', () => {
  const CAMERA = {
    uuid: 'cam-1',
    camera_code: 'CAM-0001',
    name: 'ประตูรถ',
    has_rtsp_url: true,
    has_credentials: false,
    face_detection_enabled: true,
    face_recognition_enabled: true,
    liveness_enabled: false,
    snapshot_enabled: true,
    plate_recognition_enabled: true,
    status: 'ACTIVE',
    site: { uuid: 's', name: 'วิทยาลัย' },
    zone: { uuid: 'z', name: 'ประตูหลัก' },
    ai_node: null,
  }

  async function openAiTab(flags) {
    setPermissions(['camera.view', 'camera.update'])
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: flags } })
    vi.spyOn(camerasService, 'getCamera').mockResolvedValue({ data: { ...CAMERA } })
    vi.spyOn(aiNodesService, 'listNodes').mockResolvedValue({ data: [] })
    const update = vi
      .spyOn(camerasService, 'updateCamera')
      .mockResolvedValue({ data: { ...CAMERA } })

    const wrapper = mount(CameraDetailView)
    await flushPromises()
    const tab = wrapper.findAll('button').find((b) => b.text().includes('ตั้งค่า'))
    await tab.trigger('click')
    return { wrapper, update }
  }

  it('is hidden while ALPR is off and is not sent with the face settings', async () => {
    const { wrapper, update } = await openAiTab({ alpr: false })

    expect(wrapper.find('#plate-recognition-toggle').exists()).toBe(false)
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'บันทึกการตั้งค่า')
      .trigger('click')
    await flushPromises()
    expect(update.mock.calls[0][1]).not.toHaveProperty('plate_recognition_enabled')
  })

  it('is shown and sent while ALPR is on', async () => {
    const { wrapper, update } = await openAiTab({ alpr: true })

    const toggle = wrapper.find('#plate-recognition-toggle')
    expect(toggle.exists()).toBe(true)
    expect(wrapper.text()).toContain('อ่านป้ายทะเบียน')
    await toggle.setValue(false)
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'บันทึกการตั้งค่า')
      .trigger('click')
    await flushPromises()
    expect(update.mock.calls[0][1].plate_recognition_enabled).toBe(false)
  })
})
