import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'
import { slugify, generateUniqueSlug } from '@/lib/validation'

export async function GET(request: NextRequest) {
  try {
    const { searchParams } = new URL(request.url)
    const search = searchParams.get('search')

    const where = search
      ? { name: { contains: search } }
      : {}

    const tags = await prisma.tag.findMany({
      where,
      orderBy: { name: 'asc' },
      include: {
        _count: {
          select: { posts: { where: { post: { published: true } } } },
        },
      },
    })

    return NextResponse.json({
      tags: tags.map(tag => ({
        ...tag,
        postCount: tag._count.posts,
        _count: undefined,
      })),
    })
  } catch (error) {
    console.error('Get tags error:', error)
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
    const { name } = body

    if (!name || name.length < 2) {
      return NextResponse.json(
        { errors: [{ field: 'name', message: 'Name must be at least 2 characters' }] },
        { status: 400 }
      )
    }

    if (name.length > 30) {
      return NextResponse.json(
        { errors: [{ field: 'name', message: 'Name must be less than 30 characters' }] },
        { status: 400 }
      )
    }

    let slug = slugify(name)

    // Check if tag already exists
    const existing = await prisma.tag.findUnique({ where: { slug } })
    if (existing) {
      return NextResponse.json({ tag: existing })
    }

    const tag = await prisma.tag.create({
      data: { name, slug },
    })

    return NextResponse.json({ tag }, { status: 201 })
  } catch (error) {
    console.error('Create tag error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
