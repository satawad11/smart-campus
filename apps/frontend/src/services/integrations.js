import api from './api'

/**
 * External API connections (ACR-INT-02). The API key is write-only: the
 * server returns only whether a key is set, its source and a non-secret hint.
 */
export const integrationsService = {
  /** @returns {Promise<Array<object>>} */
  async list() {
    const response = await api.get('/integrations')
    return response.data.data
  },

  /**
   * @param {{ base_url: string, api_key?: string, clear_api_key?: boolean, timeout_seconds: number, sync_enabled: boolean, sync_time: string }} payload
   */
  async updateRms(payload) {
    const response = await api.put('/integrations/rms', payload)
    return response.data.data
  },

  /** @returns {Promise<{ result: object, connection: object }>} */
  async testRms() {
    const response = await api.post('/integrations/rms/test')
    return { result: response.data.data, connection: response.data.meta?.connection }
  },
}

export default integrationsService
