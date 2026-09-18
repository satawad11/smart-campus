import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { authService } from '@/services/auth'
import { setUnauthorizedHandler } from '@/services/api'

/**
 * Pinia Authentication Store (AUTH-015).
 *
 * Centralized state for user session, initialization, and MFA flow.
 * No credentials, tokens, or TOTP codes are persisted in client storage.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const initialized = ref(false)
  const loading = ref(false)
  const mfaPending = ref(false)
  const intendedRoute = ref(null)
  const sessionExpired = ref(false)

  const isAuthenticated = computed(() => !!user.value)
  const mustChangePassword = computed(() => !!user.value?.must_change_password)
  const isSuperAdmin = computed(() => {
    if (!user.value?.roles) return false
    return user.value.roles.some((r) =>
      typeof r === 'string' ? r === 'SUPER_ADMIN' : r.code === 'SUPER_ADMIN',
    )
  })

  /**
   * Check if current user has a specific permission for UI visibility.
   * Note: Backend remains authoritative for security enforcement.
   * @param {string} permission
   * @returns {boolean}
   */
  function can(permission) {
    if (!user.value) return false
    if (isSuperAdmin.value) return true
    if (Array.isArray(user.value.permissions)) {
      return user.value.permissions.includes(permission)
    }
    // If backend doesn't list granular permissions in /auth/me, allow UI action by default
    return true
  }

  /**
   * Bootstrap application authentication state on initial load.
   */
  async function bootstrap() {
    if (initialized.value) return

    loading.value = true
    try {
      const data = await authService.getMe()
      user.value = data.user || null
      mfaPending.value = false
    } catch {
      user.value = null
      mfaPending.value = false
    } finally {
      initialized.value = true
      loading.value = false
    }
  }

  /**
   * Log in with username and password.
   * @param {{ username: string, password: string }} credentials
   */
  async function login(credentials) {
    loading.value = true
    sessionExpired.value = false

    try {
      const data = await authService.login(credentials)

      if (data.status === 'MFA_REQUIRED') {
        user.value = null
        mfaPending.value = true
        return { status: 'MFA_REQUIRED', challenge: data.challenge }
      }

      user.value = data.user
      mfaPending.value = false
      return { status: 'SUCCESS', user: data.user }
    } finally {
      loading.value = false
    }
  }

  /**
   * Verify TOTP code for MFA challenge during login.
   * @param {string} code
   */
  async function verifyMfa(code) {
    loading.value = true

    try {
      const data = await authService.verifyMfa(code)
      user.value = data.user
      mfaPending.value = false
      return { status: 'SUCCESS', user: data.user }
    } finally {
      loading.value = false
    }
  }

  /**
   * Change current user's own password.
   * @param {{ current_password: string, password: string, password_confirmation: string }} payload
   */
  async function changePassword(payload) {
    loading.value = true

    try {
      const data = await authService.changePassword(payload)
      user.value = data.user
      return { status: 'SUCCESS', user: data.user }
    } finally {
      loading.value = false
    }
  }

  /**
   * Log out the current user and invalidate the session.
   */
  async function logout() {
    loading.value = true

    try {
      await authService.logout()
    } catch {
      // Ignore 401/network errors during logout — client must clear local auth state regardless
    } finally {
      clearAuth()
      loading.value = false
    }
  }

  /**
   * Clear local memory auth state.
   */
  function clearAuth() {
    user.value = null
    mfaPending.value = false
    intendedRoute.value = null
  }

  /**
   * Handle session revocation / 401 on protected requests.
   */
  function handleSessionExpired() {
    if (user.value) {
      clearAuth()
      sessionExpired.value = true
    }
  }

  // Register unauthorized response interceptor callback
  setUnauthorizedHandler(() => {
    handleSessionExpired()
  })

  return {
    user,
    initialized,
    loading,
    mfaPending,
    intendedRoute,
    sessionExpired,
    isAuthenticated,
    mustChangePassword,
    isSuperAdmin,
    can,
    bootstrap,
    login,
    verifyMfa,
    changePassword,
    logout,
    clearAuth,
    handleSessionExpired,
  }
})
