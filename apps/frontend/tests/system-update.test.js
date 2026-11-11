import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import { systemUpdateService } from '@/services/systemUpdate'
import { parseReleaseNotes } from '@/utils/releaseNotes'
import { useAuthStore } from '@/stores/auth'
import SystemUpdateView from '@/views/system/SystemUpdateView.vue'
import ReleaseNotes from '@/components/system/ReleaseNotes.vue'

function makeState(overrides = {}) {
  return {
    installed: { version: 'v1.1.0-rc6', installed_at: '2026-10-01T00:00:00Z', updated_at: null },
    web_update: { enabled: true, reason: null },
    checked_at: '2026-11-10T00:00:00Z',
    releases: [
      {
        tag: 'v1.2.0',
        name: 'v1.2.0',
        published_at: '2026-11-09T00:00:00Z',
        prerelease: false,
        has_kit: true,
        notes: '## ฟีเจอร์ใหม่\n- **หน้าอัปเดตระบบ** ในเว็บ\n- รองรับ `rollback`',
        is_installed: false,
        is_newer: true,
      },
      {
        tag: 'v1.1.0-rc6',
        name: 'v1.1.0-rc6',
        published_at: '2026-11-01T00:00:00Z',
        prerelease: true,
        has_kit: true,
        notes: '- แก้ไขการติดตั้ง',
        is_installed: true,
        is_newer: false,
      },
    ],
    history: [
      { at: '2026-11-01T00:00:00Z', action: 'update', from: 'v1.1.0-rc5', to: 'v1.1.0-rc6' },
    ],
    job: null,
    pending: [],
    ...overrides,
  }
}

describe('ACR-OPS-01: release notes rendering', () => {
  it('parses headings, lists, bold and code into plain data', () => {
    const blocks = parseReleaseNotes('# หัวข้อ\n\n- **ใหม่** ข้อ 1\n* `code`\n\nย่อหน้า\nต่อ')
    expect(blocks[0]).toMatchObject({ type: 'h', level: 1 })
    expect(blocks[1].type).toBe('ul')
    expect(blocks[1].items[0]).toEqual([
      { t: 'b', v: 'ใหม่' },
      { t: 'text', v: ' ข้อ 1' },
    ])
    expect(blocks[2]).toEqual({ type: 'p', inlines: [{ t: 'text', v: 'ย่อหน้า ต่อ' }] })
  })

  it('never renders HTML from release notes', () => {
    const wrapper = mount(ReleaseNotes, {
      props: {
        notes:
          '- <img src=x onerror=alert(1)> [link](javascript:alert(1))\n<script>alert(1)</script>',
      },
    })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('link')
  })
})

