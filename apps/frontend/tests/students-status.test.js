import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { studentsService } from '@/services/students'
import { useAuthStore } from '@/stores/auth'
import AcademicStatusBadge from '@/components/students/AcademicStatusBadge.vue'
import ChangeStatusModal from '@/components/students/ChangeStatusModal.vue'
import StatusHistoryTimeline from '@/components/students/StatusHistoryTimeline.vue'
import StudentDetailView from '@/views/students/StudentDetailView.vue'

describe('STD-016: Student Academic Status and History UI', () => {
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

  const sampleHistoryList = [
    {
      uuid: 'hist-uuid-002',
      old_status: 'ACTIVE',
      new_status: 'LEAVE',
      effective_from: '2024-06-01',
      effective_to: null,
      reason: 'ขอลาพักการเรียนเพื่อรักษาพยาบาล',
      source: 'MANUAL',
      changed_by: {
        uuid: 'user-uuid-admin',
        username: 'registrar_admin',
        display_name: 'เจ้าหน้าที่ ทะเบียน',
      },
      created_at: '2024-06-01T10:00:00.000000Z',
    },
    {
      uuid: 'hist-uuid-001',
      old_status: null,
      new_status: 'ACTIVE',
      effective_from: '2024-05-15',
      effective_to: '2024-06-01',
      reason: 'ขึ้นทะเบียนนักเรียนใหม่',
      source: 'IMPORT',
      changed_by: null,
      created_at: '2024-05-15T08:30:00.000000Z',
    },
  ]

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/students', component: { template: '<div>Students List</div>' } },
        { path: '/students/:uuid', component: StudentDetailView },
        { path: '/students/:uuid/edit', component: { template: '<div>Student Edit</div>' } },
      ],
    })
  })

  describe('1. AcademicStatusBadge Component', () => {
    it('renders all canonical academic statuses with Thai labels', () => {
      const cases = [
        { status: 'ACTIVE', label: 'กำลังศึกษา' },
        { status: 'LEAVE', label: 'ลาพักการเรียน' },
        { status: 'SUSPENDED', label: 'พักการเรียน' },
        { status: 'WITHDRAWN', label: 'ลาออก' },
        { status: 'GRADUATED', label: 'สำเร็จการศึกษา' },
        { status: 'EXPELLED', label: 'พ้นสภาพ' },
      ]

      for (const { status, label } of cases) {
        const wrapper = mount(AcademicStatusBadge, {
          props: { status },
        })
        expect(wrapper.text()).toContain(label)
        // Must not contain access policy words
        expect(wrapper.text()).not.toMatch(/ALLOW|DENY|REVIEW|GRANT|REJECT/i)
      }
    })

    it('handles fallback for unknown status gracefully', () => {
      const wrapper = mount(AcademicStatusBadge, {
        props: { status: 'CUSTOM_STATUS' },
      })
      expect(wrapper.text()).toContain('CUSTOM_STATUS')
    })
  })

  describe('2. studentsService status methods', () => {
    it('changeStatus calls PATCH /students/{uuid}/status with clean payload', async () => {
      vi.spyOn(api, 'patch').mockResolvedValueOnce({
        data: {
          data: {
            ...sampleStudent,
            academic_status: 'LEAVE',
          },
        },
      })

      const payload = {
        status: 'LEAVE',
        reason: 'พักการเรียน',
      }
      const result = await studentsService.changeStatus('std-uuid-12345', payload)

      expect(api.patch).toHaveBeenCalledWith('/students/std-uuid-12345/status', payload)
      expect(result.academic_status).toBe('LEAVE')
    })

    it('getStatusHistory calls GET /students/{uuid}/status-history with pagination query params', async () => {
      vi.spyOn(api, 'get').mockResolvedValueOnce({
        data: {
          data: sampleHistoryList,
          meta: {
            pagination: {
              current_page: 1,
              last_page: 2,
              per_page: 5,
              total: 8,
            },
          },
        },
      })

      const result = await studentsService.getStatusHistory('std-uuid-12345', {
        page: 1,
        per_page: 5,
      })

      expect(api.get).toHaveBeenCalledWith('/students/std-uuid-12345/status-history', {
        params: { page: 1, per_page: 5 },
      })
      expect(result.data).toHaveLength(2)
      expect(result.meta.pagination.total).toBe(8)
    })
  })

  describe('3. ChangeStatusModal Component', () => {
    it('does not render when show is false', () => {
      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: false,
          student: sampleStudent,
        },
      })
      expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    })

    it('renders student info and status options when show is true', () => {
      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: true,
          student: sampleStudent,
        },
      })
      expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
      expect(wrapper.text()).toContain('สมชาย สุขใจ')
      expect(wrapper.text()).toContain('STD-66001')
      expect(wrapper.text()).toContain('เปลี่ยนสถานะการศึกษา (Academic Status)')

      const currentOpt = wrapper.find('option[value="ACTIVE"]')
      expect(currentOpt.exists()).toBe(true)
      expect(currentOpt.element.disabled).toBe(true)
    })

    it('shows warning alert when a critical status is selected', async () => {
      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: true,
          student: sampleStudent,
        },
      })

      expect(wrapper.text()).not.toContain('คำเตือน:')

      const select = wrapper.find('select#modal_academic_status')
      await select.setValue('EXPELLED')

      expect(wrapper.text()).toContain('คำเตือน:')
      expect(wrapper.text()).toContain('สถานะที่เลือกมีผลต่อประวัติการศึกษาของนักเรียน')
    })

    it('submits clean payload without metadata/person fields', async () => {
      const changeSpy = vi.spyOn(studentsService, 'changeStatus').mockResolvedValueOnce({
        ...sampleStudent,
        academic_status: 'LEAVE',
      })

      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: true,
          student: sampleStudent,
        },
      })

      await wrapper.find('select#modal_academic_status').setValue('LEAVE')
      await wrapper.find('textarea#modal_status_reason').setValue('มีความจำเป็นทางครอบครัว')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(changeSpy).toHaveBeenCalledWith('std-uuid-12345', {
        status: 'LEAVE',
        reason: 'มีความจำเป็นทางครอบครัว',
      })
      expect(wrapper.emitted('success')).toBeTruthy()
      expect(wrapper.emitted('close')).toBeTruthy()
    })

    it('handles STUDENT_STATUS_NO_OP error correctly', async () => {
      vi.spyOn(studentsService, 'changeStatus').mockRejectedValueOnce({
        code: 'STUDENT_STATUS_NO_OP',
        message: 'Student already has academic status ACTIVE',
      })

      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: true,
          student: { ...sampleStudent, academic_status: 'LEAVE' },
        },
      })

      await wrapper.find('select#modal_academic_status').setValue('ACTIVE')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('สถานะใหม่ตรงกับสถานะปัจจุบัน ไม่มีการเปลี่ยนแปลง')
    })

    it('displays validation errors from API response', async () => {
      vi.spyOn(studentsService, 'changeStatus').mockRejectedValueOnce({
        code: 'VALIDATION_ERROR',
        message: 'The given data was invalid.',
        errors: {
          reason: ['เหตุผลต้องมีความยาวไม่เกิน 1,000 ตัวอักษร'],
        },
      })

      const wrapper = mount(ChangeStatusModal, {
        props: {
          show: true,
          student: sampleStudent,
        },
      })

      await wrapper.find('select#modal_academic_status').setValue('SUSPENDED')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('เหตุผลต้องมีความยาวไม่เกิน 1,000 ตัวอักษร')
    })
  })

  describe('4. StatusHistoryTimeline Component', () => {
    it('fetches and displays status history timeline items', async () => {
      vi.spyOn(studentsService, 'getStatusHistory').mockResolvedValueOnce({
        data: sampleHistoryList,
        meta: {
          pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 2,
          },
        },
      })

      const wrapper = mount(StatusHistoryTimeline, {
        props: {
          studentUuid: 'std-uuid-12345',
        },
      })

      await flushPromises()

      expect(studentsService.getStatusHistory).toHaveBeenCalledWith('std-uuid-12345', {
        page: 1,
        per_page: 5,
      })

      expect(wrapper.text()).toContain('ประวัติการเปลี่ยนสถานะการศึกษา')
      expect(wrapper.text()).toContain('ขอลาพักการเรียนเพื่อรักษาพยาบาล')
      expect(wrapper.text()).toContain('เจ้าหน้าที่ ทะเบียน')
      expect(wrapper.text()).toContain('เจ้าหน้าที่บันทึก')
      expect(wrapper.text()).toContain('นำเข้าข้อมูล')

      // Zero internal integer database IDs
      expect(wrapper.text()).not.toMatch(/student_profile_id|changed_by_user_id/)
    })

    it('displays empty state when student has no status history', async () => {
      vi.spyOn(studentsService, 'getStatusHistory').mockResolvedValueOnce({
        data: [],
        meta: {
          pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 5,
            total: 0,
          },
        },
      })

      const wrapper = mount(StatusHistoryTimeline, {
        props: {
          studentUuid: 'std-uuid-12345',
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('ยังไม่มีบันทึกประวัติการเปลี่ยนสถานะ')
    })

    it('supports pagination interactions', async () => {
      const historyMock = vi.spyOn(studentsService, 'getStatusHistory')
      historyMock
        .mockResolvedValueOnce({
          data: sampleHistoryList,
          meta: {
            pagination: {
              current_page: 1,
              last_page: 2,
              per_page: 5,
              total: 7,
            },
          },
        })
        .mockResolvedValueOnce({
          data: [sampleHistoryList[0]],
          meta: {
            pagination: {
              current_page: 2,
              last_page: 2,
              per_page: 5,
              total: 7,
            },
          },
        })

      const wrapper = mount(StatusHistoryTimeline, {
        props: {
          studentUuid: 'std-uuid-12345',
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('แสดงหน้า 1 จาก 2')
      const nextButton = wrapper.findAll('button').find((b) => b.text().includes('ถัดไป'))
      expect(nextButton).toBeDefined()
      await nextButton.trigger('click')
      await flushPromises()

      expect(historyMock).toHaveBeenCalledWith('std-uuid-12345', {
        page: 2,
        per_page: 5,
      })
      expect(wrapper.text()).toContain('แสดงหน้า 2 จาก 2')
    })
  })

  describe('5. StudentDetailView Integration', () => {
    it('shows Change Status button only when user has student.status.change permission', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'user-admin',
        username: 'admin',
        permissions: ['student.view', 'student.update'], // No status change permission
      }

      vi.spyOn(studentsService, 'get').mockResolvedValueOnce(sampleStudent)
      vi.spyOn(studentsService, 'getStatusHistory').mockResolvedValueOnce({
        data: sampleHistoryList,
        meta: { pagination: { current_page: 1, last_page: 1, per_page: 5, total: 2 } },
      })

      router.push('/students/std-uuid-12345')
      await router.isReady()

      const wrapper = mount(StudentDetailView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('สมชาย สุขใจ')
      expect(wrapper.text()).toContain('แก้ไขข้อมูล')

      let changeStatusBtn = wrapper
        .findAll('button')
        .find((b) => b.text().trim() === 'เปลี่ยนสถานะการศึกษา')
      expect(changeStatusBtn).toBeUndefined()

      // Grant permission
      authStore.user = {
        uuid: 'user-admin',
        username: 'admin',
        permissions: ['student.view', 'student.update', 'student.status.change'],
      }
      await wrapper.vm.$nextTick()

      changeStatusBtn = wrapper
        .findAll('button')
        .find((b) => b.text().trim() === 'เปลี่ยนสถานะการศึกษา')
      expect(changeStatusBtn).toBeDefined()
    })

    it('opens modal on click and updates student status on success', async () => {
      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'user-admin',
        username: 'admin',
        permissions: ['student.view', 'student.status.change'],
      }

      vi.spyOn(studentsService, 'get').mockResolvedValueOnce(sampleStudent)
      vi.spyOn(studentsService, 'getStatusHistory').mockResolvedValue({
        data: sampleHistoryList,
        meta: { pagination: { current_page: 1, last_page: 1, per_page: 5, total: 2 } },
      })

      router.push('/students/std-uuid-12345')
      await router.isReady()

      const wrapper = mount(StudentDetailView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      const changeStatusBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('เปลี่ยนสถานะการศึกษา'))
      expect(changeStatusBtn).toBeDefined()
      await changeStatusBtn.trigger('click')

      expect(wrapper.findComponent(ChangeStatusModal).props('show')).toBe(true)

      // Simulate modal success event
      const updatedStudent = {
        ...sampleStudent,
        academic_status: 'GRADUATED',
        status_start_date: '2025-03-30',
        status_end_date: null,
      }
      wrapper.findComponent(ChangeStatusModal).vm.$emit('success', updatedStudent)
      await flushPromises()

      expect(wrapper.text()).toContain('เปลี่ยนสถานะการศึกษาเรียบร้อยแล้ว')
      expect(wrapper.text()).toContain('สำเร็จการศึกษา')
    })
  })
})
