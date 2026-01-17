import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { hashPassword, createSession } from '@/lib/auth'
import { validateEmail, validatePassword, validateUsername } from '@/lib/validation'

export async function POST(request: NextRequest) {
  try {
    const body = await request.json()
    const { email, password, name } = body

    // Validate input
    const errors = []

    if (!email || !validateEmail(email)) {
      errors.push({ field: 'email', message: 'Valid email is required' })
    }

    const passwordErrors = validatePassword(password || '')
    errors.push(...passwordErrors)

    if (name) {
      const nameErrors = validateUsername(name)
      errors.push(...nameErrors)
    }

    if (errors.length > 0) {
      return NextResponse.json({ errors }, { status: 400 })
    }

    // Check if user exists
    const existingUser = await prisma.user.findUnique({
      where: { email },
    })

    if (existingUser) {
      return NextResponse.json(
        { errors: [{ field: 'email', message: 'Email already registered' }] },
        { status: 400 }
      )
    }

    // Create user
    const hashedPassword = await hashPassword(password)
    const user = await prisma.user.create({
      data: {
        email,
        password: hashedPassword,
        name: name || null,
      },
      select: {
        id: true,
        email: true,
        name: true,
        createdAt: true,
      },
    })

    // Create session
    const token = await createSession(user.id)

    return NextResponse.json(
      { user, token },
      { status: 201 }
    )
  } catch (error) {
    console.error('Registration error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
