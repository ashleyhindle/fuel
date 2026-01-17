export interface ValidationError {
  field: string
  message: string
}

export interface ValidationResult<T> {
  success: boolean
  data?: T
  errors?: ValidationError[]
}

export function validateEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(email)
}

export function validatePassword(password: string): ValidationError[] {
  const errors: ValidationError[] = []

  if (password.length < 8) {
    errors.push({ field: 'password', message: 'Password must be at least 8 characters' })
  }
  if (!/[A-Z]/.test(password)) {
    errors.push({ field: 'password', message: 'Password must contain at least one uppercase letter' })
  }
  if (!/[a-z]/.test(password)) {
    errors.push({ field: 'password', message: 'Password must contain at least one lowercase letter' })
  }
  if (!/[0-9]/.test(password)) {
    errors.push({ field: 'password', message: 'Password must contain at least one number' })
  }

  return errors
}

export function validateUsername(name: string): ValidationError[] {
  const errors: ValidationError[] = []

  if (name.length < 2) {
    errors.push({ field: 'name', message: 'Name must be at least 2 characters' })
  }
  if (name.length > 50) {
    errors.push({ field: 'name', message: 'Name must be less than 50 characters' })
  }

  return errors
}

export function validatePostTitle(title: string): ValidationError[] {
  const errors: ValidationError[] = []

  if (!title.trim()) {
    errors.push({ field: 'title', message: 'Title is required' })
  }
  if (title.length > 200) {
    errors.push({ field: 'title', message: 'Title must be less than 200 characters' })
  }

  return errors
}

export function validatePostContent(content: string): ValidationError[] {
  const errors: ValidationError[] = []

  if (!content.trim()) {
    errors.push({ field: 'content', message: 'Content is required' })
  }
  if (content.length > 50000) {
    errors.push({ field: 'content', message: 'Content must be less than 50000 characters' })
  }

  return errors
}

export function validateCommentContent(content: string): ValidationError[] {
  const errors: ValidationError[] = []

  if (!content.trim()) {
    errors.push({ field: 'content', message: 'Comment content is required' })
  }
  if (content.length > 5000) {
    errors.push({ field: 'content', message: 'Comment must be less than 5000 characters' })
  }

  return errors
}

export function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s_-]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

export function generateUniqueSlug(baseSlug: string): string {
  const timestamp = Date.now().toString(36)
  return `${baseSlug}-${timestamp}`
}
