import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import AuditLogView from '@/views/system/AuditLogView.vue'

function page(data, current = 1, last = 2) {
  return {
    data: {
      data,
      meta: { pagination: { current_page: current, last_page: last, total: 30, per_page: 25 } },
    },
  }
}

const LOG = {
  uuid: 'a1',
  action: 'LICENSE_INSTALLED',
  module: 'license',
  actor: { username: 'admin' },
  subject_type: 'LICENSE',
  subject_uuid: 'lic-1',
  old_values: null,
  new_values: { plan: 'STANDARD' },
  metadata: { reason_code: null },
  ip_address: '10.0.0.5',
  created_at: '2026-10-14T03:00:00+00:00',
}

describe('A-17 / COM-05: audit log viewer', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('lists audit entries and expands details', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue(page([LOG]))

    const wrapper = mount(AuditLogView)
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/audit-logs', { params: { page: 1, per_page: 25 } })
    expect(wrapper.get('[data-test="audit-row"]').text()).toContain('LICENSE_INSTALLED')
    expect(wrapper.get('[data-test="audit-row"]').text()).toContain('admin')
    expect(wrapper.find('[data-test="audit-detail"]').exists()).toBe(false)

    await wrapper.get('[data-test="audit-row"]').trigger('click')
    expect(wrapper.get('[data-test="audit-detail"]').text()).toContain('STANDARD')
  })

  it('sends only filled filters and resets to page 1', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue(page([LOG]))

    const wrapper = mount(AuditLogView)
    await flushPromises()
    await wrapper.get('[data-test="filter-module"]').setValue('license')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/audit-logs', {
      params: { page: 1, per_page: 25, module: 'license' },
    })
  })

  it('paginates', async () => {
    const get = vi
      .spyOn(api, 'get')
      .mockResolvedValueOnce(page([LOG], 1, 2))
      .mockResolvedValueOnce(page([LOG], 2, 2))

    const wrapper = mount(AuditLogView)
    await flushPromises()
    await wrapper.get('[data-test="next"]').trigger('click')
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/audit-logs', { params: { page: 2, per_page: 25 } })
    expect(wrapper.get('[data-test="pagination"]').text()).toContain('หน้า 2 / 2')
    expect(wrapper.get('[data-test="next"]').attributes('disabled')).toBeDefined()
  })

  it('shows "system" for entries without an actor and an error on failure', async () => {
    vi.spyOn(api, 'get').mockResolvedValueOnce(page([{ ...LOG, actor: null }]))
    const wrapper = mount(AuditLogView)
    await flushPromises()
    expect(wrapper.get('[data-test="audit-row"]').text()).toContain('ระบบ')

    vi.spyOn(api, 'get').mockRejectedValueOnce({ message: 'Forbidden' })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Forbidden')
  })
})
