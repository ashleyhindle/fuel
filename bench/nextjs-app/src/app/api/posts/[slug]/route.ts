import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'
import { validatePostTitle, validatePostContent, slugify, generateUniqueSlug } from '@/lib/validation'

interface RouteParams {
  params: Promise<{ slug: string }>
}

export async function GET(request: NextRequest, { params }: RouteParams) {
  try {
    const { slug } = await params

    const post = await prisma.post.findUnique({
      where: { slug },
      include: {
        author: {
          select: { id: true, name: true, avatar: true, bio: true },
        },
        category: true,
        tags: {
          include: { tag: true },
        },
        _count: {
          select: { comments: true, likes: true },
        },
      },
    })

    if (!post) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    // Increment view count
    await prisma.post.update({
      where: { id: post.id },
      data: { viewCount: { increment: 1 } },
    })

    return NextResponse.json({
      post: {
        ...post,
        tags: post.tags.map(pt => pt.tag),
        commentCount: post._count.comments,
        likeCount: post._count.likes,
        viewCount: post.viewCount + 1,
        _count: undefined,
      },
    })
  } catch (error) {
    console.error('Get post error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function PATCH(request: NextRequest, { params }: RouteParams) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    const { slug } = await params

    const existingPost = await prisma.post.findUnique({
      where: { slug },
    })

    if (!existingPost) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    // Check ownership or admin
    if (existingPost.authorId !== user.id && user.role !== 'admin') {
      return NextResponse.json(
        { error: 'Forbidden' },
        { status: 403 }
      )
    }

    const body = await request.json()
    const { title, content, excerpt, categoryId, tagIds, published, featured } = body

    // Validate if provided
    const errors = []
    if (title !== undefined) {
      errors.push(...validatePostTitle(title))
    }
    if (content !== undefined) {
      errors.push(...validatePostContent(content))
    }

    if (errors.length > 0) {
      return NextResponse.json({ errors }, { status: 400 })
    }

    // Build update data
    const updateData: Record<string, unknown> = {}

    if (title !== undefined) {
      updateData.title = title
      // Update slug if title changed
      let newSlug = slugify(title)
      if (newSlug !== existingPost.slug) {
        const slugExists = await prisma.post.findFirst({
          where: { slug: newSlug, id: { not: existingPost.id } },
        })
        if (slugExists) {
          newSlug = generateUniqueSlug(newSlug)
        }
        updateData.slug = newSlug
      }
    }

    if (content !== undefined) updateData.content = content
    if (excerpt !== undefined) updateData.excerpt = excerpt
    if (categoryId !== undefined) updateData.categoryId = categoryId || null
    if (featured !== undefined && user.role === 'admin') updateData.featured = featured

    if (published !== undefined) {
      updateData.published = published
      if (published && !existingPost.publishedAt) {
        updateData.publishedAt = new Date()
      }
    }

    // Handle tags
    if (tagIds !== undefined) {
      // Delete existing tags
      await prisma.postTag.deleteMany({
        where: { postId: existingPost.id },
      })

      if (tagIds.length > 0) {
        await prisma.postTag.createMany({
          data: tagIds.map((tagId: string) => ({
            postId: existingPost.id,
            tagId,
          })),
        })
      }
    }

    const post = await prisma.post.update({
      where: { id: existingPost.id },
      data: updateData,
      include: {
        author: {
          select: { id: true, name: true, avatar: true },
        },
        category: true,
        tags: {
          include: { tag: true },
        },
      },
    })

    return NextResponse.json({
      post: {
        ...post,
        tags: post.tags.map(pt => pt.tag),
      },
    })
  } catch (error) {
    console.error('Update post error:', error)
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
    })

    if (!post) {
      return NextResponse.json(
        { error: 'Post not found' },
        { status: 404 }
      )
    }

    // Check ownership or admin
    if (post.authorId !== user.id && user.role !== 'admin') {
      return NextResponse.json(
        { error: 'Forbidden' },
        { status: 403 }
      )
    }

    await prisma.post.delete({
      where: { id: post.id },
    })

    return NextResponse.json({ success: true })
  } catch (error) {
    console.error('Delete post error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
