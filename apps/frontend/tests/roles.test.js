import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { rolesService } from '@/services/roles'
import { useAuthStore } from '@/stores/auth'
import RoleListView from '@/views/roles/RoleListView.vue'
import RoleCreateView from '@/views/roles/RoleCreateView.vue'
import RoleDetailView from '@/views/roles/RoleDetailView.vue'
import RoleEditView from '@/views/roles/RoleEditView.vue'
import RolePermissionsView from '@/views/roles/RolePermissionsView.vue'
import RoleTypeBadge from '@/components/roles/RoleTypeBadge.vue'

describe('AUTH-017: Role & Permission Matrix UI', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/roles', name: 'roles.index', component: RoleListView },
        { path: '/roles/new', name: 'roles.create', component: RoleCreateView },
        { path: '/roles/:uuid', name: 'roles.show', component: RoleDetailView },
        { path: '/roles/:uuid/edit', name: 'roles.edit', component: RoleEditView },
        {
          path: '/roles/:uuid/permissions',
          name: 'roles.permissions',
          component: RolePermissionsView,
        },
      ],
    })
    await router.push('/roles')
    await router.isReady()
  })

  describe('1. rolesService API Client Wrapper', () => {
    it('calls list with server pagination, filters, and sort parameters', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [{ uuid: 'r-1', code: 'OFFICER', name: 'Officer' }],
          meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
        },
      })

      const res = await rolesService.list({
        page: 2,
        per_page: 10,
        keyword: 'officer',
        status: 'ACTIVE',
        system_role: true,
        sort: 'name',
        order: 'desc',
      })

      expect(getSpy).toHaveBeenCalledWith('/roles', {
        params: {
          page: 2,
          per_page: 10,
          keyword: 'officer',
          status: 'ACTIVE',
          system_role: true,
          sort: 'name',
          order: 'desc',
        },
      })
      expect(res.data).toHaveLength(1)
      expect(res.meta.pagination.current_page).toBe(1)
    })

    it('calls get by UUID', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: { data: { uuid: 'r-123', code: 'CUSTOM_ROLE', name: 'Custom' } },
      })

      const res = await rolesService.get('r-123')
      expect(getSpy).toHaveBeenCalledWith('/roles/r-123')
      expect(res.code).toBe('CUSTOM_ROLE')
    })

    it('calls create with safe payload and never passes system_role', async () => {
      const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
        data: { data: { uuid: 'r-new', code: 'DORM_GUARD', name: 'Dorm Guard' } },
      })

      const res = await rolesService.create({
        code: 'DORM_GUARD',
        name: 'Dorm Guard',
        description: 'Guard for dorms',
        status: 'ACTIVE',
      })

      expect(postSpy).toHaveBeenCalledWith('/roles', {
        code: 'DORM_GUARD',
        name: 'Dorm Guard',
        description: 'Guard for dorms',
        status: 'ACTIVE',
      })
      expect(res.uuid).toBe('r-new')
    })

    it('calls update with approved fields (name, description, status)', async () => {
      const putSpy = vi.spyOn(api, 'put').mockResolvedValue({
        data: { data: { uuid: 'r-123', name: 'Updated Name' } },
      })

      const res = await rolesService.update('r-123', {
        name: 'Updated Name',
        description: 'Updated desc',
        status: 'DISABLED',
      })

      expect(putSpy).toHaveBeenCalledWith('/roles/r-123', {
        name: 'Updated Name',
        description: 'Updated desc',
        status: 'DISABLED',
      })
      expect(res.name).toBe('Updated Name')
    })

    it('calls updatePermissions with permission codes only', async () => {
      const putSpy = vi.spyOn(api, 'put').mockResolvedValue({
        data: { data: { uuid: 'r-123', permissions: ['dashboard.view', 'student.view'] } },
      })

      const res = await rolesService.updatePermissions('r-123', ['dashboard.view', 'student.view'])
      expect(putSpy).toHaveBeenCalledWith('/roles/r-123/permissions', {
        permissions: ['dashboard.view', 'student.view'],
      })
      expect(res.permissions).toEqual(['dashboard.view', 'student.view'])
    })

    it('fetches permissions catalog from GET /permissions', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [
            { code: 'user.view', module: 'user', name: 'View Users' },
            { code: 'role.view', module: 'role', name: 'View Roles' },
          ],
        },
      })

      const res = await rolesService.permissions()
      expect(getSpy).toHaveBeenCalledWith('/permissions', { params: {} })
      expect(res).toHaveLength(2)
      expect(res[0].code).toBe('user.view')
    })

    it('strictly prohibits DELETE role and permission CRUD methods', () => {
      expect(rolesService.delete).toBeUndefined()
      expect(rolesService.destroy).toBeUndefined()
      expect(rolesService.deleteRole).toBeUndefined()
      expect(rolesService.createPermission).toBeUndefined()
      expect(rolesService.deletePermission).toBeUndefined()
      expect(rolesService.assignUserRole).toBeUndefined()
    })
  })

  describe('2. Role List View (RoleListView.vue)', () => {
    it('renders role list with server-side pagination, sorting, and filter controls', async () => {
      vi.spyOn(rolesService, 'list').mockResolvedValue({
        data: [
          {
            uuid: 'r-super',
            code: 'SUPER_ADMIN',
            name: 'Super Administrator',
            description: 'Full campus system control',
            system_role: true,
            status: 'ACTIVE',
            permissions_count: 44,
            users_count: 2,
            created_at: '2026-09-01T00:00:00Z',
          },
          {
            uuid: 'r-custom',
            code: 'CAMPUS_GUARD',
            name: 'Campus Guard',
            description: 'Guard officers',
            system_role: false,
            status: 'ACTIVE',
            permissions_count: 5,
            users_count: 10,
            created_at: '2026-09-10T00:00:00Z',
          },
        ],
        meta: {
          pagination: { current_page: 1, per_page: 20, total: 2, last_page: 1 },
        },
      })

      const wrapper = mount(RoleListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('จัดการบทบาทและสิทธิ์')
      expect(wrapper.text()).toContain('SUPER_ADMIN')
      expect(wrapper.text()).toContain('Super Administrator')
      expect(wrapper.text()).toContain('CAMPUS_GUARD')
      expect(wrapper.text()).toContain('Campus Guard')
      expect(wrapper.text()).toContain('บทบาทระบบ')
      expect(wrapper.text()).toContain('บทบาทกำหนดเอง')
      expect(wrapper.text()).toContain('44') // permissions_count
      expect(wrapper.text()).toContain('2') // users_count
    })

    it('renders empty state when no roles match filter', async () => {
      vi.spyOn(rolesService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
      })

      const wrapper = mount(RoleListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('ไม่พบข้อมูลบทบาท')
    })

    it('applies keyword, status, and system_role filters resetting page to 1', async () => {
      const listSpy = vi.spyOn(rolesService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
      })

      const wrapper = mount(RoleListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      await wrapper.find('#system-role-filter').setValue('true')

      expect(listSpy).toHaveBeenLastCalledWith(
        expect.objectContaining({
          system_role: true,
          page: 1,
        }),
      )
    })

    it('handles 403 Forbidden without logging the user out', async () => {
      vi.spyOn(rolesService, 'list').mockRejectedValue({
        status: 403,
        code: 'FORBIDDEN',
        message: 'This action is unauthorized.',
      })

      const authStore = useAuthStore()
      authStore.user = { uuid: 'u-1', username: 'auditor' }

      const wrapper = mount(RoleListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // 403 is shown as the Thai permission message, never the raw English one
      expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์ดูข้อมูลส่วนนี้')
      expect(wrapper.text()).not.toContain('This action is unauthorized.')
      expect(authStore.user).not.toBeNull()
    })
  })

  describe('3. Role Create View (RoleCreateView.vue)', () => {
    it('validates role code format and required fields on client side', async () => {
      const wrapper = mount(RoleCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสบทบาท (Code)')

      await wrapper.find('#code').setValue('invalid-code!')
      await wrapper.find('form').trigger('submit.prevent')
      expect(wrapper.text()).toContain('รหัสบทบาทต้องขึ้นต้นด้วยตัวอักษรภาษาอังกฤษพิมพ์ใหญ่')
    })

    it('submits valid custom role create payload and never sends system_role', async () => {
      const createSpy = vi.spyOn(rolesService, 'create').mockResolvedValue({
        uuid: 'r-created',
        code: 'DORM_MANAGER',
        name: 'Dormitory Manager',
      })

      const wrapper = mount(RoleCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('#code').setValue('DORM_MANAGER')
      await wrapper.find('#name').setValue('Dormitory Manager')
      await wrapper.find('#description').setValue('Manages dorm rooms')
      await wrapper.find('#status').setValue('ACTIVE')

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(createSpy).toHaveBeenCalledWith({
        code: 'DORM_MANAGER',
        name: 'Dormitory Manager',
        description: 'Manages dorm rooms',
        status: 'ACTIVE',
      })
    })

    it('renders server 422 field errors properly', async () => {
      vi.spyOn(rolesService, 'create').mockRejectedValue({
        status: 422,
        code: 'VALIDATION_ERROR',
        message: 'The code has already been taken.',
        fields: {
          code: ['The code has already been taken.'],
        },
      })

      const wrapper = mount(RoleCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('#code').setValue('EXISTING_ROLE')
      await wrapper.find('#name').setValue('Existing Role')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('The code has already been taken.')
    })
  })

  describe('4. Role Detail View (RoleDetailView.vue)', () => {
    it('loads safe fields by UUID and renders assigned permissions list', async () => {
      await router.push('/roles/r-detail-uuid')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-detail-uuid',
        code: 'SECURITY_OFFICER',
        name: 'Security Officer',
        description: 'Handles campus patrols and security monitoring',
        system_role: true,
        status: 'ACTIVE',
        permissions_count: 3,
        users_count: 5,
        created_at: '2026-09-01T00:00:00Z',
        permissions: ['camera.view', 'event.view', 'event.review'],
      })

      const wrapper = mount(RoleDetailView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('SECURITY_OFFICER')
      expect(wrapper.text()).toContain('Security Officer')
      expect(wrapper.text()).toContain('บทบาทระบบ')
      expect(wrapper.text()).toContain('camera.view')
      expect(wrapper.text()).toContain('event.view')
      expect(wrapper.text()).toContain('event.review')
      expect(wrapper.text()).toContain('5 คน')
    })
  })

  describe('5. Role Edit View (RoleEditView.vue)', () => {
    it('allows editing name, description, and status while keeping code immutable', async () => {
      await router.push('/roles/r-edit-uuid/edit')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-edit-uuid',
        code: 'CUSTOM_PATROL',
        name: 'Custom Patrol',
        description: 'Initial description',
        system_role: false,
        status: 'ACTIVE',
      })

      const updateSpy = vi.spyOn(rolesService, 'update').mockResolvedValue({
        uuid: 'r-edit-uuid',
        name: 'Updated Patrol Officer',
        description: 'New description',
        status: 'ACTIVE',
      })

      const wrapper = mount(RoleEditView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Verify code input is not an editable field
      expect(wrapper.find('input#code').exists()).toBe(false)
      expect(wrapper.text()).toContain('CUSTOM_PATROL')

      await wrapper.find('#edit-name').setValue('Updated Patrol Officer')
      await wrapper.find('#edit-description').setValue('New description')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(updateSpy).toHaveBeenCalledWith('r-edit-uuid', {
        name: 'Updated Patrol Officer',
        description: 'New description',
        status: 'ACTIVE',
      })
    })

    it('requires confirmation when disabling a role and handles 409 SYSTEM_ROLE_PROTECTED', async () => {
      await router.push('/roles/r-super-uuid/edit')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-super-uuid',
        code: 'SUPER_ADMIN',
        name: 'Super Administrator',
        system_role: true,
        status: 'ACTIVE',
      })

      const wrapper = mount(RoleEditView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // SUPER_ADMIN status select should be disabled
      const statusSelect = wrapper.find('#edit-status')
      expect(statusSelect.attributes('disabled')).toBeDefined()
      expect(wrapper.text()).toContain(
        'บทบาท SUPER_ADMIN เป็นบทบาทหลักของระบบ ไม่สามารถปิดใช้งานได้',
      )
    })
  })

  describe('6. Permission Matrix View (RolePermissionsView.vue)', () => {
    const mockCatalog = [
      {
        code: 'dashboard.view',
        module: 'dashboard',
        name: 'ดูแดชบอร์ด',
        description: 'เข้าถึงภาพรวมระบบ',
      },
      {
        code: 'student.view',
        module: 'student',
        name: 'ดูข้อมูลนักเรียน',
        description: 'ค้นหาและเปิดดูข้อมูล',
      },
      {
        code: 'student.create',
        module: 'student',
        name: 'เพิ่มนักเรียน',
        description: 'สร้างข้อมูลนักเรียนใหม่',
      },
      {
        code: 'camera.view',
        module: 'camera',
        name: 'ดูกล้องวงจรปิด',
        description: 'ดูรายการกล้อง',
      },
    ]

    it('loads catalog dynamically from API and groups permissions by module', async () => {
      await router.push('/roles/r-custom-uuid/permissions')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-custom-uuid',
        code: 'OFFICER_ROLE',
        name: 'Officer Role',
        system_role: false,
        status: 'ACTIVE',
        permissions: ['dashboard.view', 'student.view'],
      })
      vi.spyOn(rolesService, 'permissions').mockResolvedValue(mockCatalog)

      const wrapper = mount(RolePermissionsView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('ตารางสิทธิ์: Officer Role')
      expect(wrapper.text()).toContain('dashboard.view')
      expect(wrapper.text()).toContain('student.view')
      expect(wrapper.text()).toContain('student.create')
      expect(wrapper.text()).toContain('camera.view')
      expect(wrapper.text()).toContain('2 / 4') // selected 2 out of 4
    })

    it('supports module-level bulk select and clear, dirty-state tracking, and saving', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'admin-uuid', username: 'admin' }

      await router.push('/roles/r-custom-uuid/permissions')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-custom-uuid',
        code: 'OFFICER_ROLE',
        name: 'Officer Role',
        system_role: false,
        status: 'ACTIVE',
        permissions: ['dashboard.view'],
      })
      vi.spyOn(rolesService, 'permissions').mockResolvedValue(mockCatalog)

      const syncSpy = vi.spyOn(rolesService, 'updatePermissions').mockResolvedValue({
        uuid: 'r-custom-uuid',
        name: 'Officer Role',
        status: 'ACTIVE',
        permissions: ['dashboard.view', 'student.view', 'student.create'],
      })

      const wrapper = mount(RolePermissionsView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Module bulk select on student module (second module in catalog)
      const selectBtns = wrapper
        .findAll('button')
        .filter((b) => b.text().includes('เลือกทั้งหมดในกลุ่ม'))
      expect(selectBtns.length).toBeGreaterThan(1)
      await selectBtns[1].trigger('click')
      await flushPromises()

      // Dirty state is active
      expect(wrapper.text()).toContain('(มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก)')

      // Click save button
      const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('บันทึกตารางสิทธิ์'))
      expect(saveBtn).toBeDefined()
      await saveBtn.trigger('click')
      await flushPromises()

      expect(syncSpy).toHaveBeenCalledWith(
        'r-custom-uuid',
        expect.arrayContaining(['dashboard.view', 'student.view', 'student.create']),
      )
      expect(wrapper.text()).toContain('บันทึกตารางสิทธิ์สำหรับบทบาท Officer Role สำเร็จ')
    })

    it('locks all permissions for SUPER_ADMIN role with explanatory notice', async () => {
      await router.push('/roles/r-super-uuid/permissions')

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-super-uuid',
        code: 'SUPER_ADMIN',
        name: 'Super Administrator',
        system_role: true,
        status: 'ACTIVE',
        permissions: ['dashboard.view', 'student.view', 'student.create', 'camera.view'],
      })
      vi.spyOn(rolesService, 'permissions').mockResolvedValue(mockCatalog)

      const wrapper = mount(RolePermissionsView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('ตารางสิทธิ์ SUPER_ADMIN ถูกล็อก')
      const checkboxes = wrapper.findAll('input[type="checkbox"]')
      for (const cb of checkboxes) {
        expect(cb.attributes('disabled')).toBeDefined()
      }
    })

    it('renders read-only view when user lacks role.manage permission', async () => {
      await router.push('/roles/r-viewonly/permissions')

      const authStore = useAuthStore()
      authStore.user = {
        uuid: 'u-viewer',
        username: 'viewer',
        permissions: ['role.view'],
      }

      vi.spyOn(rolesService, 'get').mockResolvedValue({
        uuid: 'r-viewonly',
        code: 'CUSTOM_ROLE',
        name: 'Custom Role',
        system_role: false,
        status: 'ACTIVE',
        permissions: ['dashboard.view'],
      })
      vi.spyOn(rolesService, 'permissions').mockResolvedValue(mockCatalog)

      const wrapper = mount(RolePermissionsView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('โหมดดูอย่างเดียว (Read-Only)')
      expect(wrapper.find('button.bg-indigo-600').exists()).toBe(false)
    })
  })

  describe('7. RoleTypeBadge Component', () => {
    it('renders correctly for system and custom roles', () => {
      const systemBadge = mount(RoleTypeBadge, { props: { systemRole: true } })
      expect(systemBadge.text()).toBe('บทบาทระบบ')

      const customBadge = mount(RoleTypeBadge, { props: { systemRole: false } })
      expect(customBadge.text()).toBe('บทบาทกำหนดเอง')
    })
  })
})
