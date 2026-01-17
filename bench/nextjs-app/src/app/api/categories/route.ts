import { NextRequest, NextResponse } from 'next/server'
import prisma from '@/lib/prisma'
import { getCurrentUser } from '@/lib/auth'
import { slugify, generateUniqueSlug } from '@/lib/validation'

export async function GET() {
  try {
    const categories = await prisma.category.findMany({
      orderBy: { name: 'asc' },
      include: {
        _count: {
          select: { posts: { where: { published: true } } },
        },
      },
    })

    return NextResponse.json({
      categories: categories.map(cat => ({
        ...cat,
        postCount: cat._count.posts,
        _count: undefined,
      })),
    })
  } catch (error) {
    console.error('Get categories error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}

export async function POST(request: NextRequest) {
  try {
    const user = await getCurrentUser(request.headers)

    if (!user || user.role !== 'admin') {
      return NextResponse.json(
        { error: 'Forbidden' },
        { status: 403 }
      )
    }

    const body = await request.json()
    const { name, description, color } = body

    if (!name || name.length < 2) {
      return NextResponse.json(
        { errors: [{ field: 'name', message: 'Name must be at least 2 characters' }] },
        { status: 400 }
      )
    }

    let slug = slugify(name)
    const existing = await prisma.category.findUnique({ where: { slug } })
    if (existing) {
      slug = generateUniqueSlug(slug)
    }

    const category = await prisma.category.create({
      data: {
        name,
        slug,
        description: description || null,
        color: color || null,
      },
    })

    return NextResponse.json({ category }, { status: 201 })
  } catch (error) {
    console.error('Create category error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
