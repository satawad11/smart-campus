import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import StaffListView from '@/views/staff/StaffListView.vue'
import StaffFormView from '@/views/staff/StaffFormView.vue'
import StaffDetailView from '@/views/staff/StaffDetailView.vue'
import StaffImportView from '@/views/staff/StaffImportView.vue'

const staff = {
  uuid: 'st1',
  staff_code: 'T001',
  position: 'ครูประจำแผนก',
  employment_status: 'ACTIVE',
  status_start_date: '2026-11-16',
  phone: null,
  email: null,
  person: {
    uuid: 'p1',
    first_name: 'สมชาย',
    last_name: 'ใจดี',
    display_name: null,
    person_type: 'TEACHER',
  },
  department: { uuid: 'd1', name: 'IT' },
}

const stub = { template: '<div />' }
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/staff', name: 'staff.index', component: stub },
      { path: '/staff/new', name: 'staff.create', component: StaffFormView },
      { path: '/staff/import', name: 'staff.import', component: stub },
      { path: '/staff/:uuid', name: 'staff.show', component: StaffDetailView },
      { path: '/staff/:uuid/edit', name: 'staff.edit', component: StaffFormView },
      { path: '/faces/enroll', name: 'faces.enroll', component: stub },
      { path: '/persons/:uuid/timeline', name: 'persons.timeline', component: stub },
    ],
  })
}

function signIn(permissions) {
  useAuthStore().user = { uuid: 'u1', username: 'admin', permissions }
}

