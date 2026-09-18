import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { authService } from '@/services/auth'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import MfaView from '@/views/MfaView.vue'
import ChangePasswordView from '@/views/ChangePasswordView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

describe('AUTH-015: Authentication & Session Management', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  describe('1. API Client & Service Contract', () => {
    it('configures withCredentials and /api/v1 base URL', () => {
      expect(api.defaults.withCredentials).toBe(true)
      expect(api.defaults.baseURL).toBe('/api/v1')
      expect(api.defaults.headers['Accept']).toBe('application/json')
    })

    it('delegates authService methods to api endpoints', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: { user: { id: 1 } } } })
      const postSpy = vi
        .spyOn(api, 'post')
        .mockResolvedValue({ data: { data: { status: 'SUCCESS' } } })

      const me = await authService.getMe()
      expect(getSpy).toHaveBeenCalledWith('/auth/me')
      expect(me.user.id).toBe(1)

      await authService.login({ username: 'admin', password: 'password' })
      expect(postSpy).toHaveBeenCalledWith('/auth/login', {
        username: 'admin',
        password: 'password',
      })

      await authService.verifyMfa('123456')
      expect(postSpy).toHaveBeenCalledWith('/auth/mfa/verify', { code: '123456' })

      await authService.changePassword({
        current_password: 'old',
        password: 'new',
        password_confirmation: 'new',
      })
      expect(postSpy).toHaveBeenCalledWith('/auth/change-password', {
        current_password: 'old',
        password: 'new',
        password_confirmation: 'new',
      })

      await authService.logout()
      expect(postSpy).toHaveBeenCalledWith('/auth/logout')
    })
  })

  describe('2. Pinia Auth Store', () => {
    it('initializes with default state', () => {
      const store = useAuthStore()
      expect(store.user).toBeNull()
      expect(store.initialized).toBe(false)
      expect(store.loading).toBe(false)
      expect(store.mfaPending).toBe(false)
      expect(store.isAuthenticated).toBe(false)
      expect(store.mustChangePassword).toBe(false)
      expect(store.sessionExpired).toBe(false)
    })

    it('bootstraps authenticated user', async () => {
      vi.spyOn(authService, 'getMe').mockResolvedValue({
        user: { id: 'u-1', username: 'admin', must_change_password: false },
      })

      const store = useAuthStore()
      await store.bootstrap()

      expect(store.initialized).toBe(true)
      expect(store.isAuthenticated).toBe(true)
      expect(store.user.username).toBe('admin')
      expect(store.mustChangePassword).toBe(false)
    })

    it('bootstraps unauthenticated guest gracefully on 401', async () => {
      vi.spyOn(authService, 'getMe').mockRejectedValue({
        status: 401,
        code: 'UNAUTHENTICATED',
      })

      const store = useAuthStore()
      await store.bootstrap()

      expect(store.initialized).toBe(true)
      expect(store.isAuthenticated).toBe(false)
      expect(store.user).toBeNull()
    })

    it('handles successful direct login', async () => {
      vi.spyOn(authService, 'login').mockResolvedValue({
        user: { id: 'u-1', username: 'admin', must_change_password: false },
      })

      const store = useAuthStore()
      const res = await store.login({ username: 'admin', password: 'Password@123' })

      expect(res.status).toBe('SUCCESS')
      expect(store.isAuthenticated).toBe(true)
      expect(store.mfaPending).toBe(false)
      expect(store.user.username).toBe('admin')
    })

    it('handles MFA challenge requirement during login', async () => {
      vi.spyOn(authService, 'login').mockResolvedValue({
        status: 'MFA_REQUIRED',
        challenge: { type: 'totp' },
      })

      const store = useAuthStore()
      const res = await store.login({ username: 'mfa_user', password: 'Password@123' })

      expect(res.status).toBe('MFA_REQUIRED')
      expect(store.isAuthenticated).toBe(false)
      expect(store.mfaPending).toBe(true)
    })

    it('completes MFA verification successfully', async () => {
      vi.spyOn(authService, 'verifyMfa').mockResolvedValue({
        user: { id: 'u-2', username: 'mfa_user', must_change_password: false },
      })

      const store = useAuthStore()
      store.mfaPending = true

      const res = await store.verifyMfa('654321')
      expect(res.status).toBe('SUCCESS')
      expect(store.isAuthenticated).toBe(true)
      expect(store.mfaPending).toBe(false)
      expect(store.user.username).toBe('mfa_user')
    })

    it('changes password and updates state', async () => {
      vi.spyOn(authService, 'changePassword').mockResolvedValue({
        user: { id: 'u-3', username: 'forced_user', must_change_password: false },
      })

      const store = useAuthStore()
      store.user = { id: 'u-3', username: 'forced_user', must_change_password: true }
      expect(store.mustChangePassword).toBe(true)

      const res = await store.changePassword({
        current_password: 'OldPassword@123',
        password: 'NewPassword@123',
        password_confirmation: 'NewPassword@123',
      })

      expect(res.status).toBe('SUCCESS')
      expect(store.mustChangePassword).toBe(false)
    })

    it('logs out and resets state', async () => {
      const logoutSpy = vi.spyOn(authService, 'logout').mockResolvedValue()

      const store = useAuthStore()
      store.user = { id: 'u-1', username: 'admin' }
      store.intendedRoute = '/dashboard'

      await store.logout()

      expect(logoutSpy).toHaveBeenCalled()
      expect(store.user).toBeNull()
      expect(store.isAuthenticated).toBe(false)
      expect(store.intendedRoute).toBeNull()
    })

    it('handles session expiration', () => {
      const store = useAuthStore()
      store.user = { id: 'u-1', username: 'admin' }

      store.handleSessionExpired()

      expect(store.user).toBeNull()
      expect(store.isAuthenticated).toBe(false)
      expect(store.sessionExpired).toBe(true)
    })
  })

  describe('3. Router Navigation Guards', () => {
    function setupRouter(initialUser = null, mfaPending = false) {
      const routes = [
        {
          path: '/login',
          name: 'login',
          component: { template: '<div>Login</div>' },
          meta: { guestOnly: true },
        },
        {
          path: '/mfa',
          name: 'mfa',
          component: { template: '<div>MFA</div>' },
          meta: { requiresMfaPending: true },
        },
        {
          path: '/change-password',
          name: 'change-password',
          component: { template: '<div>Change Password</div>' },
          meta: { requiresAuth: true },
        },
        {
          path: '/dashboard',
          name: 'dashboard',
          component: { template: '<div>Dashboard</div>' },
          meta: { requiresAuth: true },
        },
      ]

      const router = createRouter({
        history: createMemoryHistory(),
        routes,
      })

      const authStore = useAuthStore()
      authStore.initialized = true
      authStore.user = initialUser
      authStore.mfaPending = mfaPending

      router.beforeEach(async (to, from, next) => {
        if (!authStore.initialized) {
          await authStore.bootstrap()
        }

        const isAuthenticated = authStore.isAuthenticated
        const mustChangePassword = authStore.mustChangePassword
        const isMfaPending = authStore.mfaPending

        if (isAuthenticated) {
          if (mustChangePassword && to.name !== 'change-password') {
            return next({ name: 'change-password' })
          }

          if (to.meta.guestOnly || to.meta.requiresMfaPending) {
            if (mustChangePassword) {
              return next({ name: 'change-password' })
            }
            return next({ name: 'dashboard' })
          }

          return next()
        }

        if (to.meta.requiresMfaPending) {
          if (isMfaPending) {
            return next()
          }
          return next({ name: 'login' })
        }

        if (to.meta.requiresAuth) {
          authStore.intendedRoute = to.fullPath
          return next({ name: 'login' })
        }

        return next()
      })

      return { router, authStore }
    }

    it('redirects unauthenticated user from protected route to /login', async () => {
      const { router, authStore } = setupRouter(null)

      await router.push('/dashboard')
      expect(router.currentRoute.value.name).toBe('login')
      expect(authStore.intendedRoute).toBe('/dashboard')
    })

    it('blocks access to /mfa if mfaPending is false', async () => {
      const { router } = setupRouter(null, false)

      await router.push('/mfa')
      expect(router.currentRoute.value.name).toBe('login')
    })

    it('allows access to /mfa if mfaPending is true', async () => {
      const { router } = setupRouter(null, true)

      await router.push('/mfa')
      expect(router.currentRoute.value.name).toBe('mfa')
    })

    it('redirects authenticated user away from /login to /dashboard', async () => {
      const { router } = setupRouter({ id: 'u-1', username: 'admin' })

      await router.push('/login')
      expect(router.currentRoute.value.name).toBe('dashboard')
    })

    it('forces user with must_change_password to /change-password', async () => {
      const { router } = setupRouter({ id: 'u-1', username: 'admin', must_change_password: true })

      await router.push('/dashboard')
      expect(router.currentRoute.value.name).toBe('change-password')

      await router.push('/login')
      expect(router.currentRoute.value.name).toBe('change-password')
    })
  })

  describe('4. Component Views', () => {
    let testRouter

    beforeEach(async () => {
      testRouter = createRouter({
        history: createMemoryHistory(),
        routes: [
          { path: '/login', name: 'login', component: { template: '<div />' } },
          { path: '/mfa', name: 'mfa', component: { template: '<div />' } },
          { path: '/change-password', name: 'change-password', component: { template: '<div />' } },
          { path: '/dashboard', name: 'dashboard', component: { template: '<div />' } },
        ],
      })
      await testRouter.push('/login')
      await testRouter.isReady()
    })

    it('renders LoginView with inputs, validation and session alert', async () => {
      const authStore = useAuthStore()
      authStore.sessionExpired = true

      const wrapper = mount(LoginView, {
        global: {
          plugins: [testRouter],
          stubs: {
            RouterLink: true,
          },
        },
      })

      expect(wrapper.text()).toContain('เข้าสู่ระบบ')
      expect(wrapper.text()).toContain('เซสชันของคุณหมดอายุหรือถูกยกเลิก')

      const usernameInput = wrapper.find('#username')
      const passwordInput = wrapper.find('#password')
      expect(usernameInput.exists()).toBe(true)
      expect(passwordInput.exists()).toBe(true)

      // Test validation
      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('กรุณากรอกชื่อผู้ใช้')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสผ่าน')
    })

    it('renders MfaView with TOTP input and validation', async () => {
      const wrapper = mount(MfaView, {
        global: {
          plugins: [testRouter],
        },
      })

      expect(wrapper.text()).toContain('การยืนยันตัวตน 2 ขั้นตอน')
      expect(wrapper.text()).toContain('กรุณากรอกรหัส 6 หลัก')

      const codeInput = wrapper.find('#mfa-code')
      expect(codeInput.exists()).toBe(true)

      // Validation test for short/empty code
      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสความปลอดภัย 6 หลัก')

      await codeInput.setValue('123')
      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('รหัสความปลอดภัยต้องเป็นตัวเลข 6 หลัก')
    })

    it('renders ChangePasswordView with policy guide and field validation', async () => {
      const authStore = useAuthStore()
      authStore.user = { id: 1, must_change_password: true }

      const wrapper = mount(ChangePasswordView, {
        global: {
          plugins: [testRouter],
        },
      })

      expect(wrapper.text()).toContain('เปลี่ยนรหัสผ่าน')
      expect(wrapper.text()).toContain('ระบบกำหนดให้คุณต้องเปลี่ยนรหัสผ่านก่อนเข้าใช้งาน')
      expect(wrapper.text()).toContain('เกณฑ์ความปลอดภัยของรหัสผ่าน')

      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสผ่านปัจจุบัน')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสผ่านใหม่')
    })

    it('renders AdminLayout with current user information and logout trigger', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        id: 1,
        username: 'sec_admin',
        display_name: 'Security Officer',
        roles: ['Super Admin'],
      }

      const wrapper = mount(AdminLayout, {
        global: {
          plugins: [testRouter],
          stubs: {
            RouterLink: true,
            RouterView: true,
          },
        },
      })

      expect(wrapper.text()).toContain('Security Officer')
      expect(wrapper.text()).toContain('Super Admin')
      expect(wrapper.text()).toContain('ออกจากระบบ')
    })
  })
})
