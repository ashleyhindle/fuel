import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'
import { validatePostTitle, validatePostContent, slugify, generateUniqueSlug } from '@/lib/validation'

export async function GET(request: NextRequest) {
  try {
    const { searchParams } = new URL(request.url)
    const page = parseInt(searchParams.get('page') || '1')
    const limit = parseInt(searchParams.get('limit') || '10')
    const categorySlug = searchParams.get('category')
    const tagSlug = searchParams.get('tag')
    const authorId = searchParams.get('author')
    const search = searchParams.get('search')
    const featured = searchParams.get('featured') === 'true'

    const skip = (page - 1) * limit

    const where: Record<string, unknown> = {
      published: true,
    }

    if (categorySlug) {
      where.category = { slug: categorySlug }
    }

    if (tagSlug) {
      where.tags = { some: { tag: { slug: tagSlug } } }
    }

    if (authorId) {
      where.authorId = authorId
    }

    if (featured) {
      where.featured = true
    }

    if (search) {
      where.OR = [
        { title: { contains: search } },
        { content: { contains: search } },
        { excerpt: { contains: search } },
      ]
    }

    const [posts, total] = await Promise.all([
      prisma.post.findMany({
        where,
        skip,
        take: limit,
        orderBy: { publishedAt: 'desc' },
        include: {
          author: {
            select: { id: true, name: true, avatar: true },
          },
          category: {
            select: { id: true, name: true, slug: true },
          },
          tags: {
            include: {
              tag: { select: { id: true, name: true, slug: true } },
            },
          },
          _count: {
            select: { comments: true, likes: true },
          },
        },
      }),
      prisma.post.count({ where }),
    ])

    return NextResponse.json({
      posts: posts.map(post => ({
        ...post,
        tags: post.tags.map(pt => pt.tag),
        commentCount: post._count.comments,
        likeCount: post._count.likes,
        _count: undefined,
      })),
      pagination: {
        page,
        limit,
        total,
        pages: Math.ceil(total / limit),
      },
    })
  } catch (error) {
    console.error('Get posts error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function POST(request: NextRequest) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user) {
      return NextResponse.json(
        { error: 'Unauthorized' },
        { status: 401 }
      )
    }

    const body = await request.json()
    const { title, content, excerpt, categoryId, tagIds, published } = body

    // Validate
    const errors = [
      ...validatePostTitle(title || ''),
      ...validatePostContent(content || ''),
    ]

    if (errors.length > 0) {
      return NextResponse.json({ errors }, { status: 400 })
    }

    // Generate slug
    let slug = slugify(title)
    const existingPost = await prisma.post.findUnique({ where: { slug } })
    if (existingPost) {
      slug = generateUniqueSlug(slug)
    }

    // Create post
    const post = await prisma.post.create({
      data: {
        title,
        slug,
        content,
        excerpt: excerpt || content.substring(0, 200),
        authorId: user.id,
        categoryId: categoryId || null,
        published: published || false,
        publishedAt: published ? new Date() : null,
        tags: tagIds?.length
          ? {
              create: tagIds.map((tagId: string) => ({
                tag: { connect: { id: tagId } },
              })),
            }
          : undefined,
      },
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

    return NextResponse.json(
      {
        post: {
          ...post,
          tags: post.tags.map(pt => pt.tag),
        },
      },
      { status: 201 }
    )
  } catch (error) {
    console.error('Create post error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
