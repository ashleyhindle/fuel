import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'

interface RouteParams {
  params: Promise<{ id: string }>
}

export async function GET(request: NextRequest, { params }: RouteParams) {
  try {
    const { id } = await params

    const user = await prisma.user.findUnique({
      where: { id },
      select: {
        id: true,
        name: true,
        bio: true,
        avatar: true,
        createdAt: true,
        _count: {
          select: {
            posts: { where: { published: true } },
            followers: true,
            following: true,
          },
        },
      },
    })

    if (!user) {
      return NextResponse.json(
        { error: 'User not found' },
        { status: 404 }
      )
    }

    const currentUser = await getCurrentUser(request.headers)
    let isFollowing = false

    if (currentUser && currentUser.id !== user.id) {
      const follow = await prisma.follow.findUnique({
        where: {
          followerId_followingId: {
            followerId: currentUser.id,
            followingId: user.id,
          },
        },
      })
      isFollowing = !!follow
    }

    return NextResponse.json({
      user: {
        ...user,
        postCount: user._count.posts,
        followerCount: user._count.followers,
        followingCount: user._count.following,
        isFollowing,
        _count: undefined,
      },
    })
  } catch (error) {
    console.error('Get user error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
