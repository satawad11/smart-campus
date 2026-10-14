import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'
import AccountSecurityView from '@/views/account/AccountSecurityView.vue'

describe('Admin MFA enrollment required (decision 2026-10-14)', () => {
  let pinia

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
    vi.restoreAllMocks()
  })

  function signIn(user) {
    const store = useAuthStore()
    store.initialized = true
    store.user = { username: 'admin', permissions: ['*'], roles: [], ...user }
  }

  it('redirects every page to account security until MFA is enrolled', async () => {
    signIn({ mfa_enrollment_required: true })

    await router.push('/users')
    expect(router.currentRoute.value.name).toBe('account.security')

    await router.push('/change-password')
    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('does not redirect once MFA is enrolled', async () => {
    signIn({ mfa_enrollment_required: false, mfa_enabled: true })

    await router.push('/settings')
    expect(router.currentRoute.value.name).toBe('settings.index')
  })

  it('shows the requirement banner on the account security page', () => {
    signIn({ mfa_enrollment_required: true, mfa_enabled: false })

    const wrapper = mount(AccountSecurityView, {
      global: { plugins: [pinia], stubs: { 'router-link': true, SessionList: true } },
    })

    expect(wrapper.get('[data-test="mfa-required-banner"]').text()).toContain('MFA')
  })
})
