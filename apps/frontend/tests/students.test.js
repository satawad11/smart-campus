import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { studentsService } from '@/services/students'
import { academicsService } from '@/services/academics'
import { useAuthStore } from '@/stores/auth'
import StudentListView from '@/views/students/StudentListView.vue'
import StudentCreateView from '@/views/students/StudentCreateView.vue'
import StudentDetailView from '@/views/students/StudentDetailView.vue'
import StudentEditView from '@/views/students/StudentEditView.vue'
import AcademicStatusBadge from '@/components/students/AcademicStatusBadge.vue'
import EducationLevelBadge from '@/components/students/EducationLevelBadge.vue'

describe('STD-015: Student Management UI', () => {
  let router

  const sampleStudent = {
    uuid: 'std-uuid-12345',
    student_code: 'STD-66001',
    academic_status: 'ACTIVE',
    education_level: 'VOC_CERT',
    year_level: 2,
    admission_year: 2566,
    graduation_year: 2569,
    status_start_date: '2024-05-15',
    status_end_date: null,
    person: {
      uuid: 'person-uuid-999',
      first_name: 'สมชาย',
      last_name: 'สุขใจ',
      display_name: 'ชาย',
      person_code: '1234567890123',
      person_type: 'STUDENT',
      gender: 'MALE',
      status: 'ACTIVE',
    },
    department: {
      uuid: 'dept-uuid-001',
      code: 'IT',
      name: 'เทคโนโลยีสารสนเทศ',
    },
    program: {
      uuid: 'prog-uuid-001',
      code: 'IT-DEV',
      name: 'การพัฒนาซอฟต์แวร์',
      education_level: 'VOC_CERT',
    },
    classroom: {
      uuid: 'class-uuid-001',
      code: 'IT-101',
      name: 'ปวช. 2/1',
      academic_year: 2566,
      year_level: 2,
    },
    created_at: '2024-05-15T08:30:00.000000Z',
    updated_at: '2024-05-15T08:30:00.000000Z',
  }

  const sampleDepartments = [
    { uuid: 'dept-uuid-001', code: 'IT', name: 'เทคโนโลยีสารสนเทศ', status: 'ACTIVE' },
    { uuid: 'dept-uuid-002', code: 'ACC', name: 'การบัญชี', status: 'ACTIVE' },
  ]

  const samplePrograms = [
    { uuid: 'prog-uuid-001', code: 'IT-DEV', name: 'การพัฒนาซอฟต์แวร์', department_uuid: 'dept-uuid-001', status: 'ACTIVE' },
  ]

  const sampleClassrooms = [
    { uuid: 'class-uuid-001', code: 'IT-101', name: 'ปวช. 2/1', department_uuid: 'dept-uuid-001', status: 'ACTIVE' },
  ]

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    // Default mock for academic lookups to prevent unhandled jsdom XHR
    vi.spyOn(academicsService, 'listDepartments').mockResolvedValue(sampleDepartments)
    vi.spyOn(academicsService, 'listPrograms').mockResolvedValue(samplePrograms)
    vi.spyOn(academicsService, 'listClassrooms').mockResolvedValue(sampleClassrooms)

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/students', name: 'students.index', component: StudentListView, meta: { requiresAuth: true, permission: 'student.view' } },
        { path: '/students/new', name: 'students.create', component: StudentCreateView, meta: { requiresAuth: true, permission: 'student.create' } },
        { path: '/students/:uuid', name: 'students.show', component: StudentDetailView, meta: { requiresAuth: true, permission: 'student.view' } },
        { path: '/students/:uuid/edit', name: 'students.edit', component: StudentEditView, meta: { requiresAuth: true, permission: 'student.update' } },
      ],
    })
    await router.push('/students')
    await router.isReady()
  })

  // ================= 1. API Services =================
  describe('1. API Services (studentsService & academicsService)', () => {
    it('studentsService.list calls GET /students with query parameters', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [sampleStudent],
          meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
        },
      })

      const res = await studentsService.list({
        page: 1,
        per_page: 20,
        keyword: 'สมชาย',
        academic_status: 'ACTIVE',
        department_uuid: 'dept-uuid-001',
      })

      expect(getSpy).toHaveBeenCalledWith('/students', {
        params: {
          page: 1,
          per_page: 20,
          keyword: 'สมชาย',
          academic_status: 'ACTIVE',
          department_uuid: 'dept-uuid-001',
        },
      })
      expect(res.data).toHaveLength(1)
      expect(res.data[0].student_code).toBe('STD-66001')
    })

    it('studentsService.get calls GET /students/:uuid', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: { data: sampleStudent },
      })

      const res = await studentsService.get('std-uuid-12345')
      expect(getSpy).toHaveBeenCalledWith('/students/std-uuid-12345')
      expect(res.uuid).toBe('std-uuid-12345')
    })

    it('studentsService.create calls POST /students with StoreStudentRequest format', async () => {
      const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
        data: { data: sampleStudent },
      })

      const payload = {
        person: {
          first_name: 'สมชาย',
          last_name: 'สุขใจ',
          display_name: 'ชาย',
          person_code: '1234567890123',
          gender: 'MALE',
        },
        student_code: 'STD-66001',
        department_uuid: 'dept-uuid-001',
        program_uuid: 'prog-uuid-001',
        classroom_uuid: 'class-uuid-001',
        education_level: 'VOC_CERT',
        year_level: 2,
        admission_year: 2566,
      }

      const res = await studentsService.create(payload)
      expect(postSpy).toHaveBeenCalledWith('/students', payload)
      expect(res.student_code).toBe('STD-66001')
    })

    it('studentsService.update calls PUT /students/:uuid with UpdateStudentRequest format', async () => {
      const putSpy = vi.spyOn(api, 'put').mockResolvedValue({
        data: { data: { ...sampleStudent, year_level: 3 } },
      })

      const payload = {
        person: {
          first_name: 'สมชาย',
          last_name: 'สุขใจยิ่ง',
          display_name: 'พี่ชาย',
        },
        year_level: 3,
      }

      const res = await studentsService.update('std-uuid-12345', payload)
      expect(putSpy).toHaveBeenCalledWith('/students/std-uuid-12345', payload)
      expect(res.year_level).toBe(3)
    })

    it('academicsService calls GET /departments, /programs, /classrooms', async () => {
      vi.restoreAllMocks()
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: { data: sampleDepartments },
      })

      const depts = await academicsService.listDepartments()
      expect(getSpy).toHaveBeenCalledWith('/departments', { params: { per_page: 100, status: 'ACTIVE' } })
      expect(depts).toHaveLength(2)
    })
  })

  // ================= 2. Student List View =================
  describe('2. Student List View (StudentListView.vue)', () => {
    beforeEach(() => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'admin-uuid',
        username: 'admin',
        roles: [{ code: 'ADMIN', name: 'Admin' }],
        permissions: ['student.view', 'student.create', 'student.update'],
      }
    })

    it('loads and renders student list with safe fields', async () => {
      vi.spyOn(studentsService, 'list').mockResolvedValue({
        data: [sampleStudent],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Header & student code
      expect(wrapper.text()).toContain('จัดการข้อมูลนักเรียน')
      expect(wrapper.text()).toContain('STD-66001')
      expect(wrapper.text()).toContain('สมชาย สุขใจ')
      expect(wrapper.text()).toContain('เทคโนโลยีสารสนเทศ')
      expect(wrapper.text()).toContain('การพัฒนาซอฟต์แวร์')
      expect(wrapper.text()).toContain('กำลังศึกษา')
      expect(wrapper.text()).toContain('ปวช.')

      // Internal IDs must NOT be rendered in the document
      expect(wrapper.html()).not.toContain('student_id')
      expect(wrapper.html()).not.toContain('person_id')
    })

    it('filters students by academic_status', async () => {
      const listSpy = vi.spyOn(studentsService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      const statusSelect = wrapper.find('#status-filter')
      await statusSelect.setValue('LEAVE')

      expect(listSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          academic_status: 'LEAVE',
        })
      )
    })

    it('filters students by department and updates dependent programs/classrooms', async () => {
      const listProgsSpy = vi.spyOn(academicsService, 'listPrograms').mockResolvedValue(samplePrograms)
      const listClassesSpy = vi.spyOn(academicsService, 'listClassrooms').mockResolvedValue(sampleClassrooms)
      const listSpy = vi.spyOn(studentsService, 'list').mockResolvedValue({
        data: [sampleStudent],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      const deptSelect = wrapper.find('#dept-filter')
      await deptSelect.setValue('dept-uuid-001')
      await flushPromises()

      expect(listProgsSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          department_uuid: 'dept-uuid-001',
        })
      )
      expect(listClassesSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          department_uuid: 'dept-uuid-001',
        })
      )
      expect(listSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          department_uuid: 'dept-uuid-001',
        })
      )
    })

    it('renders empty state when no students are returned', async () => {
      vi.spyOn(studentsService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 0 } },
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(wrapper.text()).toContain('ไม่พบข้อมูลนักเรียน')
    })

    it('renders API error message when student list fails to load', async () => {
      vi.spyOn(studentsService, 'list').mockRejectedValue({
        code: 'FORBIDDEN',
        message: 'Forbidden',
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์ในการเข้าถึงข้อมูลนักเรียน')
    })

    it('shows Create button when user has student.create permission and hides when absent', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'u-viewer',
        username: 'viewer',
        permissions: ['student.view'], // no student.create
      }

      vi.spyOn(studentsService, 'list').mockResolvedValue({
        data: [sampleStudent],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      })

      const wrapper = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(wrapper.find('a[href="/students/new"]').exists()).toBe(false)

      // Grant student.create
      auth.user.permissions.push('student.create')
      const wrapperWithPerm = mount(StudentListView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()
      expect(wrapperWithPerm.find('a[href="/students/new"]').exists()).toBe(true)
    })
  })

  // ================= 3. Student Create View =================
  describe('3. Student Create View (StudentCreateView.vue)', () => {
    beforeEach(async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'admin-uuid',
        username: 'admin',
        permissions: ['student.view', 'student.create'],
      }
      await router.push('/students/new')
    })

    it('loads academic departments on mount', async () => {
      const listDeptSpy = vi.spyOn(academicsService, 'listDepartments').mockResolvedValue(sampleDepartments)

      const wrapper = mount(StudentCreateView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(listDeptSpy).toHaveBeenCalled()
      expect(wrapper.find('#department_uuid').text()).toContain('เทคโนโลยีสารสนเทศ')
    })

    it('validates required fields client-side before submission', async () => {
      const createSpy = vi.spyOn(studentsService, 'create')

      const wrapper = mount(StudentCreateView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(createSpy).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('กรุณากรอกชื่อ')
      expect(wrapper.text()).toContain('กรุณากรอกนามสกุล')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสนักเรียน')
      expect(wrapper.text()).toContain('กรุณาเลือกแผนกวิชา')
    })

    it('does NOT include an academic status dropdown (status is server-controlled ACTIVE)', async () => {
      const wrapper = mount(StudentCreateView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(wrapper.find('select[name="academic_status"]').exists()).toBe(false)
      expect(wrapper.find('#academic_status').exists()).toBe(false)
      expect(wrapper.text()).toContain('กำลังศึกษา (ACTIVE)')
    })

    it('submits valid form data and redirects to detail page with created=true query', async () => {
      const createSpy = vi.spyOn(studentsService, 'create').mockResolvedValue(sampleStudent)
      const pushSpy = vi.spyOn(router, 'push')

      const wrapper = mount(StudentCreateView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      // Fill in fields
      await wrapper.find('#first_name').setValue('สมชาย')
      await wrapper.find('#last_name').setValue('สุขใจ')
      await wrapper.find('#student_code').setValue('STD-66001')
      await wrapper.find('#department_uuid').setValue('dept-uuid-001')
      await wrapper.find('#education_level').setValue('VOC_CERT')
      await wrapper.find('#year_level').setValue(2)

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(createSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          person: expect.objectContaining({
            first_name: 'สมชาย',
            last_name: 'สุขใจ',
          }),
          student_code: 'STD-66001',
          department_uuid: 'dept-uuid-001',
          education_level: 'VOC_CERT',
          year_level: 2,
        })
      )

      expect(pushSpy).toHaveBeenCalledWith({
        path: '/students/std-uuid-12345',
        query: { created: 'true' },
      })
    })

    it('displays server 422 validation field errors', async () => {
      vi.spyOn(studentsService, 'create').mockRejectedValue({
        status: 422,
        message: 'ข้อมูลไม่ถูกต้อง',
        fields: {
          student_code: ['รหัสนักเรียนนี้มีอยู่ในระบบแล้ว'],
          'person.first_name': ['ชื่อมีความยาวเกินกำหนด'],
        },
      })

      const wrapper = mount(StudentCreateView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      await wrapper.find('#first_name').setValue('สมชาย')
      await wrapper.find('#last_name').setValue('สุขใจ')
      await wrapper.find('#student_code').setValue('STD-66001')
      await wrapper.find('#department_uuid').setValue('dept-uuid-001')
      await wrapper.find('#education_level').setValue('VOC_CERT')

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('รหัสนักเรียนนี้มีอยู่ในระบบแล้ว')
    })
  })

  // ================= 4. Student Detail View =================
  describe('4. Student Detail View (StudentDetailView.vue)', () => {
    beforeEach(async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'admin-uuid',
        username: 'admin',
        permissions: ['student.view', 'student.update'],
      }
      await router.push('/students/std-uuid-12345')
    })

    it('loads and displays student detail by UUID', async () => {
      const getSpy = vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)

      const wrapper = mount(StudentDetailView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(getSpy).toHaveBeenCalledWith('std-uuid-12345')
      expect(wrapper.text()).toContain('สมชาย สุขใจ')
      expect(wrapper.text()).toContain('STD-66001')
      expect(wrapper.text()).toContain('เทคโนโลยีสารสนเทศ')
      expect(wrapper.text()).toContain('การพัฒนาซอฟต์แวร์')
      expect(wrapper.text()).toContain('ปวช. 2/1')
      expect(wrapper.text()).toContain('กำลังศึกษา')
      expect(wrapper.text()).toContain('std-uuid-12345')

      // Security boundaries: no user accounts, passwords, or facial vectors
      expect(wrapper.html()).not.toContain('password')
      expect(wrapper.html()).not.toContain('embedding')
      expect(wrapper.html()).not.toContain('ALLOW')
      expect(wrapper.html()).not.toContain('DENY')
    })

    it('hides edit button when user lacks student.update permission', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'viewer-uuid',
        username: 'viewer',
        permissions: ['student.view'], // no student.update
      }

      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)

      const wrapper = mount(StudentDetailView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      expect(wrapper.find('a[href="/students/std-uuid-12345/edit"]').exists()).toBe(false)
    })
  })

  // ================= 4b. Person Event Timeline (EVT-019) =================
  describe('4b. Person Event Timeline integration (PersonEventTimeline.vue)', () => {
    beforeEach(async () => {
      await router.push('/students/std-uuid-12345')
    })

    it('is hidden without event.view, and never queries GET /events', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'admin-uuid',
        username: 'admin',
        permissions: ['student.view', 'student.update'], // no event.view
      }
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [], meta: null } })

      const wrapper = mount(StudentDetailView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.text()).not.toContain('ประวัติการรับรู้ใบหน้า')
      expect(wrapper.html()).not.toContain('ALLOW')
      expect(wrapper.html()).not.toContain('DENY')
      expect(getSpy).not.toHaveBeenCalledWith('/events', expect.anything())
    })

    it('loads the persons events filtered by person_uuid when event.view is held', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'officer-uuid',
        username: 'officer',
        permissions: ['student.view', 'event.view'],
      }
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)
      const getSpy = vi.spyOn(api, 'get').mockImplementation((url) => {
        if (url === '/events') {
          return Promise.resolve({
            data: {
              data: [
                {
                  uuid: 'evt-1',
                  event_type: 'FACE_RECOGNIZED',
                  occurred_at: '2026-09-23T09:00:00Z',
                  site: { uuid: 's-1', name: 'Main Campus' },
                  camera: { uuid: 'c-1', name: 'Gate 1' },
                  current_decision: { decision: 'ALLOW', reason_code: 'STUDENT_ACTIVE' },
                },
              ],
              meta: { pagination: { current_page: 1, per_page: 5, total: 1, last_page: 1 } },
            },
          })
        }
        return Promise.resolve({ data: { data: null } })
      })

      const wrapper = mount(StudentDetailView, { global: { plugins: [router] } })
      await flushPromises()

      expect(getSpy).toHaveBeenCalledWith('/events', {
        params: {
          person_uuid: 'person-uuid-999',
          sort: 'occurred_at',
          order: 'desc',
          page: 1,
          per_page: 5,
        },
      })
      expect(wrapper.text()).toContain('ประวัติการรับรู้ใบหน้า')
      expect(wrapper.text()).toContain('FACE_RECOGNIZED')
      expect(wrapper.text()).toContain('ALLOW')
      expect(wrapper.text()).toContain('Gate 1')
    })

    it('shows an empty state when the person has no recognition events', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'officer-uuid-2',
        username: 'officer2',
        permissions: ['student.view', 'event.view'],
      }
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)
      vi.spyOn(api, 'get').mockImplementation((url) => {
        if (url === '/events') {
          return Promise.resolve({
            data: { data: [], meta: { pagination: { current_page: 1, per_page: 5, total: 0, last_page: 1 } } },
          })
        }
        return Promise.resolve({ data: { data: null } })
      })

      const wrapper = mount(StudentDetailView, { global: { plugins: [router] } })
      await flushPromises()

      expect(wrapper.text()).toContain('ยังไม่มีบันทึกเหตุการณ์การรับรู้ใบหน้า')
    })
  })

  // ================= 5. Student Edit View =================
  describe('5. Student Edit View (StudentEditView.vue)', () => {
    beforeEach(async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'admin-uuid',
        username: 'admin',
        permissions: ['student.view', 'student.update'],
      }
      await router.push('/students/std-uuid-12345/edit')
    })

    it('loads student and populates editable fields while keeping immutable fields read-only', async () => {
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)

      const wrapper = mount(StudentEditView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      // Editable inputs
      expect(wrapper.find('#first_name').element.value).toBe('สมชาย')
      expect(wrapper.find('#last_name').element.value).toBe('สุขใจ')
      expect(wrapper.find('#display_name').element.value).toBe('ชาย')

      // Immutable attributes must be disabled / read-only
      const studentCodeInput = wrapper.findAll('input').find(i => i.element.value === 'STD-66001')
      expect(studentCodeInput.attributes('disabled')).toBeDefined()
    })

    it('submits updated student fields and redirects to detail view', async () => {
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)
      const updateSpy = vi.spyOn(studentsService, 'update').mockResolvedValue({
        ...sampleStudent,
        person: { ...sampleStudent.person, first_name: 'สมชายวิชญ์' },
      })
      const pushSpy = vi.spyOn(router, 'push')

      const wrapper = mount(StudentEditView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      await wrapper.find('#first_name').setValue('สมชายวิชญ์')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(updateSpy).toHaveBeenCalledWith(
        'std-uuid-12345',
        expect.objectContaining({
          person: expect.objectContaining({
            first_name: 'สมชายวิชญ์',
            last_name: 'สุขใจ',
          }),
        })
      )

      expect(pushSpy).toHaveBeenCalledWith({
        path: '/students/std-uuid-12345',
        query: { updated: 'true' },
      })
    })

    it('displays server validation errors on edit form', async () => {
      vi.spyOn(studentsService, 'get').mockResolvedValue(sampleStudent)
      vi.spyOn(studentsService, 'update').mockRejectedValue({
        status: 422,
        message: 'Validation failed',
        fields: {
          'person.last_name': ['นามสกุลไม่ถูกต้อง'],
        },
      })

      const wrapper = mount(StudentEditView, {
        global: {
          plugins: [router],
        },
      })
      await flushPromises()

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('นามสกุลไม่ถูกต้อง')
    })
  })

  // ================= 6. Badges & Boundary Invariants =================
  describe('6. Badges & Boundary Invariants', () => {
    it('AcademicStatusBadge renders all canonical academic statuses correctly', () => {
      const statuses = [
        { status: 'ACTIVE', label: 'กำลังศึกษา' },
        { status: 'LEAVE', label: 'ลาพักการเรียน' },
        { status: 'SUSPENDED', label: 'พักการเรียน' },
        { status: 'WITHDRAWN', label: 'ลาออก' },
        { status: 'GRADUATED', label: 'สำเร็จการศึกษา' },
        { status: 'EXPELLED', label: 'พ้นสภาพ' },
        { status: 'UNKNOWN', label: 'ไม่ทราบสถานะ' },
      ]

      for (const { status, label } of statuses) {
        const wrapper = mount(AcademicStatusBadge, { props: { status } })
        expect(wrapper.text()).toContain(label)
      }
    })

    it('EducationLevelBadge renders all canonical education levels correctly', () => {
      const levels = [
        { level: 'VOC_CERT', label: 'ปวช.' },
        { level: 'HIGH_VOC_CERT', label: 'ปวส.' },
        { level: 'SHORT_COURSE', label: 'ระยะสั้น' },
        { level: 'OTHER', label: 'อื่นๆ' },
      ]

      for (const { level, label } of levels) {
        const wrapper = mount(EducationLevelBadge, { props: { level } })
        expect(wrapper.text()).toContain(label)
      }
    })
  })
})
