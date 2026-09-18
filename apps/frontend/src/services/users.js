import api from './api'

/**
 * Administrative User Management API Service (AUTH-016).
 *
 * Interacts with backend User Management endpoints.
 * Public identifier is strictly the UUID.
 */
export const usersService = {
  /**
   * List users with server-side pagination, search, filters, and sorting.
   * @param {{
   *   page?: number,
   *   per_page?: number,
   *   keyword?: string,
   *   status?: string,
   *   role?: string,
   *   sort?: string,
   *   order?: string
   * }} params
   * @returns {Promise<{ data: Array<object>, meta: { pagination: object } }>}
   */
  async list(params = {}) {
    const response = await api.get('/users', { params })
    return {
      data: response.data.data,
      meta: response.data.meta,
    }
  },

  /**
   * Fetch a single user by public UUID.
   * @param {string} uuid
   * @returns {Promise<object>}
   */
  async get(uuid) {
    const response = await api.get(`/users/${uuid}`)
    return response.data.data
  },

  /**
   * Create a new user.
   * @param {{
   *   username: string,
   *   email?: string,
   *   password: string,
   *   status?: string,
   *   must_change_password?: boolean
   * }} payload
   * @returns {Promise<object>}
   */
  async create(payload) {
    const response = await api.post('/users', payload)
    return response.data.data
  },

  /**
   * Update an existing user's non-security attributes (username, email).
   * @param {string} uuid
   * @param {{ username?: string, email?: string }} payload
   * @returns {Promise<object>}
   */
  async update(uuid, payload) {
    const response = await api.put(`/users/${uuid}`, payload)
    return response.data.data
  },

  /**
   * Update a user's account status (ACTIVE, LOCKED, DISABLED, EXPIRED).
   * @param {string} uuid
   * @param {'ACTIVE'|'LOCKED'|'DISABLED'|'EXPIRED'} status
   * @returns {Promise<object>}
   */
  async changeStatus(uuid, status) {
    const response = await api.patch(`/users/${uuid}/status`, { status })
    return response.data.data
  },

  /**
   * Administratively reset a target user's password.
   * @param {string} uuid
   * @param {string} password
   * @returns {Promise<{ message: string }>}
   */
  async resetPassword(uuid, password) {
    const response = await api.post(`/users/${uuid}/reset-password`, { password })
    return response.data
  },
}

export default usersService
