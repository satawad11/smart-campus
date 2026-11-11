import api from './api'

/**
 * Web system update (ACR-OPS-01, docs/decisions/ACR-OPS-01-WEB-SYSTEM-UPDATE.md).
 * The backend only reads state published by the host update agent and queues
 * signed requests for it; the GitHub token never reaches the browser.
 */
export const systemUpdateService = {
  /** @returns {Promise<object>} installed version, releases, history, job, pending */
  async getState() {
    const response = await api.get('/system/update')
    return response.data.data
  },

  /** Ask the host agent to refresh the release list from GitHub. */
  async requestCheck() {
    const response = await api.post('/system/update/check')
    return response.data.data
  },

  /**
   * @param {string} version release tag, e.g. v1.2.0
   * @param {string} currentPassword the signed-in admin's password (re-entry)
   */
  async requestUpdate(version, currentPassword) {
    const response = await api.post('/system/update', {
      version,
      current_password: currentPassword,
    })
    return response.data.data
  },
}
