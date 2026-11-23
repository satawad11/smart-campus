import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import api from '@/services/api'
import { authService } from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import AccountSecurityView from '@/views/account/AccountSecurityView.vue'
import QrcodeVue from 'qrcode.vue'

const RouterLinkStub = { template: '<a><slot /></a>', props: ['to'] }

function mountView(pinia) {
  return mount(AccountSecurityView, {
    global: { plugins: [pinia], stubs: { 'router-link': RouterLinkStub, SessionList: true } },
  })
}

describe('A-15: MFA enrollment UI (account security)', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  it('calls the existing MFA endpoints', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { ok: true } } })

    await authService.setupMfa()
    await authService.confirmMfa('123456')
    await authService.disableMfa({ password: 'pw', code: '654321' })

    expect(post).toHaveBeenNthCalledWith(1, '/auth/mfa/setup')
    expect(post).toHaveBeenNthCalledWith(2, '/auth/mfa/confirm', { code: '123456' })
    expect(post).toHaveBeenNthCalledWith(3, '/auth/mfa/disable', { password: 'pw', code: '654321' })
  })

  it('enrolls: shows the secret, confirms the code and refreshes the profile', async () => {
    const store = useAuthStore()
    store.user = { username: 'officer1', mfa_enabled: false }
    vi.spyOn(authService, 'setupMfa').mockResolvedValue({
      secret: 'JBSWY3DPEHPK3PXP',
      qr_code_url: 'otpauth://totp/Campus:officer1?secret=JBSWY3DPEHPK3PXP',
      issuer: 'Campus',
      account_name: 'officer1',
      expires_in_seconds: 600,
    })
    const confirm = vi.spyOn(authService, 'confirmMfa').mockResolvedValue({ mfa_enabled: true })
    vi.spyOn(authService, 'getMe').mockResolvedValue({
      user: { username: 'officer1', mfa_enabled: true },
    })

    const wrapper = mountView(pinia)
    expect(wrapper.get('[data-test="mfa-status"]').text()).toContain('ยังไม่เปิดใช้งาน')

    await wrapper.get('[data-test="mfa-start"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="mfa-qr-code"]').exists()).toBe(true)
    expect(wrapper.findComponent(QrcodeVue).props()).toMatchObject({
      value: 'otpauth://totp/Campus:officer1?secret=JBSWY3DPEHPK3PXP',
      size: 240,
      level: 'M',
      renderAs: 'svg',
    })
    expect(wrapper.get('[data-test="mfa-secret"]').text()).toBe('JBSW Y3DP EHPK 3PXP')

    await wrapper.get('[data-test="mfa-confirm-code"]').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith('123456')
    expect(store.user.mfa_enabled).toBe(true)
    expect(wrapper.find('[data-test="mfa-secret"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="mfa-status"]').text()).toContain('เปิดใช้งานแล้ว')
  })

  it('rejects a malformed confirmation code without calling the API', async () => {
    const store = useAuthStore()
    store.user = { username: 'officer1', mfa_enabled: false }
    vi.spyOn(authService, 'setupMfa').mockResolvedValue({
      secret: 'ABCD',
      account_name: 'o',
      issuer: 'C',
    })
    const confirm = vi.spyOn(authService, 'confirmMfa')

    const wrapper = mountView(pinia)
    await wrapper.get('[data-test="mfa-start"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="mfa-confirm-code"]').setValue('12ab')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(confirm).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('6 หลัก')
  })

  it('maps an invalid code error to a Thai message and keeps the setup open', async () => {
    const store = useAuthStore()
    store.user = { username: 'officer1', mfa_enabled: false }
    vi.spyOn(authService, 'setupMfa').mockResolvedValue({
      secret: 'ABCD',
      account_name: 'o',
      issuer: 'C',
    })
    vi.spyOn(authService, 'confirmMfa').mockRejectedValue({ code: 'AUTH_MFA_INVALID_CODE' })

    const wrapper = mountView(pinia)
    await wrapper.get('[data-test="mfa-start"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="mfa-confirm-code"]').setValue('000000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('รหัสยืนยันไม่ถูกต้อง')
    expect(wrapper.find('[data-test="mfa-secret"]').exists()).toBe(true)
  })

  it('reports success when only the profile refresh fails after confirming', async () => {
    const store = useAuthStore()
    store.user = { username: 'officer1', mfa_enabled: false }
    vi.spyOn(authService, 'setupMfa').mockResolvedValue({
      secret: 'ABCD',
      account_name: 'o',
      issuer: 'C',
    })
    vi.spyOn(authService, 'confirmMfa').mockResolvedValue({ mfa_enabled: true })
    vi.spyOn(authService, 'getMe').mockRejectedValue({ code: 'UNKNOWN_ERROR' })

    const wrapper = mountView(pinia)
    await wrapper.get('[data-test="mfa-start"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="mfa-confirm-code"]').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toContain('เปิดใช้งาน MFA สำเร็จ')
    expect(store.user.mfa_enabled).toBe(true)
  })

  it('disables MFA with password and code', async () => {
    const store = useAuthStore()
    store.user = { username: 'officer1', mfa_enabled: true }
    const disable = vi.spyOn(authService, 'disableMfa').mockResolvedValue({})
    vi.spyOn(authService, 'getMe').mockResolvedValue({
      user: { username: 'officer1', mfa_enabled: false },
    })

    const wrapper = mountView(pinia)
    await wrapper.get('[data-test="mfa-disable-password"]').setValue('Current#Pass1')
    await wrapper.get('[data-test="mfa-disable-code"]').setValue('654321')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(disable).toHaveBeenCalledWith({ password: 'Current#Pass1', code: '654321' })
    expect(store.user.mfa_enabled).toBe(false)
  })
})
