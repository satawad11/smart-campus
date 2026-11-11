/**
 * Minimal, safe Markdown subset for release notes (ACR-OPS-01).
 *
 * Returns plain data (no HTML) that the template renders with text
 * interpolation, so nothing in a release body can inject markup or script.
 * Supported: # / ## / ### headings, "-" / "*" / "1." list items, paragraphs,
 * fenced code blocks, **bold**, `code`. Links render as their text only.
 *
 * @param {string} markdown
 * @returns {Array<{type: 'h'|'ul'|'p'|'code', level?: number, items?: Array, inlines?: Array, text?: string}>}
 */
export function parseReleaseNotes(markdown) {
  const blocks = []
  const lines = String(markdown || '')
    .replace(/\r\n?/g, '\n')
    .split('\n')
  let paragraph = []
  let list = null
  let code = null

  const flushParagraph = () => {
    if (paragraph.length) {
      blocks.push({ type: 'p', inlines: parseInlines(paragraph.join(' ')) })
      paragraph = []
    }
  }
  const flushList = () => {
    if (list) {
      blocks.push(list)
      list = null
    }
  }

  for (const raw of lines) {
    if (code) {
      if (/^\s*```/.test(raw)) {
        blocks.push({ type: 'code', text: code.join('\n') })
        code = null
      } else {
        code.push(raw)
      }
      continue
    }
    const line = raw.trim()
    if (/^```/.test(line)) {
      flushParagraph()
      flushList()
      code = []
      continue
    }
    const heading = /^(#{1,6})\s+(.*)$/.exec(line)
    if (heading) {
      flushParagraph()
      flushList()
      blocks.push({
        type: 'h',
        level: Math.min(heading[1].length, 3),
        inlines: parseInlines(heading[2]),
      })
      continue
    }
    const item = /^(?:[-*+]|\d+[.)])\s+(.*)$/.exec(line)
    if (item) {
      flushParagraph()
      if (!list) list = { type: 'ul', items: [] }
      list.items.push(parseInlines(item[1]))
      continue
    }
    if (!line) {
      flushParagraph()
      flushList()
      continue
    }
    flushList()
    paragraph.push(line)
  }
  if (code) blocks.push({ type: 'code', text: code.join('\n') })
  flushParagraph()
  flushList()
  return blocks
}

/**
 * @param {string} text
 * @returns {Array<{t: 'text'|'b'|'code', v: string}>}
 */
export function parseInlines(text) {
  // [label](url) -> label ; drop raw HTML tags.
  const clean = String(text)
    .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/<[^>]*>/g, '')
  const out = []
  const re = /(\*\*([^*]+)\*\*|`([^`]+)`)/g
  let last = 0
  let m
  while ((m = re.exec(clean)) !== null) {
    if (m.index > last) out.push({ t: 'text', v: clean.slice(last, m.index) })
    if (m[2] !== undefined) out.push({ t: 'b', v: m[2] })
    else out.push({ t: 'code', v: m[3] })
    last = re.lastIndex
  }
  if (last < clean.length) out.push({ t: 'text', v: clean.slice(last) })
  return out
}
