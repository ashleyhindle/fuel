import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'

interface RouteParams {
  params: Promise<{ slug: string }>
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

    const { slug } = await params

    const post = await prisma.post.findUnique({
      where: { slug },
      select: { id: true },
    })

    if (!post) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    // Check if already liked
    const existingLike = await prisma.like.findUnique({
      where: {
        userId_postId: {
          userId: user.id,
          postId: post.id,
        },
      },
    })

    if (existingLike) {
      return NextResponse.json(
        { error: 'Already liked' },
        { status: 400 }
      )
    }

    await prisma.like.create({
      data: {
        userId: user.id,
        postId: post.id,
      },
    })

    const likeCount = await prisma.like.count({
      where: { postId: post.id },
    })

    return NextResponse.json({ liked: true, likeCount })
  } catch (error) {
    console.error('Like post error:', error)
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

    const { slug } = await params

    const post = await prisma.post.findUnique({
      where: { slug },
      select: { id: true },
    })

    if (!post) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    await prisma.like.deleteMany({
      where: {
        userId: user.id,
        postId: post.id,
      },
    })

    const likeCount = await prisma.like.count({
      where: { postId: post.id },
    })

    return NextResponse.json({ liked: false, likeCount })
  } catch (error) {
    console.error('Unlike post error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function GET(request: NextRequest, { params }: RouteParams) {
  try {
    const { slug } = await params

    const post = await prisma.post.findUnique({
      where: { slug },
      select: { id: true },
    })

    if (!post) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    const user = await getCurrentUser(request.headers)

    const [likeCount, liked] = await Promise.all([
      prisma.like.count({ where: { postId: post.id } }),
      user
        ? prisma.like.findUnique({
            where: { userId_postId: { userId: user.id, postId: post.id } },
          })
        : null,
    ])

    return NextResponse.json({
      likeCount,
      liked: !!liked,
    })
  } catch (error) {
    console.error('Get like status error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
