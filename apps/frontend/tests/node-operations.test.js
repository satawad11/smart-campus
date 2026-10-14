import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import aiNodesService from '@/services/aiNodes'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import NodeOperationsPanel from '@/components/aiNodes/NodeOperationsPanel.vue'

const CONFIG = {
  data: {
    applied_revision: 1,
    schema: { target_fps: { min: 1, max: 30 }, heartbeat_interval_seconds: { min: 5, max: 300 } },
    revisions: [
      { uuid: 'r2', revision: 2, settings: { target_fps: 8 }, created_by: { username: 'ops' } },
      { uuid: 'r1', revision: 1, settings: { target_fps: 5 }, created_by: { username: 'ops' } },
    ],
  },
}
const COMMANDS = {
  data: [
    {
      uuid: 'c1',
      command: 'RESYNC_CONFIG',
      status: 'SUCCEEDED',
      requested_by: { username: 'ops' },
      result_message: 'synced',
    },
  ],
}

describe('COM-03: node operations panel', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
    vi.spyOn(aiNodesService, 'listCommands').mockResolvedValue(COMMANDS)
    vi.spyOn(aiNodesService, 'listConfigRevisions').mockResolvedValue(CONFIG)
  })

  function mountAs(permissions) {
    useAuthStore().user = { username: 'ops', permissions }
    return mount(NodeOperationsPanel, {
      props: { nodeUuid: 'node-1' },
      global: { plugins: [pinia] },
    })
  }

  it('service methods call the COM-03 endpoints', async () => {
    vi.restoreAllMocks()
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: {} })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: {} })

    await aiNodesService.listCommands('n')
    await aiNodesService.sendCommand('n', 'RESTART_SERVICE', 'stuck')
    await aiNodesService.listConfigRevisions('n')
    await aiNodesService.saveConfigRevision('n', { target_fps: 5 }, 'x')
    await aiNodesService.rollbackConfig('n', 2)

    expect(get).toHaveBeenCalledWith('/ai-nodes/n/commands')
    expect(get).toHaveBeenCalledWith('/ai-nodes/n/config-revisions')
    expect(post).toHaveBeenCalledWith('/ai-nodes/n/commands', {
      command: 'RESTART_SERVICE',
      reason: 'stuck',
    })
    expect(post).toHaveBeenCalledWith('/ai-nodes/n/config-revisions', {
      settings: { target_fps: 5 },
      note: 'x',
    })
    expect(post).toHaveBeenCalledWith('/ai-nodes/n/config-revisions/2/rollback')
  })

  it('shows history read-only without ai_node.manage', async () => {
    const wrapper = mountAs(['ai_node.view'])
    await flushPromises()

    expect(wrapper.findAll('[data-test="command-row"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-test="revision-row"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="revision-status"]').text()).toContain('2')
    expect(wrapper.find('[data-test="send-RESTART_SERVICE"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-settings"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rollback-1"]').exists()).toBe(false)
  })

  it('sends an allowlisted command with a reason', async () => {
    const send = vi.spyOn(aiNodesService, 'sendCommand').mockResolvedValue({})
    const wrapper = mountAs(['ai_node.view', 'ai_node.manage'])
    await flushPromises()

    await wrapper.get('[data-test="command-reason"]').setValue('camera frozen')
    await wrapper.get('[data-test="send-RESTART_SERVICE"]').trigger('click')
    await flushPromises()

    expect(send).toHaveBeenCalledWith('node-1', 'RESTART_SERVICE', 'camera frozen')
    expect(wrapper.get('[role="status"]').text()).toContain('ส่งคำสั่งแล้ว')
  })

  it('saves settings prefilled from the latest revision as numbers', async () => {
    const save = vi.spyOn(aiNodesService, 'saveConfigRevision').mockResolvedValue({})
    const wrapper = mountAs(['ai_node.view', 'ai_node.manage'])
    await flushPromises()

    expect(wrapper.get('[data-test="setting-target_fps"]').element.value).toBe('8')
    await wrapper.get('[data-test="setting-heartbeat_interval_seconds"]').setValue('20')
    await wrapper.get('[data-test="save-settings"]').trigger('submit')
    await flushPromises()

    expect(save).toHaveBeenCalledWith(
      'node-1',
      { target_fps: 8, heartbeat_interval_seconds: 20 },
      null,
    )
  })

  it('rolls back to an older revision and shows backend errors', async () => {
    const rollback = vi
      .spyOn(aiNodesService, 'rollbackConfig')
      .mockRejectedValueOnce({ message: 'Revision not found' })
    const wrapper = mountAs(['ai_node.view', 'ai_node.manage'])
    await flushPromises()

    expect(wrapper.find('[data-test="rollback-2"]').exists()).toBe(false) // latest
    await wrapper.get('[data-test="rollback-1"]').trigger('click')
    await flushPromises()

    expect(rollback).toHaveBeenCalledWith('node-1', 1)
    expect(wrapper.get('[role="alert"]').text()).toContain('Revision not found')
  })
})
