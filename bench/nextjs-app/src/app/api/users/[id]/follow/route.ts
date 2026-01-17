import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'

interface RouteParams {
  params: Promise<{ id: string }>
}

export async function POST(request: NextRequest, { params }: RouteParams) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    const { id: targetId } = await params

    if (user.id === targetId) {
      return NextResponse.json(
        { error: 'Cannot follow yourself' },
        { status: 400 }
      )
    }

    const targetUser = await prisma.user.findUnique({
      where: { id: targetId },
      select: { id: true },
    })

    if (!targetUser) {
      return NextResponse.json(
        { error: 'User not found' },
        { status: 404 }
      )
    }

    // Check if already following
    const existingFollow = await prisma.follow.findUnique({
      where: {
        followerId_followingId: {
          followerId: user.id,
          followingId: targetId,
        },
      },
    })

    if (existingFollow) {
      return NextResponse.json(
        { error: 'Already following' },
        { status: 400 }
      )
    }

    await prisma.follow.create({
      data: {
        followerId: user.id,
        followingId: targetId,
      },
    })

    const followerCount = await prisma.follow.count({
      where: { followingId: targetId },
    })

    return NextResponse.json({ following: true, followerCount })
  } catch (error) {
    console.error('Follow user error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function DELETE(request: NextRequest, { params }: RouteParams) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    const { id: targetId } = await params

    await prisma.follow.deleteMany({
      where: {
        followerId: user.id,
        followingId: targetId,
      },
    })

    const followerCount = await prisma.follow.count({
      where: { followingId: targetId },
    })

    return NextResponse.json({ following: false, followerCount })
  } catch (error) {
    console.error('Unfollow user error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
