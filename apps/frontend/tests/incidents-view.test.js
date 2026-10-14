import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import IncidentsView from '@/views/system/IncidentsView.vue'

const INCIDENT = {
  id: 'inc-1',
  incident_number: 'INC-2026-0001',
  title: 'Camera offline at gate',
  severity: 'SEV2',
  status: 'OPEN',
  description: 'Gate camera stopped streaming',
}

describe('A-17 / COM-05: incidents UI', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  function mountAs(permissions) {
    useAuthStore().user = { username: 'ops', permissions }
    return mount(IncidentsView, { global: { plugins: [pinia] } })
  }

  it('lists incidents and hides management controls without system.manage', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [INCIDENT] } })

    const wrapper = mountAs(['system.view'])
    await flushPromises()

    expect(wrapper.get('[data-test="incident"]').text()).toContain('INC-2026-0001')
    expect(wrapper.find('[data-test="create-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="edit"]').exists()).toBe(false)
  })

  it('filters by status', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [] } })

    const wrapper = mountAs(['system.view'])
    await flushPromises()
    await wrapper.get('[data-test="status-filter"]').setValue('RESOLVED')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/system/incidents', { params: { status: 'RESOLVED' } })
  })

  it('creates an incident and validates required fields', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [] } })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: INCIDENT } })

    const wrapper = mountAs(['system.view', 'system.manage'])
    await flushPromises()

    await wrapper.get('[data-test="create-form"]').trigger('submit')
    await flushPromises()
    expect(post).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('หัวข้อ')

    await wrapper.get('[data-test="new-title"]').setValue('Camera offline at gate')
    await wrapper.get('[data-test="new-description"]').setValue('Gate camera stopped streaming')
    await wrapper.get('[data-test="create-form"]').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/system/incidents', {
      title: 'Camera offline at gate',
      severity: 'SEV2',
      description: 'Gate camera stopped streaming',
      impact_summary: '',
    })
  })

  it('updates an incident status', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [INCIDENT] } })
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: { data: {} } })

    const wrapper = mountAs(['system.view', 'system.manage'])
    await flushPromises()
    await wrapper.get('[data-test="edit"]').trigger('click')
    await wrapper.get('[data-test="edit-status"]').setValue('RESOLVED')
    await wrapper.get('[data-test="save"]').trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/system/incidents/inc-1', {
      status: 'RESOLVED',
      root_cause: '',
      resolution_summary: '',
    })
  })
})
