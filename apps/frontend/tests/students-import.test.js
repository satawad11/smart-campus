import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { studentImportService } from '@/services/students'
import { useAuthStore } from '@/stores/auth'
import StudentListView from '@/views/students/StudentListView.vue'
import StudentImportView from '@/views/students/StudentImportView.vue'
import ImportUploadStep from '@/components/students/import/ImportUploadStep.vue'
import ImportPreviewStep from '@/components/students/import/ImportPreviewStep.vue'
import ImportErrorsTable from '@/components/students/import/ImportErrorsTable.vue'
import ImportProcessingStep from '@/components/students/import/ImportProcessingStep.vue'
import ImportSummaryStep from '@/components/students/import/ImportSummaryStep.vue'

describe('STD-017: Student Import Wizard UI', () => {
  let router

  const sampleJob = {
    uuid: 'job-uuid-11111',
    type: 'STUDENT',
    status: 'VALIDATED',
    conflict_mode: 'CREATE_ONLY',
    total_rows: 10,
    valid_rows: 8,
    invalid_rows: 2,
    success_rows: 0,
    failed_rows: 0,
    skipped_rows: 0,
    started_at: null,
    completed_at: null,
    created_at: '2024-05-15T08:30:00.000000Z',
    updated_at: '2024-05-15T08:30:00.000000Z',
    creator: {
      uuid: 'user-uuid-admin',
      username: 'admin',
    },
  }

  const samplePreviewData = {
    job: sampleJob,
    summary: {
      total_rows: 10,
      valid_rows: 8,
      invalid_rows: 2,
      conflict_rows: 1,
      identity_mismatches: 1,
      conflict_mode: 'CREATE_ONLY',
      is_ready_for_execution: true,
    },
    sample_rows: [
      {
        student_code: 'STD-66001',
        first_name: 'สมชาย',
        last_name: 'สุขใจ',
        department_code: 'IT',
        program_code: 'IT-DEV',
        classroom_code: 'IT-101',
        education_level: 'VOC_CERT',
        year_level: 1,
        academic_status: 'ACTIVE',
        _row_number: 2,
        _has_conflict: false,
        _conflict_type: null,
      },
      {
        student_code: 'STD-66002',
        first_name: 'สมหญิง',
        last_name: 'ใจดี',
        department_code: 'IT',
        program_code: 'IT-DEV',
        classroom_code: 'IT-101',
        education_level: 'VOC_CERT',
        year_level: 1,
        academic_status: 'ACTIVE',
        _row_number: 3,
        _has_conflict: true,
        _conflict_type: 'IDENTITY_MISMATCH',
      },
    ],
    sample_errors: [],
  }

  const sampleErrors = [
    {
      row_number: 3,
      field: 'student_code',
      value: 'STD-66002',
      error_code: 'IDENTITY_MISMATCH',
      error_message: 'Student code exists but person identity does not match existing record.',
      created_at: '2024-05-15T08:30:00.000000Z',
    },
    {
      row_number: 5,
      field: 'education_level',
      value: 'INVALID_LEVEL',
      error_code: 'INVALID_EDUCATION_LEVEL',
      error_message: 'Education level is invalid.',
      created_at: '2024-05-15T08:30:00.000000Z',
    },
  ]

  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/students', component: StudentListView },
        { path: '/students/import', name: 'students.import', component: StudentImportView },
      ],
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('1. Permission & Navigation (student.import)', () => {
    it('shows Import button in StudentListView when user has student.import permission', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'user-admin',
        username: 'admin',
        permissions: ['student.view', 'student.import'],
      }

      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [],
          meta: { pagination: { total: 0, current_page: 1, last_page: 1, per_page: 20 } },
        },
      })

      router.push('/students')
      await router.isReady()

      const wrapper = mount(StudentListView, {
        global: { plugins: [router] },
      })
      await flushPromises()

      const importLink = wrapper.find('a[href="/students/import"]')
      expect(importLink.exists()).toBe(true)
      expect(importLink.text()).toContain('นำเข้าข้อมูล (CSV)')
    })

    it('hides Import button in StudentListView when user lacks student.import permission', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'user-viewer',
        username: 'viewer',
        permissions: ['student.view'], // no student.import
      }

      vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [],
          meta: { pagination: { total: 0, current_page: 1, last_page: 1, per_page: 20 } },
        },
      })

      router.push('/students')
      await router.isReady()

      const wrapper = mount(StudentListView, {
        global: { plugins: [router] },
      })
      await flushPromises()

      const importLink = wrapper.find('a[href="/students/import"]')
      expect(importLink.exists()).toBe(false)
    })

    it('displays unauthorized state in StudentImportView when user lacks student.import permission', async () => {
      const auth = useAuthStore()
      auth.user = {
        uuid: 'user-viewer',
        username: 'viewer',
        permissions: ['student.view'], // no student.import
      }

      const wrapper = mount(StudentImportView, {
        global: { plugins: [router] },
      })
      await flushPromises()

      expect(wrapper.text()).toContain('ไม่มีสิทธิ์เข้าถึง')
      expect(wrapper.text()).toContain('student.import')
      expect(wrapper.findComponent(ImportUploadStep).exists()).toBe(false)
    })
  })

  describe('2. Step 1: Upload (ImportUploadStep.vue)', () => {
    it('defaults conflict_mode to CREATE_ONLY', () => {
      const wrapper = mount(ImportUploadStep, {
        global: { plugins: [router] },
      })
      const checkedRadio = wrapper.find('input[type="radio"][value="CREATE_ONLY"]')
      expect(checkedRadio.element.checked).toBe(true)
    })

    it('rejects non-CSV files on client-side selection', async () => {
      const wrapper = mount(ImportUploadStep, {
        global: { plugins: [router] },
      })
      const input = wrapper.find('input[type="file"]')

      const fakeFile = new File(['hello'], 'document.pdf', { type: 'application/pdf' })
      Object.defineProperty(input.element, 'files', { value: [fakeFile] })
      await input.trigger('change')

      expect(wrapper.text()).toContain('รองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น')
    })

    it('rejects files larger than 10MB on client-side selection', async () => {
      const wrapper = mount(ImportUploadStep, {
        global: { plugins: [router] },
      })
      const input = wrapper.find('input[type="file"]')

      // 11MB file
      const fakeBigFile = new File(['a'], 'big.csv', { type: 'text/csv' })
      Object.defineProperty(fakeBigFile, 'size', { value: 11 * 1024 * 1024 })
      Object.defineProperty(input.element, 'files', { value: [fakeBigFile] })
      await input.trigger('change')

      expect(wrapper.text()).toContain('ขนาดไฟล์เกินกำหนด')
    })

    it('submits FormData with file and selected conflict_mode and emits uploaded', async () => {
      const uploadSpy = vi.spyOn(studentImportService, 'upload').mockResolvedValueOnce(sampleJob)

      const wrapper = mount(ImportUploadStep, {
        global: { plugins: [router] },
      })
      const input = wrapper.find('input[type="file"]')

      const validFile = new File(['student_code,first_name\nSTD-1,John'], 'students.csv', {
        type: 'text/csv',
      })
      Object.defineProperty(input.element, 'files', { value: [validFile] })
      await input.trigger('change')

      // Select UPDATE_EXISTING
      await wrapper.find('input[value="UPDATE_EXISTING"]').setChecked()

      // Click upload
      const submitBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('อัปโหลดและตรวจสอบ'))
      expect(submitBtn).toBeDefined()
      await submitBtn.trigger('click')
      await flushPromises()

      expect(uploadSpy).toHaveBeenCalledTimes(1)
      const sentFormData = uploadSpy.mock.calls[0][0]
      expect(sentFormData.get('conflict_mode')).toBe('UPDATE_EXISTING')
      expect(sentFormData.get('file')).toBeDefined()

      // Invariant: no internal/actor fields in FormData
      expect(sentFormData.get('status')).toBeNull()
      expect(sentFormData.get('uuid')).toBeNull()
      expect(sentFormData.get('created_by')).toBeNull()

      expect(wrapper.emitted('uploaded')).toBeTruthy()
      expect(wrapper.emitted('uploaded')[0][0]).toEqual(sampleJob)
    })
  })

  describe('3. Step 2: Preview & Validation (ImportPreviewStep.vue)', () => {
    it('renders KPI summary cards and sample preview table rows', () => {
      const wrapper = mount(ImportPreviewStep, {
        props: {
          job: sampleJob,
          previewData: samplePreviewData,
        },
        global: { plugins: [router] },
      })

      expect(wrapper.text()).toContain('10') // Total rows
      expect(wrapper.text()).toContain('8') // Valid rows
      expect(wrapper.text()).toContain('2') // Invalid rows
      expect(wrapper.text()).toContain('STD-66001')
      expect(wrapper.text()).toContain('สมชาย สุขใจ')
      expect(wrapper.text()).toContain('พร้อมนำเข้า')

      // Conflict row
      expect(wrapper.text()).toContain('STD-66002')
      expect(wrapper.text()).toContain('สมหญิง ใจดี')
      expect(wrapper.text()).toContain('ข้อมูลตัวบุคคลไม่ตรงกัน (Identity Mismatch)')

      // Zero internal integer IDs
      expect(wrapper.text()).not.toMatch(/student_profile_id|changed_by_user_id|person_id/)
      // No door access policy words
      expect(wrapper.text()).not.toMatch(/ALLOW|DENY|REVIEW/)
    })

    it('emits reviewErrors when clicking view errors button', async () => {
      const wrapper = mount(ImportPreviewStep, {
        props: {
          job: sampleJob,
          previewData: samplePreviewData,
        },
        global: { plugins: [router] },
      })

      const errorBtn = wrapper.findAll('button').find((b) => b.text().includes('ตรวจสอบข้อผิดพลาด'))
      expect(errorBtn).toBeDefined()
      await errorBtn.trigger('click')

      expect(wrapper.emitted('reviewErrors')).toBeTruthy()
    })

    it('emits proceed when clicking proceed button', async () => {
      const wrapper = mount(ImportPreviewStep, {
        props: {
          job: sampleJob,
          previewData: samplePreviewData,
        },
        global: { plugins: [router] },
      })

      const proceedBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('ยืนยันและไปขั้นตอนประมวลผล'))
      expect(proceedBtn).toBeDefined()
      await proceedBtn.trigger('click')

      expect(wrapper.emitted('proceed')).toBeTruthy()
    })
  })

  describe('4. Step 3: Errors Review (ImportErrorsTable.vue)', () => {
    it('fetches and renders paginated errors with Thai labels', async () => {
      vi.spyOn(studentImportService, 'errors').mockResolvedValueOnce({
        data: sampleErrors,
        meta: {
          pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 20,
            total: 2,
          },
        },
      })

      const wrapper = mount(ImportErrorsTable, {
        props: {
          jobUuid: 'job-uuid-11111',
        },
        global: { plugins: [router] },
      })
      await flushPromises()

      expect(studentImportService.errors).toHaveBeenCalledWith('job-uuid-11111', {
        page: 1,
        per_page: 20,
      })

      expect(wrapper.text()).toContain('3') // Row number
      expect(wrapper.text()).toContain('student_code')
      expect(wrapper.text()).toContain('STD-66002')
      expect(wrapper.text()).toContain('ข้อมูลตัวบุคคลไม่ตรงกับรหัสเดิม (Identity Mismatch)')
      expect(wrapper.text()).toContain('5') // Row number
      expect(wrapper.text()).toContain('INVALID_LEVEL')
      expect(wrapper.text()).toContain('ระดับการศึกษาไม่ถูกต้อง')
    })
  })

  describe('5. Step 4 & 5: Processing & Polling (ImportProcessingStep.vue)', () => {
    it('calls process without conflict_mode override and starts polling', async () => {
      const processSpy = vi.spyOn(studentImportService, 'process').mockResolvedValueOnce({
        ...sampleJob,
        status: 'PROCESSING',
      })

      vi.spyOn(studentImportService, 'show').mockResolvedValueOnce({
        ...sampleJob,
        status: 'COMPLETED',
        success_rows: 8,
        failed_rows: 0,
        skipped_rows: 0,
      })

      const wrapper = mount(ImportProcessingStep, {
        props: {
          job: sampleJob,
        },
        global: { plugins: [router] },
      })

      const startBtn = wrapper
        .findAll('button')
        .find((b) => b.text().includes('ยืนยันและเริ่มประมวลผล'))
      expect(startBtn).toBeDefined()
      await startBtn.trigger('click')
      await flushPromises()

      expect(processSpy).toHaveBeenCalledWith('job-uuid-11111')
      // Ensure no conflict_mode was sent to process
      expect(processSpy.mock.calls[0].length).toBe(1)
    })

    it('stops polling and emits completed when job status becomes COMPLETED', async () => {
      vi.useFakeTimers()

      vi.spyOn(studentImportService, 'process').mockResolvedValueOnce({
        ...sampleJob,
        status: 'PROCESSING',
      })

      const showMock = vi.spyOn(studentImportService, 'show')
      showMock.mockResolvedValueOnce({
        ...sampleJob,
        status: 'COMPLETED',
        success_rows: 8,
        skipped_rows: 0,
        failed_rows: 0,
      })

      const wrapper = mount(ImportProcessingStep, {
        props: {
          job: { ...sampleJob, status: 'PROCESSING' },
        },
        global: { plugins: [router] },
      })

      // Advance timer for polling interval
      await vi.advanceTimersByTimeAsync(2000)
      await flushPromises()

      expect(showMock).toHaveBeenCalledWith('job-uuid-11111')
      expect(wrapper.emitted('completed')).toBeTruthy()
    })
  })

  describe('6. Step 6: Summary & Counters (ImportSummaryStep.vue)', () => {
    it('renders completion counters with distinct skipped vs success rows', () => {
      const completedJob = {
        ...sampleJob,
        status: 'COMPLETED',
        conflict_mode: 'SKIP_EXISTING',
        total_rows: 10,
        valid_rows: 8,
        invalid_rows: 2,
        success_rows: 5,
        skipped_rows: 3,
        failed_rows: 0,
        completed_at: '2024-05-15T08:35:00.000000Z',
      }

      const wrapper = mount(ImportSummaryStep, {
        props: {
          job: completedJob,
        },
        global: { plugins: [router] },
      })

      expect(wrapper.text()).toContain('การนำเข้าข้อมูลเสร็จสมบูรณ์')
      expect(wrapper.text()).toContain('5') // Success
      expect(wrapper.text()).toContain('3') // Skipped
      expect(wrapper.text()).toContain('0') // Failed
      expect(wrapper.text()).toContain('2') // Invalid
      expect(wrapper.text()).toContain('ข้ามรายการเดิม (Skipped)')
      expect(wrapper.text()).toContain('สำเร็จ (Success)')
    })

    it('renders failed job summary safely without stack traces', () => {
      const failedJob = {
        ...sampleJob,
        status: 'FAILED',
        total_rows: 10,
        valid_rows: 0,
        invalid_rows: 10,
      }

      const wrapper = mount(ImportSummaryStep, {
        props: {
          job: failedJob,
        },
        global: { plugins: [router] },
      })

      expect(wrapper.text()).toContain('การนำเข้าข้อมูลล้มเหลว (Import Failed)')
      expect(wrapper.text()).not.toMatch(/Exception|Stack trace|vendor\/laravel/i)
    })
  })
})
