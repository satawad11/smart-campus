import api from './api'

/**
 * Academic Master Data API Service (STD-015).
 *
 * Provides lookup APIs for Departments, Programs, and Classrooms.
 */
export const academicsService = {
  /**
   * List departments.
   * @param {{ status?: string, keyword?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listDepartments(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/departments', { params })
    return response.data.data || []
  },

  /**
   * List programs, optionally filtered by department_uuid.
   * @param {{ department_uuid?: string, status?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listPrograms(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/programs', { params })
    return response.data.data || []
  },

  /**
   * List classrooms, optionally filtered by department_uuid or program_uuid.
   * @param {{ department_uuid?: string, program_uuid?: string, status?: string, per_page?: number }} params
   * @returns {Promise<Array<object>>}
   */
  async listClassrooms(params = { per_page: 100, status: 'ACTIVE' }) {
    const response = await api.get('/classrooms', { params })
    return response.data.data || []
  },
}

export default academicsService
