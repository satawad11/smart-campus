<script setup>
import { computed } from 'vue'
import { parseReleaseNotes } from '@/utils/releaseNotes'

/**
 * Renders release notes from a safe Markdown subset. Text interpolation only
 * (no v-html): release bodies come from GitHub and are treated as untrusted.
 */
const props = defineProps({
  notes: { type: String, default: '' },
})

const blocks = computed(() => parseReleaseNotes(props.notes))
const headingClass = {
  1: 'text-base font-semibold text-gray-900',
  2: 'text-sm font-semibold text-gray-900',
  3: 'text-sm font-medium text-gray-800',
}
</script>

<template>
  <div data-test="release-notes" class="space-y-2 text-sm text-gray-700">
    <p v-if="!blocks.length" class="text-gray-400">ไม่มีรายละเอียดการเปลี่ยนแปลง</p>
    <template v-for="(block, i) in blocks" :key="i">
      <div v-if="block.type === 'h'" :class="[headingClass[block.level], i > 0 ? 'pt-2' : '']">
        <template v-for="(part, j) in block.inlines" :key="j">
          <code v-if="part.t === 'code'" class="rounded bg-gray-100 px-1 font-mono text-xs">{{
            part.v
          }}</code>
          <span v-else>{{ part.v }}</span>
        </template>
      </div>
      <ul v-else-if="block.type === 'ul'" class="list-disc space-y-1 pl-5">
        <li v-for="(item, j) in block.items" :key="j">
          <template v-for="(part, k) in item" :key="k">
            <strong v-if="part.t === 'b'" class="font-semibold text-gray-900">{{ part.v }}</strong>
            <code
              v-else-if="part.t === 'code'"
              class="rounded bg-gray-100 px-1 font-mono text-xs"
              >{{ part.v }}</code
            >
            <span v-else>{{ part.v }}</span>
          </template>
        </li>
      </ul>
      <pre
        v-else-if="block.type === 'code'"
        class="overflow-x-auto rounded bg-gray-900 p-3 font-mono text-xs text-gray-100"
        >{{ block.text }}</pre>
      <p v-else>
        <template v-for="(part, j) in block.inlines" :key="j">
          <strong v-if="part.t === 'b'" class="font-semibold text-gray-900">{{ part.v }}</strong>
          <code v-else-if="part.t === 'code'" class="rounded bg-gray-100 px-1 font-mono text-xs">{{
            part.v
          }}</code>
          <span v-else>{{ part.v }}</span>
        </template>
      </p>
    </template>
  </div>
</template>
