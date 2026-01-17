import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  formatDistanceToNow,
  classNames,
  truncate,
  capitalizeFirst,
  debounce,
  throttle,
} from './utils'

describe('formatDistanceToNow', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2024-01-15T12:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('returns "just now" for recent times', () => {
    const date = new Date('2024-01-15T11:59:30Z')
    expect(formatDistanceToNow(date)).toBe('just now')
  })

  it('returns minutes ago', () => {
    const date = new Date('2024-01-15T11:55:00Z')
    expect(formatDistanceToNow(date)).toBe('5m ago')
  })

  it('returns hours ago', () => {
    const date = new Date('2024-01-15T09:00:00Z')
    expect(formatDistanceToNow(date)).toBe('3h ago')
  })

  it('returns days ago', () => {
    const date = new Date('2024-01-12T12:00:00Z')
    expect(formatDistanceToNow(date)).toBe('3d ago')
  })

  it('returns weeks ago', () => {
    const date = new Date('2024-01-01T12:00:00Z')
    expect(formatDistanceToNow(date)).toBe('2w ago')
  })

  it('returns months ago', () => {
    const date = new Date('2023-11-15T12:00:00Z')
    expect(formatDistanceToNow(date)).toBe('2mo ago')
  })

  it('returns years ago', () => {
    const date = new Date('2022-01-15T12:00:00Z')
    expect(formatDistanceToNow(date)).toBe('2y ago')
  })
})

describe('classNames', () => {
  it('joins multiple class names', () => {
    expect(classNames('foo', 'bar', 'baz')).toBe('foo bar baz')
  })

  it('filters out falsy values', () => {
    expect(classNames('foo', null, 'bar', undefined, false, 'baz')).toBe('foo bar baz')
  })

  it('returns empty string for no valid classes', () => {
    expect(classNames(null, undefined, false)).toBe('')
  })
})

describe('truncate', () => {
  it('returns original string if shorter than length', () => {
    expect(truncate('Hello', 10)).toBe('Hello')
  })

  it('truncates and adds ellipsis if longer than length', () => {
    expect(truncate('Hello World', 8)).toBe('Hello Wo...')
  })

  it('handles exact length', () => {
    expect(truncate('Hello', 5)).toBe('Hello')
  })
})

describe('capitalizeFirst', () => {
  it('capitalizes the first character', () => {
    expect(capitalizeFirst('hello')).toBe('Hello')
    expect(capitalizeFirst('world')).toBe('World')
  })

  it('handles already capitalized strings', () => {
    expect(capitalizeFirst('Hello')).toBe('Hello')
  })

  it('handles single characters', () => {
    expect(capitalizeFirst('a')).toBe('A')
  })

  it('handles empty strings', () => {
    expect(capitalizeFirst('')).toBe('')
  })
})

describe('debounce', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('delays function execution', () => {
    const fn = vi.fn()
    const debouncedFn = debounce(fn, 100)

    debouncedFn()
    expect(fn).not.toHaveBeenCalled()

    vi.advanceTimersByTime(100)
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('resets timer on subsequent calls', () => {
    const fn = vi.fn()
    const debouncedFn = debounce(fn, 100)

    debouncedFn()
    vi.advanceTimersByTime(50)
    debouncedFn()
    vi.advanceTimersByTime(50)
    expect(fn).not.toHaveBeenCalled()

    vi.advanceTimersByTime(50)
    expect(fn).toHaveBeenCalledTimes(1)
  })
})

describe('throttle', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('executes immediately on first call', () => {
    const fn = vi.fn()
    const throttledFn = throttle(fn, 100)

    throttledFn()
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('ignores calls within throttle period', () => {
    const fn = vi.fn()
    const throttledFn = throttle(fn, 100)

    throttledFn()
    throttledFn()
    throttledFn()
    expect(fn).toHaveBeenCalledTimes(1)
  })

  it('allows calls after throttle period', () => {
    const fn = vi.fn()
    const throttledFn = throttle(fn, 100)

    throttledFn()
    vi.advanceTimersByTime(100)
    throttledFn()
    expect(fn).toHaveBeenCalledTimes(2)
  })
})
