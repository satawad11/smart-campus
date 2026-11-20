import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import camerasService from '@/services/cameras'
import aiNodesService from '@/services/aiNodes'
import CameraListView from '@/views/cameras/CameraListView.vue'
import AiNodeListView from '@/views/aiNodes/AiNodeListView.vue'
import AiNodeDetailView from '@/views/aiNodes/AiNodeDetailView.vue'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ params: { uuid: 'node-1' }, query: {} }),
}))

const NODE = {
  uuid: 'node-1',
  node_code: 'NODE-01',
  name: 'ประตูหน้า',
  hostname: 'edge-01',
  ip_address: '10.10.20.201',
  api_url: 'http://10.10.20.201:8000',
  hardware_type: 'Jetson',
  cpu_model: 'ARM',
  gpu_name: 'Orin',
  gpu_memory_mb: 8192,
  max_camera: 2,
  status: 'ACTIVE',
  site: { uuid: 'site-1', name: 'พื้นที่หลัก' },
}

function setPermissions(permissions) {
  useAuthStore().user = { uuid: 'admin-1', username: 'admin', permissions }
}

function buttonByText(wrapper, text) {
  return wrapper.findAll('button').find((button) => button.text() === text)
}

describe('CAM-017/CAM-018 device edit actions', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
    push.mockReset()
  })

  it('shows a permission-gated camera edit action from the list', async () => {
    setPermissions(['camera.view', 'camera.update'])
    vi.spyOn(camerasService, 'listCameras').mockResolvedValue({
      data: [{ uuid: 'cam-1', camera_code: 'CAM-01', name: 'กล้องหน้า', status: 'ACTIVE' }],
      meta: { pagination: { current_page: 1, last_page: 1, total: 1 } },
    })

    const wrapper = mount(CameraListView)
    await flushPromises()
    await buttonByText(wrapper, 'แก้ไข').trigger('click')

    expect(push).toHaveBeenCalledWith({
      name: 'cameras.show',
      params: { uuid: 'cam-1' },
      query: { edit: '1' },
    })
  })

  it('shows a permission-gated AI node edit action from the list', async () => {
    setPermissions(['ai_node.view', 'ai_node.manage'])
    vi.spyOn(aiNodesService, 'listNodes').mockResolvedValue({
      data: [NODE],
      meta: { pagination: { current_page: 1, last_page: 1, total: 1 } },
    })

    const wrapper = mount(AiNodeListView)
    await flushPromises()
    await buttonByText(wrapper, 'แก้ไข').trigger('click')

    expect(push).toHaveBeenCalledWith({
      name: 'ai-nodes.show',
      params: { uuid: 'node-1' },
      query: { edit: '1' },
    })
  })

  it('updates all administrator-editable AI node fields without sending locked fields', async () => {
    setPermissions(['ai_node.view', 'ai_node.manage'])
    vi.spyOn(aiNodesService, 'getNode').mockResolvedValue({ data: { ...NODE } })
    const update = vi.spyOn(aiNodesService, 'updateNode').mockResolvedValue({
      data: { ...NODE, name: 'ประตูหน้าแก้ไข', max_camera: 4 },
    })

    const wrapper = mount(AiNodeDetailView, {
      global: { stubs: { NodeOperationsPanel: true } },
    })
    await flushPromises()
    await buttonByText(wrapper, 'แก้ไขข้อมูลหน่วยประมวลผล').trigger('click')

    const form = wrapper.find('[data-testid="ai-node-edit-form"]')
    await form.find('input[name="name"]').setValue('ประตูหน้าแก้ไข')
    await form.find('input[name="max_camera"]').setValue('4')
    await form.trigger('submit')
    await flushPromises()

    expect(update).toHaveBeenCalledTimes(1)
    expect(update.mock.calls[0][0]).toBe('node-1')
    expect(update.mock.calls[0][1]).toMatchObject({
      site_uuid: 'site-1',
      name: 'ประตูหน้าแก้ไข',
      max_camera: 4,
    })
    for (const locked of [
      'node_code',
      'status',
      'software_version',
      'model_code',
      'model_version',
    ]) {
      expect(update.mock.calls[0][1]).not.toHaveProperty(locked)
    }
    expect(wrapper.find('[data-testid="ai-node-edit-form"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('ประตูหน้าแก้ไข')
  })

  it('hides edit actions from view-only users', async () => {
    setPermissions(['ai_node.view'])
    vi.spyOn(aiNodesService, 'getNode').mockResolvedValue({ data: { ...NODE } })

    const wrapper = mount(AiNodeDetailView, {
      global: { stubs: { NodeOperationsPanel: true } },
    })
    await flushPromises()

    expect(buttonByText(wrapper, 'แก้ไขข้อมูลหน่วยประมวลผล')).toBeUndefined()
  })
})