describe('Staff pages (PPL-010)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('lists staff with type, position and status', async () => {
    signIn(['staff.view'])
    vi.spyOn(api, 'get').mockImplementation((url) =>
      Promise.resolve({
        data:
          url === '/staff'
            ? { data: [staff], meta: { pagination: { current_page: 1, last_page: 1, total: 1 } } }
            : { data: [] },
      }),
    )
    const router = makeRouter()
    const wrapper = mount(StaffListView, { global: { plugins: [router] } })
    await flushPromises()

    const row = wrapper.find('[data-test="staff-row"]').text()
    expect(row).toContain('T001')
    expect(row).toContain('สมชาย ใจดี')
    expect(row).toContain('ครู')
    expect(row).toContain('ปฏิบัติงาน')
    expect(wrapper.find('[data-test="staff-add"]').exists()).toBe(false)
  })

  it('creates a teacher and opens the new record', async () => {
    signIn(['staff.view', 'staff.create'])
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [] } })
    const postSpy = vi.spyOn(api, 'post').mockResolvedValue({ data: { data: { uuid: 'st9' } } })
    const router = makeRouter()
    await router.push('/staff/new')
    await router.isReady()
    const wrapper = mount(StaffFormView, { global: { plugins: [router] } })
    await flushPromises()

    await wrapper.find('[data-test="f-first"]').setValue('มาลี')
    await wrapper.find('[data-test="f-last"]').setValue('ดีมาก')
    await wrapper.find('[data-test="f-code"]').setValue('T009')
    await wrapper.find('[data-test="f-position"]').setValue('ครู')
    await wrapper.find('[data-test="staff-form"]').trigger('submit')
    await flushPromises()

    expect(postSpy).toHaveBeenCalledWith('/staff', {
      person: { first_name: 'มาลี', last_name: 'ดีมาก', display_name: null, gender: null },
      person_type: 'TEACHER',
      staff_code: 'T009',
      position: 'ครู',
      department_uuid: null,
      phone: null,
      email: null,
    })
    expect(router.currentRoute.value.fullPath).toBe('/staff/st9')
  })

  it('edit sends neither the staff code nor the type', async () => {
    signIn(['staff.view', 'staff.update'])
    vi.spyOn(api, 'get').mockImplementation((url) =>
      Promise.resolve({ data: url === '/staff/st1' ? { data: staff } : { data: [] } }),
    )
    const putSpy = vi.spyOn(api, 'put').mockResolvedValue({ data: { data: { uuid: 'st1' } } })
    const router = makeRouter()
    await router.push('/staff/st1/edit')
    await router.isReady()
    const wrapper = mount(StaffFormView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.find('[data-test="f-code"]').attributes('disabled')).toBeDefined()
    await wrapper.find('[data-test="f-position"]').setValue('หัวหน้าแผนก')
    await wrapper.find('[data-test="staff-form"]').trigger('submit')
    await flushPromises()

    const [url, body] = putSpy.mock.calls[0]
    expect(url).toBe('/staff/st1')
    expect(body.position).toBe('หัวหน้าแผนก')
    expect(body).not.toHaveProperty('staff_code')
    expect(body).not.toHaveProperty('person_type')
  })

  it('changes employment status from the detail page and shows history', async () => {
    signIn(['staff.view', 'staff.status.change'])
    vi.spyOn(api, 'get').mockImplementation((url) =>
      Promise.resolve({
        data:
          url === '/staff/st1'
            ? { data: staff }
            : {
                data: [
                  {
                    uuid: 'h1',
                    old_status: null,
                    new_status: 'ACTIVE',
                    effective_from: '2026-11-16T08:00:00Z',
                    source: 'MANUAL',
                  },
                ],
              },
      }),
    )
    const patchSpy = vi.spyOn(api, 'patch').mockResolvedValue({ data: { data: staff } })
    const router = makeRouter()
    await router.push('/staff/st1')
    await router.isReady()
    const wrapper = mount(StaffDetailView, { global: { plugins: [router] } })
    await flushPromises()

    expect(wrapper.findAll('[data-test="staff-history"]')).toHaveLength(1)
    await wrapper.find('[data-test="staff-status-open"]').trigger('click')
    await wrapper.find('[data-test="staff-status-select"]').setValue('LEAVE')
    await wrapper.find('[data-test="staff-status-form"]').trigger('submit')
    await flushPromises()

    expect(patchSpy).toHaveBeenCalledWith('/staff/st1/status', { status: 'LEAVE', reason: null })
  })

  it('import: uploads, shows row errors, then processes the valid rows', async () => {
    signIn(['staff.import'])
    vi.spyOn(api, 'post').mockImplementation((url) => {
      if (url === '/staff/import') {
        return Promise.resolve({
          data: {
            data: {
              uuid: 'j1',
              status: 'VALIDATED',
              total_rows: 2,
              valid_rows: 1,
              invalid_rows: 1,
            },
          },
        })
      }
      return Promise.resolve({ data: { data: { uuid: 'j1', status: 'PROCESSING' } } })
    })
    vi.spyOn(api, 'get').mockImplementation((url) => {
      if (url.endsWith('/errors')) {
        return Promise.resolve({
          data: {
            data: [
              { row_number: 3, field: 'email', value: 'x', error_message: 'email is not valid.' },
            ],
          },
        })
      }
      return Promise.resolve({
        data: {
          data: {
            uuid: 'j1',
            status: 'COMPLETED',
            total_rows: 2,
            valid_rows: 1,
            invalid_rows: 1,
            success_rows: 1,
            skipped_rows: 0,
            failed_rows: 0,
          },
        },
      })
    })
    const router = makeRouter()
    const wrapper = mount(StaffImportView, { global: { plugins: [router] } })

    const input = wrapper.find('[data-test="import-file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'staff.csv')] })
    await input.trigger('change')
    await wrapper.find('[data-test="import-upload"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="import-summary"]').text()).toContain('2')
    expect(wrapper.find('[data-test="import-error"]').text()).toContain('email is not valid.')

    await wrapper.find('[data-test="import-process"]').trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/staff/imports/j1/process')
    expect(wrapper.find('[data-test="import-done"]').exists()).toBe(true)
  })
})

describe('Face enrollment opened from a staff page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  it('preselects the person passed in the link', async () => {
    useAuthStore().user = { uuid: 'u1', username: 'admin', permissions: ['face.enroll'] }
    const FaceEnrollmentView = (await import('@/views/face/FaceEnrollmentView.vue')).default
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/faces/enroll', name: 'faces.enroll', component: FaceEnrollmentView },
        { path: '/faces', name: 'faces.index', component: { template: '<div />' } },
      ],
    })
    await router.push({
      path: '/faces/enroll',
      query: { person: '01a1e41d-5a43-716d-8deb-2a76734c2ec7', name: 'สมชาย ใจดี' },
    })
    await router.isReady()

    const wrapper = mount(FaceEnrollmentView, {
      global: { plugins: [router], stubs: { WebcamCapture: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('สมชาย ใจดี')
  })
})
