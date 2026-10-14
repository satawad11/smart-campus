import api from './api'

/**
 * Commercial licence API (COM-02, docs/commercial/LICENSE_MODEL.md).
 * The signed licence text is sent once and never returned by the backend.
 */
export const licenseService = {
  /** @returns {Promise<object>} evaluated licence state incl. installation_id */
  async getStatus() {
    const response = await api.get('/system/license')
    return response.data.data
  },

  /**
   * @param {string} license signed licence document (JSON envelope text)
   * @returns {Promise<object>} new evaluated state
   */
  async install(license) {
    const response = await api.post('/system/license', { license })
    return response.data.data
  },

  /**
   * @param {string} revocationList signed revocation list (JSON envelope text)
   * @returns {Promise<{ newly_revoked: number, license: object }>}
   */
  async importRevocations(revocationList) {
    const response = await api.post('/system/license/revocations', {
      revocation_list: revocationList,
    })
    return response.data.data
  },
}
