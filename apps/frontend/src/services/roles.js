import api from './api'

/**
 * Administrative Role & Permission Management API Service (AUTH-017).
 *
 * Public identifier is strictly the UUID for roles and stable string code for permissions.
 * Never exposes or accepts internal database IDs.
 */
export const rolesService = {
  /**
   * List roles with server-side pagination, search, filters, and sorting.
   * @param {{
   *   page?: number,
   *   per_page?: number,
   *   keyword?: string,
   *   status?: string,
   *   system_role?: boolean,
   *   sort?: string,
   *   order?: string
   * }} params
   * @returns {Promise<{ data: Array<object>, meta: { pagination: object } }>}
   */
  async list(params = {}) {
    const response = await api.get('/roles', { params })
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  },

  /**
   * Fetch a single role with its assigned permission codes by UUID.
   * @param {string} uuid
   * @returns {Promise<object>}
   */
  async get(uuid) {
    const response = await api.get(`/roles/${uuid}`)
    return response.data.data
  },

  /**
   * Create a new custom role.
   * @param {{
   *   code: string,
   *   name: string,
   *   description?: string,
   *   status?: string
   * }} payload
   * @returns {Promise<object>}
   */
  async create(payload) {
    const response = await api.post('/roles', payload)
    return response.data.data
  },

  /**
   * Update mutable role fields (name, description, status).
   * @param {string} uuid
   * @param {{ name?: string, description?: string, status?: string }} payload
   * @returns {Promise<object>}
   */
  async update(uuid, payload) {
    const response = await api.put(`/roles/${uuid}`, payload)
    return response.data.data
  },

  /**
   * Atomically replace a role's permission matrix.
   * @param {string} uuid
   * @param {Array<string>} permissionCodes
   * @returns {Promise<object>}
   */
  async updatePermissions(uuid, permissionCodes) {
    const response = await api.put(`/roles/${uuid}/permissions`, {
      permissions: permissionCodes,
    })
    return response.data.data
  },

  /**
   * Fetch read-only permissions catalog.
   * @param {{ module?: string, keyword?: string, sort?: string, order?: string }} params
   * @returns {Promise<Array<object>>}
   */
  async permissions(params = {}) {
    const response = await api.get('/permissions', { params })
    return response.data.data
  },
}

export default rolesService
