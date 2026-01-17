import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'
import { validateCommentContent } from '@/lib/validation'

interface RouteParams {
  params: Promise<{ slug: string }>
}

export async function GET(request: NextRequest, { params }: RouteParams) {
  try {
    const { slug } = await params
    const { searchParams } = new URL(request.url)
    const page = parseInt(searchParams.get('page') || '1')
    const limit = parseInt(searchParams.get('limit') || '20')

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

    const skip = (page - 1) * limit

    const [comments, total] = await Promise.all([
      prisma.comment.findMany({
        where: {
          postId: post.id,
          parentId: null, // Only top-level comments
        },
        skip,
        take: limit,
        orderBy: { createdAt: 'desc' },
        include: {
          author: {
            select: { id: true, name: true, avatar: true },
          },
          replies: {
            include: {
              author: {
                select: { id: true, name: true, avatar: true },
              },
            },
            orderBy: { createdAt: 'asc' },
          },
        },
      }),
      prisma.comment.count({
        where: { postId: post.id, parentId: null },
      }),
    ])

    return NextResponse.json({
      comments,
      pagination: {
        page,
        limit,
        total,
        pages: Math.ceil(total / limit),
      },
    })
  } catch (error) {
    console.error('Get comments error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
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

    const body = await request.json()
    const { content, parentId } = body

    // Validate
    const errors = validateCommentContent(content || '')

    if (errors.length > 0) {
      return NextResponse.json({ errors }, { status: 400 })
    }

    // Verify parent comment exists if replying
    if (parentId) {
      const parentComment = await prisma.comment.findUnique({
        where: { id: parentId },
      })

      if (!parentComment || parentComment.postId !== post.id) {
        return NextResponse.json(
          { error: 'Parent comment not found' },
          { status: 404 }
        )
      }
    }

    const comment = await prisma.comment.create({
      data: {
        content,
        authorId: user.id,
        postId: post.id,
        parentId: parentId || null,
      },
      include: {
        author: {
          select: { id: true, name: true, avatar: true },
        },
      },
    })

    return NextResponse.json({ comment }, { status: 201 })
  } catch (error) {
    console.error('Create comment error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
