import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'

import api from '@/services/api'
import { usersService } from '@/services/users'
import { useAuthStore } from '@/stores/auth'
import UserListView from '@/views/users/UserListView.vue'
import UserCreateView from '@/views/users/UserCreateView.vue'
import UserDetailView from '@/views/users/UserDetailView.vue'
import UserEditView from '@/views/users/UserEditView.vue'
import ResetPasswordModal from '@/components/users/ResetPasswordModal.vue'
import ConfirmModal from '@/components/common/ConfirmModal.vue'
import StatusBadge from '@/components/common/StatusBadge.vue'

describe('AUTH-016: User Management UI', () => {
  let router

  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()

    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/users', name: 'users.index', component: UserListView },
        { path: '/users/new', name: 'users.create', component: UserCreateView },
        { path: '/users/:uuid', name: 'users.show', component: UserDetailView },
        { path: '/users/:uuid/edit', name: 'users.edit', component: UserEditView },
      ],
    })
    await router.push('/users')
    await router.isReady()
  })

  describe('1. usersService API Client Wrapper', () => {
    it('calls list with server pagination, filters, and sort parameters', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: {
          data: [{ uuid: 'u-1', username: 'john' }],
          meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
        },
      })

      const res = await usersService.list({
        page: 2,
        per_page: 10,
        keyword: 'john',
        status: 'ACTIVE',
        sort: 'username',
        order: 'asc',
      })

      expect(getSpy).toHaveBeenCalledWith('/users', {
        params: {
          page: 2,
          per_page: 10,
          keyword: 'john',
          status: 'ACTIVE',
          sort: 'username',
          order: 'asc',
        },
      })
      expect(res.data).toHaveLength(1)
      expect(res.meta.pagination.current_page).toBe(1)
    })

    it('calls get by UUID', async () => {
      const getSpy = vi.spyOn(api, 'get').mockResolvedValue({
        data: { data: { uuid: 'u-123', username: 'target_user' } },
      })

      const res = await usersService.get('u-123')
      expect(getSpy).toHaveBeenCalledWith('/users/u-123')
      expect(res.username).toBe('target_user')
    })

    it('calls create with only approved payload fields', async () => {
      const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
        data: { data: { uuid: 'u-new', username: 'new_user' } },
      })

      const res = await usersService.create({
        username: 'new_user',
        email: 'new@campus.local',
        password: 'Password@123',
        status: 'ACTIVE',
        must_change_password: true,
      })

      expect(postSpy).toHaveBeenCalledWith('/users', {
        username: 'new_user',
        email: 'new@campus.local',
        password: 'Password@123',
        status: 'ACTIVE',
        must_change_password: true,
      })
      expect(res.uuid).toBe('u-new')
    })

    it('calls update with only approved fields (username, email)', async () => {
      const putSpy = vi.spyOn(api, 'put').mockResolvedValue({
        data: { data: { uuid: 'u-123', username: 'updated_user' } },
      })

      const res = await usersService.update('u-123', {
        username: 'updated_user',
        email: 'updated@campus.local',
      })

      expect(putSpy).toHaveBeenCalledWith('/users/u-123', {
        username: 'updated_user',
        email: 'updated@campus.local',
      })
      expect(res.username).toBe('updated_user')
    })

    it('calls dedicated changeStatus endpoint with PATCH', async () => {
      const patchSpy = vi.spyOn(api, 'patch').mockResolvedValue({
        data: { data: { uuid: 'u-123', status: 'DISABLED' } },
      })

      const res = await usersService.changeStatus('u-123', 'DISABLED')
      expect(patchSpy).toHaveBeenCalledWith('/users/u-123/status', { status: 'DISABLED' })
      expect(res.status).toBe('DISABLED')
    })

    it('calls dedicated resetPassword endpoint with POST', async () => {
      const postSpy = vi.spyOn(api, 'post').mockResolvedValue({
        data: { success: true, message: 'Password reset.' },
      })

      const res = await usersService.resetPassword('u-123', 'NewPass@123')
      expect(postSpy).toHaveBeenCalledWith('/users/u-123/reset-password', {
        password: 'NewPass@123',
      })
      expect(res.success).toBe(true)
    })

    it('never exposes DELETE user or role mutation endpoints in usersService', () => {
      expect(usersService.delete).toBeUndefined()
      expect(usersService.destroy).toBeUndefined()
      expect(usersService.deleteUser).toBeUndefined()
      expect(usersService.assignRole).toBeUndefined()
      expect(usersService.removeRole).toBeUndefined()
    })
  })

  describe('2. User List View (UserListView.vue)', () => {
    it('renders user list with server data and pagination controls', async () => {
      vi.spyOn(usersService, 'list').mockResolvedValue({
        data: [
          {
            uuid: 'u-001',
            username: 'somchai',
            email: 'somchai@campus.local',
            status: 'ACTIVE',
            roles: [{ code: 'OFFICER', name: 'Security Officer' }],
            mfa_enabled: true,
            must_change_password: false,
            last_login_at: '2026-09-18T10:00:00Z',
            created_at: '2026-09-01T08:00:00Z',
          },
        ],
        meta: {
          pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 },
        },
      })

      const wrapper = mount(UserListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('จัดการผู้ใช้งาน')
      expect(wrapper.text()).toContain('somchai')
      expect(wrapper.text()).toContain('somchai@campus.local')
      expect(wrapper.text()).toContain('Security Officer')
      expect(wrapper.text()).toContain('เปิดใช้งาน') // MFA badge
      expect(wrapper.text()).toContain('ใช้งานปกติ') // StatusBadge
    })

    it('renders empty state when no users are returned', async () => {
      vi.spyOn(usersService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
      })

      const wrapper = mount(UserListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('ไม่พบข้อมูลผู้ใช้งาน')
    })

    it('applies search keyword and status filters with page reset to 1', async () => {
      const listSpy = vi.spyOn(usersService, 'list').mockResolvedValue({
        data: [],
        meta: { pagination: { current_page: 1, per_page: 20, total: 0, last_page: 1 } },
      })

      const wrapper = mount(UserListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Change status filter
      const statusSelect = wrapper.find('#status-filter')
      await statusSelect.setValue('LOCKED')

      expect(listSpy).toHaveBeenLastCalledWith(
        expect.objectContaining({
          status: 'LOCKED',
          page: 1,
        }),
      )
    })

    it('handles 403 Forbidden without logging the user out', async () => {
      vi.spyOn(usersService, 'list').mockRejectedValue({
        status: 403,
        code: 'FORBIDDEN',
        message: 'This action is unauthorized.',
      })

      const authStore = useAuthStore()
      authStore.user = { id: 1, uuid: 'current-admin', username: 'admin' }

      const wrapper = mount(UserListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('คุณไม่มีสิทธิ์ในการดำเนินการนี้')
      expect(authStore.user).not.toBeNull() // Ensure user is not logged out on 403
    })

    it('prevents self-disable action where detectable on current user row', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'current-user-uuid', username: 'me_admin' }

      vi.spyOn(usersService, 'list').mockResolvedValue({
        data: [
          {
            uuid: 'current-user-uuid',
            username: 'me_admin',
            email: 'me@campus.local',
            status: 'ACTIVE',
            roles: [],
            mfa_enabled: false,
          },
        ],
        meta: { pagination: { current_page: 1, per_page: 20, total: 1, last_page: 1 } },
      })

      const wrapper = mount(UserListView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Disable button should not be shown for current logged in user
      const disableBtn = wrapper.find('button[title="ปิดใช้งานบัญชี"]')
      expect(disableBtn.exists()).toBe(false)
    })
  })

  describe('3. User Create View (UserCreateView.vue)', () => {
    it('validates required fields on client side', async () => {
      const wrapper = mount(UserCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('form').trigger('submit.prevent')

      expect(wrapper.text()).toContain('กรุณากรอกชื่อผู้ใช้')
      expect(wrapper.text()).toContain('กรุณากรอกรหัสผ่านเริ่มต้น')
    })

    it('submits valid create payload and redirects', async () => {
      const createSpy = vi.spyOn(usersService, 'create').mockResolvedValue({
        uuid: 'new-user-uuid',
        username: 'created_user',
      })

      const wrapper = mount(UserCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('#username').setValue('created_user')
      await wrapper.find('#email').setValue('created@campus.local')
      await wrapper.find('#password').setValue('Password@123')
      await wrapper.find('#status').setValue('ACTIVE')

      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(createSpy).toHaveBeenCalledWith({
        username: 'created_user',
        email: 'created@campus.local',
        password: 'Password@123',
        status: 'ACTIVE',
        must_change_password: true,
      })
    })

    it('renders server 422 field errors properly', async () => {
      vi.spyOn(usersService, 'create').mockRejectedValue({
        status: 422,
        code: 'VALIDATION_ERROR',
        message: 'The username has already been taken.',
        fields: {
          username: ['The username has already been taken.'],
        },
      })

      const wrapper = mount(UserCreateView, {
        global: {
          plugins: [router],
        },
      })

      await wrapper.find('#username').setValue('duplicate_user')
      await wrapper.find('#password').setValue('Password@123')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(wrapper.text()).toContain('The username has already been taken.')
    })
  })

  describe('4. User Detail View (UserDetailView.vue)', () => {
    it('loads safe fields by UUID and never exposes internal numeric id', async () => {
      await router.push('/users/u-target-uuid')

      vi.spyOn(usersService, 'get').mockResolvedValue({
        uuid: 'u-target-uuid',
        username: 'somying',
        email: 'somying@campus.local',
        status: 'ACTIVE',
        roles: [{ code: 'AUDITOR', name: 'Security Auditor' }],
        mfa_enabled: true,
        must_change_password: false,
        last_login_at: '2026-09-18T12:00:00Z',
        created_at: '2026-09-01T08:00:00Z',
      })

      const wrapper = mount(UserDetailView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      expect(wrapper.text()).toContain('somying')
      expect(wrapper.text()).toContain('somying@campus.local')
      expect(wrapper.text()).toContain('Security Auditor')
      expect(wrapper.text()).toContain('เปิดใช้งานแล้ว (TOTP)')
      expect(wrapper.text()).toContain('u-target-uuid')

      // Ensure forbidden words/fields are not present
      expect(wrapper.text()).not.toContain('mfa_secret')
      expect(wrapper.text()).not.toContain('failed_login_count')
    })

    it('handles 409 conflict errors (LAST_SUPER_ADMIN_REQUIRED / USER_SELF_DISABLE_FORBIDDEN)', async () => {
      const authStore = useAuthStore()
      authStore.user = { uuid: 'acting-admin-uuid', username: 'acting_admin' }

      await router.push('/users/super-admin-uuid')

      vi.spyOn(usersService, 'get').mockResolvedValue({
        uuid: 'super-admin-uuid',
        username: 'root_superadmin',
        status: 'ACTIVE',
        roles: [{ code: 'SUPER_ADMIN', name: 'Super Admin' }],
      })

      vi.spyOn(usersService, 'changeStatus').mockRejectedValue({
        status: 409,
        code: 'LAST_SUPER_ADMIN_REQUIRED',
        message: 'Cannot disable the last usable super administrator.',
      })

      const wrapper = mount(UserDetailView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Trigger status modal - find Disable button
      const buttons = wrapper.findAll('button')
      const disableBtn = buttons.find((b) => b.text().includes('ปิดใช้งาน (DISABLED)'))
      expect(disableBtn).toBeDefined()
      await disableBtn.trigger('click')

      // Confirm status change
      const confirmModalComp = wrapper.findComponent(ConfirmModal)
      confirmModalComp.vm.$emit('confirm')
      await flushPromises()

      expect(wrapper.text()).toContain(
        'ไม่สามารถปิดใช้งานหรือล็อกผู้ดูแลระบบระดับสูงสุด (SUPER_ADMIN) บัญชีสุดท้ายได้',
      )
    })
  })

  describe('5. User Edit View (UserEditView.vue)', () => {
    it('allows editing ONLY username and email', async () => {
      await router.push('/users/u-edit-uuid/edit')

      vi.spyOn(usersService, 'get').mockResolvedValue({
        uuid: 'u-edit-uuid',
        username: 'original_name',
        email: 'original@campus.local',
      })

      const updateSpy = vi.spyOn(usersService, 'update').mockResolvedValue({
        uuid: 'u-edit-uuid',
        username: 'new_name',
        email: 'new@campus.local',
      })

      const wrapper = mount(UserEditView, {
        global: {
          plugins: [router],
        },
      })

      await flushPromises()

      // Verify no password or status inputs exist
      expect(wrapper.find('input[type="password"]').exists()).toBe(false)
      expect(wrapper.find('#status').exists()).toBe(false)

      await wrapper.find('#edit-username').setValue('new_name')
      await wrapper.find('#edit-email').setValue('new@campus.local')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(updateSpy).toHaveBeenCalledWith('u-edit-uuid', {
        username: 'new_name',
        email: 'new@campus.local',
      })
    })
  })

  describe('6. Reset Password Modal & Status Badges', () => {
    it('ResetPasswordModal submits password and emits success', async () => {
      const resetSpy = vi.spyOn(usersService, 'resetPassword').mockResolvedValue({
        success: true,
        message: 'Password reset successfully.',
      })

      const wrapper = mount(ResetPasswordModal, {
        props: {
          show: true,
          user: { uuid: 'target-uuid', username: 'target_user' },
        },
      })

      await wrapper.find('#modal-password').setValue('NewSecret@123')
      await wrapper.find('#modal-password-confirm').setValue('NewSecret@123')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(resetSpy).toHaveBeenCalledWith('target-uuid', 'NewSecret@123')
      expect(wrapper.emitted('success')).toBeTruthy()
      expect(wrapper.emitted('close')).toBeTruthy()
    })

    it('StatusBadge correctly renders labels and styles for all enum values', () => {
      const activeBadge = mount(StatusBadge, { props: { status: 'ACTIVE' } })
      expect(activeBadge.text()).toBe('ใช้งานปกติ')

      const lockedBadge = mount(StatusBadge, { props: { status: 'LOCKED' } })
      expect(lockedBadge.text()).toBe('บัญชีถูกล็อก')

      const disabledBadge = mount(StatusBadge, { props: { status: 'DISABLED' } })
      expect(disabledBadge.text()).toBe('ปิดใช้งาน')

      const expiredBadge = mount(StatusBadge, { props: { status: 'EXPIRED' } })
      expect(expiredBadge.text()).toBe('หมดอายุ')
    })
  })
})
