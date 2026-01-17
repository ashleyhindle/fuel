import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser, hashPassword } from '@/lib/auth'
import { validatePassword, validateUsername } from '@/lib/validation'

export async function GET(request: NextRequest) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    // Get user stats
    const [postCount, followerCount, followingCount] = await Promise.all([
      prisma.post.count({ where: { authorId: user.id } }),
      prisma.follow.count({ where: { followingId: user.id } }),
      prisma.follow.count({ where: { followerId: user.id } }),
    ])

    return NextResponse.json({
      user: {
        id: user.id,
        email: user.email,
        name: user.name,
        bio: user.bio,
        avatar: user.avatar,
        role: user.role,
        createdAt: user.createdAt,
      },
      stats: {
        posts: postCount,
        followers: followerCount,
        following: followingCount,
      },
    })
  } catch (error) {
    console.error('Get me error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function PATCH(request: NextRequest) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    const body = await request.json()
    const { name, bio, avatar, currentPassword, newPassword } = body

    const errors = []
    const updateData: Record<string, string> = {}

    // Validate name if provided
    if (name !== undefined) {
      if (name) {
        const nameErrors = validateUsername(name)
        errors.push(...nameErrors)
        if (nameErrors.length === 0) {
          updateData.name = name
        }
      } else {
        updateData.name = ''
      }
    }

    // Update bio if provided
    if (bio !== undefined) {
      if (bio && bio.length > 500) {
        errors.push({ field: 'bio', message: 'Bio must be less than 500 characters' })
      } else {
        updateData.bio = bio || ''
      }
    }

    // Update avatar if provided
    if (avatar !== undefined) {
      updateData.avatar = avatar || ''
    }

    // Handle password change
    if (newPassword) {
      if (!currentPassword) {
        errors.push({ field: 'currentPassword', message: 'Current password is required' })
      } else {
        const { verifyPassword } = await import('@/lib/auth')
        const isValid = await verifyPassword(currentPassword, user.password)
        if (!isValid) {
          errors.push({ field: 'currentPassword', message: 'Current password is incorrect' })
        } else {
          const passwordErrors = validatePassword(newPassword)
          errors.push(...passwordErrors)
          if (passwordErrors.length === 0) {
            updateData.password = await hashPassword(newPassword)
          }
        }
      }
    }

    if (errors.length > 0) {
      return NextResponse.json({ errors }, { status: 400 })
    }

    const updatedUser = await prisma.user.update({
      where: { id: user.id },
      data: updateData,
      select: {
        id: true,
        email: true,
        name: true,
        bio: true,
        avatar: true,
        role: true,
        updatedAt: true,
      },
    })

    return NextResponse.json({ user: updatedUser })
  } catch (error) {
    console.error('Update me error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