describe('ACR-OPS-01: system update page', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
    useAuthStore().user = { username: 'admin', permissions: ['system.update'] }
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  function mountView() {
    return mount(SystemUpdateView, { global: { plugins: [pinia] } })
  }

  it('calls the update endpoints', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: {} } })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: {} } })

    await systemUpdateService.getState()
    await systemUpdateService.requestCheck()
    await systemUpdateService.requestUpdate('v1.2.0', 'pw')

    expect(get).toHaveBeenCalledWith('/system/update')
    expect(post).toHaveBeenNthCalledWith(1, '/system/update/check')
    expect(post).toHaveBeenNthCalledWith(2, '/system/update', {
      version: 'v1.2.0',
      current_password: 'pw',
    })
  })

  it('shows installed version, newer releases with Thai notes, CLI command and history', async () => {
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(makeState())
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-test="installed-version"]').text()).toBe('v1.1.0-rc6')
    const releases = wrapper.findAll('[data-test="release"]')
    expect(releases).toHaveLength(1)
    expect(releases[0].text()).toContain('v1.2.0')
    expect(releases[0].text()).toContain('ฟีเจอร์ใหม่')
    expect(releases[0].find('strong').text()).toBe('หน้าอัปเดตระบบ')
    expect(wrapper.get('[data-test="cli-command"]').text()).toBe('sudo smart-campus update v1.2.0')
    expect(wrapper.get('[data-test="history"]').text()).toContain('v1.1.0-rc5 → v1.1.0-rc6')
    expect(wrapper.get('[data-test="update-button"]').attributes('disabled')).toBeUndefined()
  })

  it('shows up to date when nothing is newer', async () => {
    const state = makeState()
    state.releases = state.releases.filter((r) => !r.is_newer)
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(state)
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.get('[data-test="up-to-date"]').text()).toBe('ระบบเป็นรุ่นล่าสุดแล้ว')
  })

  it('disables the button and explains when web updates are off', async () => {
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(
      makeState({ web_update: { enabled: false, reason: 'DISABLED_BY_ADMIN' } }),
    )
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.get('[data-test="web-disabled"]').text()).toContain('web-update on')
    expect(wrapper.get('[data-test="update-button"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="cli-command"]').exists()).toBe(true)
  })

  it('requires the password, sends the request and shows the queued job', async () => {
    vi.useFakeTimers()
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(makeState())
    const queued = makeState({
      pending: [{ request_id: 'r1', type: 'update', version: 'v1.2.0', requested_by: 'admin' }],
    })
    const request = vi
      .spyOn(systemUpdateService, 'requestUpdate')
      .mockResolvedValue({ request: { type: 'update' }, state: queued })

    const wrapper = mountView()
    await flushPromises()
    await wrapper.get('[data-test="update-button"]').trigger('click')
    expect(wrapper.find('[data-test="confirm-dialog"]').exists()).toBe(true)

    await wrapper.get('[data-test="confirm-update"]').trigger('click')
    expect(wrapper.get('[data-test="password-error"]').text()).toBe('กรุณากรอกรหัสผ่านของคุณ')
    expect(request).not.toHaveBeenCalled()

    await wrapper.get('[data-test="confirm-password"]').setValue('Secret-1')
    await wrapper.get('[data-test="confirm-update"]').trigger('click')
    await flushPromises()

    expect(request).toHaveBeenCalledWith('v1.2.0', 'Secret-1')
    expect(wrapper.find('[data-test="confirm-dialog"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="job"]').text()).toContain('รอเซิร์ฟเวอร์รับงาน')
    expect(wrapper.get('[data-test="update-button"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('keeps the dialog open on a wrong password', async () => {
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(makeState())
    vi.spyOn(systemUpdateService, 'requestUpdate').mockRejectedValue({
      status: 422,
      code: 'VALIDATION_ERROR',
      fields: { current_password: ['รหัสผ่านไม่ถูกต้อง'] },
    })
    const wrapper = mountView()
    await flushPromises()
    await wrapper.get('[data-test="update-button"]').trigger('click')
    await wrapper.get('[data-test="confirm-password"]').setValue('wrong')
    await wrapper.get('[data-test="confirm-update"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="confirm-dialog"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="password-error"]').text()).toBe('รหัสผ่านไม่ถูกต้อง')
  })

  it('shows a conflict error in Thai', async () => {
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(makeState())
    vi.spyOn(systemUpdateService, 'requestUpdate').mockRejectedValue({
      status: 409,
      code: 'SYSTEM_UPDATE_IN_PROGRESS',
      fields: {},
    })
    const wrapper = mountView()
    await flushPromises()
    await wrapper.get('[data-test="update-button"]').trigger('click')
    await wrapper.get('[data-test="confirm-password"]').setValue('pw')
    await wrapper.get('[data-test="confirm-update"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="error"]').text()).toBe(
      'มีการอัปเดตที่รอดำเนินการหรือกำลังทำงานอยู่แล้ว',
    )
  })

  it('shows the last job result and a failed load error', async () => {
    vi.spyOn(systemUpdateService, 'getState').mockResolvedValue(
      makeState({
        job: {
          type: 'update',
          version: 'v1.2.0',
          state: 'failed',
          message: 'อัปเดตไม่สำเร็จ ระบบยังเป็นรุ่น v1.1.0-rc6',
          log_tail: ['x'],
          stale: false,
        },
      }),
    )
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.get('[data-test="job-state"]').text()).toBe('ไม่สำเร็จ')
    expect(wrapper.get('[data-test="job-message"]').text()).toContain('v1.1.0-rc6')

    vi.spyOn(systemUpdateService, 'getState').mockRejectedValue({ status: 403, code: 'FORBIDDEN' })
    const denied = mountView()
    await flushPromises()
    expect(denied.get('[data-test="error"]').text()).toBe('คุณไม่มีสิทธิ์อัปเดตระบบ')
  })
})
