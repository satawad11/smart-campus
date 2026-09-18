import api from './api'

/**
 * Authentication API Service (AUTH-015).
 *
 * Interacts with backend Auth/Session endpoints.
 * Never stores or exposes secrets/tokens in client storage.
 */
export const authService = {
  /**
   * Bootstrap current session profile.
   * @returns {Promise<{ user: object }>}
   */
  async getMe() {
    const response = await api.get('/auth/me')
    return response.data.data
  },

  /**
   * Authenticate with username and password.
   * @param {{ username: string, password: string }} credentials
   * @returns {Promise<{ user?: object, status?: string, challenge?: object }>}
   */
  async login({ username, password }) {
    const response = await api.post('/auth/login', { username, password })
    return response.data.data
  },

  /**
   * Verify TOTP code for pending MFA login challenge.
   * NOTE: Server pending session state determines user identity; no user UUID is sent.
   * @param {string} code
   * @returns {Promise<{ user: object }>}
   */
  async verifyMfa(code) {
    const response = await api.post('/auth/mfa/verify', { code })
    return response.data.data
  },

  /**
   * Change current authenticated user's own password.
   * @param {{ current_password: string, password: string, password_confirmation: string }} payload
   * @returns {Promise<{ user: object }>}
   */
  async changePassword({ current_password, password, password_confirmation }) {
    const response = await api.post('/auth/change-password', {
      current_password,
      password,
      password_confirmation,
    })
    return response.data.data
  },

  /**
   * Terminate current authenticated session.
   * @returns {Promise<void>}
   */
  async logout() {
    await api.post('/auth/logout')
  },
}
