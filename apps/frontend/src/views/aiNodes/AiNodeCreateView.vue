<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import aiNodesService from '@/services/aiNodes'

const router = useRouter()

const form = reactive({
  site_uuid: '',
  node_code: '',
  name: '',
  hostname: '',
  ip_address: '',
  api_url: '',
  hardware_type: '',
  cpu_model: '',
  gpu_name: '',
  gpu_memory_mb: '',
  max_camera: '',
})

const loading = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

async function submit() {
  loading.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  const payload = {
    site_uuid: form.site_uuid || null,
    node_code: form.node_code,
    name: form.name,
    hostname: form.hostname || null,
    ip_address: form.ip_address || null,
    api_url: form.api_url || null,
    hardware_type: form.hardware_type || null,
    cpu_model: form.cpu_model || null,
    gpu_name: form.gpu_name || null,
    gpu_memory_mb: form.gpu_memory_mb || null,
    max_camera: form.max_camera || null,
  }

  try {
    const res = await aiNodesService.createNode(payload)
    router.push({ name: 'ai-nodes.show', params: { uuid: res.data.uuid } })
  } catch (err) {
    errorMessage.value = err.message || 'Failed to register AI node.'
    fieldErrors.value = err.fields || {}
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="ai-node-create-view max-w-2xl mx-auto p-6 space-y-6">
    <div class="border-b pb-4">
      <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Register AI Node</h1>
      <p class="text-sm text-gray-500 dark:text-gray-400">
        The physical node confirms itself later via check-in/heartbeat, using a credential you issue
        from its detail page (CAM-004).
      </p>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
    >
      {{ errorMessage }}
    </div>

    <div
      class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4"
    >
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
          >Node Code *</label
        >
        <input
          v-model="form.node_code"
          type="text"
          placeholder="e.g. NODE-01"
          class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        />
        <p v-if="fieldErrors.node_code" class="text-xs text-red-600 mt-1">
          {{ fieldErrors.node_code[0] }}
        </p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
          >Name *</label
        >
        <input
          v-model="form.name"
          type="text"
          placeholder="e.g. Edge Box - Main Gate"
          class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        />
        <p v-if="fieldErrors.name" class="text-xs text-red-600 mt-1">{{ fieldErrors.name[0] }}</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
          >Site UUID</label
        >
        <input
          v-model="form.site_uuid"
          type="text"
          placeholder="Site UUID (optional)"
          class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        />
        <p v-if="fieldErrors.site_uuid" class="text-xs text-red-600 mt-1">
          {{ fieldErrors.site_uuid[0] }}
        </p>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Hostname</label
          >
          <input
            v-model="form.hostname"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >IP Address</label
          >
          <input
            v-model="form.ip_address"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
          >API URL</label
        >
        <input
          v-model="form.api_url"
          type="text"
          class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
        />
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Hardware Type</label
          >
          <input
            v-model="form.hardware_type"
            type="text"
            placeholder="e.g. Jetson Orin"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >CPU Model</label
          >
          <input
            v-model="form.cpu_model"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
      </div>
      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >GPU Name</label
          >
          <input
            v-model="form.gpu_name"
            type="text"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >GPU Memory (MB)</label
          >
          <input
            v-model="form.gpu_memory_mb"
            type="number"
            min="0"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
            >Max Cameras</label
          >
          <input
            v-model="form.max_camera"
            type="number"
            min="0"
            class="w-full px-3 py-2 border rounded-lg text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
          />
        </div>
      </div>

      <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
        <button
          @click="submit"
          :disabled="loading"
          class="px-4 py-2 text-sm text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          {{ loading ? 'Saving...' : 'Register Node' }}
        </button>
      </div>
    </div>
  </div>
</template>
