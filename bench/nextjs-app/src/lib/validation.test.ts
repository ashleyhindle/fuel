import { describe, it, expect } from 'vitest'
import {
  validateEmail,
  validatePassword,
  validateUsername,
  validatePostTitle,
  validatePostContent,
  validateCommentContent,
  slugify,
  generateUniqueSlug,
} from './validation'

describe('validateEmail', () => {
  it('returns true for valid emails', () => {
    expect(validateEmail('test@example.com')).toBe(true)
    expect(validateEmail('user.name@domain.co.uk')).toBe(true)
    expect(validateEmail('user+tag@example.org')).toBe(true)
  })

  it('returns false for invalid emails', () => {
    expect(validateEmail('')).toBe(false)
    expect(validateEmail('invalid')).toBe(false)
    expect(validateEmail('invalid@')).toBe(false)
    expect(validateEmail('@example.com')).toBe(false)
    expect(validateEmail('user@.com')).toBe(false)
  })
})

describe('validatePassword', () => {
  it('returns empty array for valid passwords', () => {
    expect(validatePassword('Password1')).toEqual([])
    expect(validatePassword('MySecure123')).toEqual([])
    expect(validatePassword('Test1234!')).toEqual([])
  })

  it('returns errors for short passwords', () => {
    const errors = validatePassword('Pass1')
    expect(errors.some(e => e.message.includes('8 characters'))).toBe(true)
  })

  it('returns errors for passwords without uppercase', () => {
    const errors = validatePassword('password123')
    expect(errors.some(e => e.message.includes('uppercase'))).toBe(true)
  })

  it('returns errors for passwords without lowercase', () => {
    const errors = validatePassword('PASSWORD123')
    expect(errors.some(e => e.message.includes('lowercase'))).toBe(true)
  })

  it('returns errors for passwords without numbers', () => {
    const errors = validatePassword('PasswordABC')
    expect(errors.some(e => e.message.includes('number'))).toBe(true)
  })
})

describe('validateUsername', () => {
  it('returns empty array for valid names', () => {
    expect(validateUsername('Jo')).toEqual([])
    expect(validateUsername('John Doe')).toEqual([])
    expect(validateUsername('A'.repeat(50))).toEqual([])
  })

  it('returns errors for too short names', () => {
    const errors = validateUsername('J')
    expect(errors.some(e => e.message.includes('2 characters'))).toBe(true)
  })

  it('returns errors for too long names', () => {
    const errors = validateUsername('A'.repeat(51))
    expect(errors.some(e => e.message.includes('50 characters'))).toBe(true)
  })
})

describe('validatePostTitle', () => {
  it('returns empty array for valid titles', () => {
    expect(validatePostTitle('My Post')).toEqual([])
    expect(validatePostTitle('A'.repeat(200))).toEqual([])
  })

  it('returns errors for empty titles', () => {
    const errors = validatePostTitle('')
    expect(errors.some(e => e.message.includes('required'))).toBe(true)
  })

  it('returns errors for whitespace-only titles', () => {
    const errors = validatePostTitle('   ')
    expect(errors.some(e => e.message.includes('required'))).toBe(true)
  })

  it('returns errors for too long titles', () => {
    const errors = validatePostTitle('A'.repeat(201))
    expect(errors.some(e => e.message.includes('200 characters'))).toBe(true)
  })
})

describe('validatePostContent', () => {
  it('returns empty array for valid content', () => {
    expect(validatePostContent('Hello world')).toEqual([])
  })

  it('returns errors for empty content', () => {
    const errors = validatePostContent('')
    expect(errors.some(e => e.message.includes('required'))).toBe(true)
  })

  it('returns errors for too long content', () => {
    const errors = validatePostContent('A'.repeat(50001))
    expect(errors.some(e => e.message.includes('50000 characters'))).toBe(true)
  })
})

describe('validateCommentContent', () => {
  it('returns empty array for valid comments', () => {
    expect(validateCommentContent('Nice post!')).toEqual([])
  })

  it('returns errors for empty comments', () => {
    const errors = validateCommentContent('')
    expect(errors.some(e => e.message.includes('required'))).toBe(true)
  })

  it('returns errors for too long comments', () => {
    const errors = validateCommentContent('A'.repeat(5001))
    expect(errors.some(e => e.message.includes('5000 characters'))).toBe(true)
  })
})

describe('slugify', () => {
  it('converts text to slug format', () => {
    expect(slugify('Hello World')).toBe('hello-world')
    expect(slugify('My  Post   Title')).toBe('my-post-title')
    expect(slugify('  Leading and Trailing  ')).toBe('leading-and-trailing')
  })

  it('removes special characters', () => {
    expect(slugify('Hello! World?')).toBe('hello-world')
    expect(slugify('Test@#$%^&*() Slug')).toBe('test-slug')
  })

  it('handles unicode characters', () => {
    expect(slugify('Café au lait')).toBe('caf-au-lait')
  })
})

describe('generateUniqueSlug', () => {
  it('appends a unique suffix to the base slug', () => {
    const slug = generateUniqueSlug('test-post')
    expect(slug).toMatch(/^test-post-[a-z0-9]+$/)
    expect(slug.length).toBeGreaterThan('test-post-'.length)
  })
})
