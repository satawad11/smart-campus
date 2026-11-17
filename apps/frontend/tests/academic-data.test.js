import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import AcademicDataView from '@/views/academics/AcademicDataView.vue'

const dept = {
  uuid: 'd1',
  code: 'IT',
  name: 'เทคโนโลยีสารสนเทศ',
  description: null,
  status: 'ACTIVE',
}
const program = {
  uuid: 'p1',
  code: 'IT-NET',
  name: 'เครือข่าย',
  education_level: 'HIGH_VOC_CERT',
  status: 'ACTIVE',
  department: { uuid: 'd1', name: 'เทคโนโลยีสารสนเทศ' },
}

function mockGet() {
  return vi.spyOn(api, 'get').mockImplementation((url) => {
    const data = url === '/departments' ? [dept] : url === '/programs' ? [program] : []
    return Promise.resolve({
      data: { data, meta: { pagination: { current_page: 1, last_page: 1, total: data.length } } },
    })
  })
}

function signIn(permissions) {
  useAuthStore().user = { uuid: 'u1', username: 'admin', permissions }
}

describe('Academic data page (departments / programs / classrooms)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('lists departments and hides add/edit without academic.manage', async () => {
    signIn(['academic.view'])
    mockGet()

    const wrapper = mount(AcademicDataView)
    await flushPromises()

    expect(wrapper.findAll('[data-test="academic-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('เทคโนโลยีสารสนเทศ')
    expect(wrapper.find('[data-test="academic-add"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="academic-edit"]').exists()).toBe(false)
  })

  it('creates a department with only the accepted fields', async () => {
    signIn(['academic.view', 'academic.manage'])
    mockGet()
    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: {} } })

    const wrapper = mount(AcademicDataView)
    await flushPromises()

    await wrapper.find('[data-test="academic-add"]').trigger('click')
    await wrapper.find('[data-test="field-code"]').setValue('ACC')
    await wrapper.find('[data-test="field-name"]').setValue('การบัญชี')
    await wrapper.find('[data-test="academic-form"]').trigger('submit')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith('/departments', {
      code: 'ACC',
      name: 'การบัญชี',
      description: null,
      status: 'ACTIVE',
    })
    expect(wrapper.find('[data-test="academic-form"]').exists()).toBe(false)
  })

  it('edits a program without sending its code or department, and can deactivate it', async () => {
    signIn(['academic.view', 'academic.manage'])
    mockGet()
    const putSpy = vi.spyOn(api, 'put').mockResolvedValue({ data: { data: {} } })

    const wrapper = mount(AcademicDataView)
    await flushPromises()
    await wrapper.find('[data-test="tab-programs"]').trigger('click')
    await flushPromises()

    await wrapper.find('[data-test="academic-edit"]').trigger('click')
    expect(wrapper.find('[data-test="field-code"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="field-name"]').setValue('เครือข่ายคอมพิวเตอร์')
    await wrapper.find('[data-test="field-status"]').setValue('INACTIVE')
    await wrapper.find('[data-test="academic-form"]').trigger('submit')
    await flushPromises()

    expect(putSpy).toHaveBeenCalledWith('/programs/p1', {
      name: 'เครือข่ายคอมพิวเตอร์',
      education_level: 'HIGH_VOC_CERT',
      status: 'INACTIVE',
    })
  })

  it('shows the server validation message and keeps the form open', async () => {
    signIn(['academic.view', 'academic.manage'])
    mockGet()
    vi.spyOn(api, 'post').mockRejectedValue({
      status: 422,
      code: 'VALIDATION_ERROR',
      message: 'The code has already been taken.',
      fields: { code: ['The code has already been taken.'] },
    })

    const wrapper = mount(AcademicDataView)
    await flushPromises()
    await wrapper.find('[data-test="academic-add"]').trigger('click')
    await wrapper.find('[data-test="field-code"]').setValue('IT')
    await wrapper.find('[data-test="field-name"]').setValue('ซ้ำ')
    await wrapper.find('[data-test="academic-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="academic-form"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('The code has already been taken.')
  })
})
