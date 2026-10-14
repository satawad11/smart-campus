import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import api from '@/services/api'
import SessionList from '@/components/account/SessionList.vue'

const SESSIONS = [
  {
    uuid: 's1',
    device_name: 'This laptop',
    ip_address: '10.0.0.1',
    is_current: true,
    revoked_at: null,
    logout_at: null,
  },
  {
    uuid: 's2',
    device_name: 'Phone',
    ip_address: '10.0.0.2',
    is_current: false,
    revoked_at: null,
    logout_at: null,
  },
  {
    uuid: 's3',
    device_name: 'Old',
    ip_address: '10.0.0.3',
    is_current: false,
    revoked_at: '2026-10-01T00:00:00Z',
    logout_at: null,
  },
]

describe('A-17: own session management', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('lists only active sessions and marks the current one', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: SESSIONS } })

    const wrapper = mount(SessionList)
    await flushPromises()

    const rows = wrapper.findAll('[data-test="session"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('อุปกรณ์นี้')
    expect(rows[0].find('[data-test="revoke"]').exists()).toBe(false)
    expect(rows[1].find('[data-test="revoke"]').exists()).toBe(true)
  })

  it('revokes one other session and reloads', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: SESSIONS } })
    const del = vi.spyOn(api, 'delete').mockResolvedValue({ data: {} })

    const wrapper = mount(SessionList)
    await flushPromises()
    await wrapper.get('[data-test="revoke"]').trigger('click')
    await flushPromises()

    expect(del).toHaveBeenCalledWith('/auth/sessions/s2')
    expect(get).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="status"]').text()).toContain('ออกจากระบบ')
  })

  it('revokes all other sessions', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: SESSIONS } })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { revoked_count: 1 } } })

    const wrapper = mount(SessionList)
    await flushPromises()
    await wrapper.get('[data-test="revoke-others"]').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/auth/sessions/revoke-others')
  })

  it('hides "revoke others" when this is the only session', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [SESSIONS[0]] } })

    const wrapper = mount(SessionList)
    await flushPromises()

    expect(wrapper.find('[data-test="revoke-others"]').exists()).toBe(false)
  })
})
