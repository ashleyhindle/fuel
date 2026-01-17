import { NextRequest, NextResponse } from 'next/server'
import { deleteSession, getTokenFromHeaders } from '@/lib/auth'

export async function POST(request: NextRequest) {
  try {
    const token = getTokenFromHeaders(request.headers)

    if (!token) {
      return NextResponse.json(
        { error: 'No token provided' },
        { status: 401 }
      )
    }

    await deleteSession(token)

    return NextResponse.json({ success: true })
  } catch (error) {
    console.error('Logout error:', error)
    return NextResponse.json(
      { error: 'Internal server error' },
      { status: 500 }
    )
  }
}
