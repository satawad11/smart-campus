import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import aiNodesService from '@/services/aiNodes'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import AiNodeDetailView from '@/views/aiNodes/AiNodeDetailView.vue'

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { uuid: 'node-1' } }),
  useRouter: () => ({ push: vi.fn() }),
}))

describe('COM-03e: one-time node enrollment code', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
    vi.spyOn(aiNodesService, 'getNode').mockResolvedValue({
      data: { uuid: 'node-1', name: 'Gate', node_code: 'PI5-NODE-01', status: 'ACTIVE' },
    })
    vi.spyOn(aiNodesService, 'listCredentials').mockResolvedValue({ data: [] })
  })

  async function openCredentials(permissions) {
    useAuthStore().user = { username: 'ops', permissions }
    const wrapper = mount(AiNodeDetailView, {
      global: { plugins: [pinia], stubs: { NodeOperationsPanel: true } },
    })
    await flushPromises()
    const tab = wrapper.findAll('button').find((b) => b.text() === 'credentials')
    await tab.trigger('click')
    await flushPromises()
    return wrapper
  }

  it('service posts to the enrollment-codes endpoint', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: {} } })
    await aiNodesService.issueEnrollmentCode('node-1')
    expect(post).toHaveBeenCalledWith('/ai-nodes/node-1/enrollment-codes')
  })

  it('shows the code once with its expiry for a node manager', async () => {
    vi.spyOn(aiNodesService, 'issueEnrollmentCode').mockResolvedValue({
      data: { code: 'ABCD-EFGH-JKMN', expires_at: '2026-10-15T10:30:00Z' },
    })
    const wrapper = await openCredentials(['ai_node.view', 'ai_node.manage'])

    const button = wrapper.findAll('button').find((b) => b.text() === 'สร้างรหัสลงทะเบียน')
    await button.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="enrollment-code-value"]').text()).toBe('ABCD-EFGH-JKMN')
    expect(wrapper.text()).toContain('หมดอายุ')
  })

  it('explains an inactive node in Thai', async () => {
    vi.spyOn(aiNodesService, 'issueEnrollmentCode').mockRejectedValue({
      code: 'AI_NODE_NOT_ACTIVE',
    })
    const wrapper = await openCredentials(['ai_node.view', 'ai_node.manage'])

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'สร้างรหัสลงทะเบียน')
      .trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('ต้องเปิดใช้งานหน่วยประมวลผลก่อน')
  })

  it('is hidden from view-only users', async () => {
    const wrapper = await openCredentials(['ai_node.view'])
    expect(wrapper.find('[data-test="enrollment-code"]').exists()).toBe(false)
  })
})
