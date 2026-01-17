import { randomBytes, scrypt, timingSafeEqual } from 'crypto'
import { promisify } from 'util'
import prisma from './prisma'

const scryptAsync = promisify(scrypt)

const SALT_LENGTH = 16
const KEY_LENGTH = 64
const SESSION_EXPIRY_DAYS = 7

export async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(SALT_LENGTH).toString('hex')
  const derivedKey = (await scryptAsync(password, salt, KEY_LENGTH)) as Buffer
  return `${salt}:${derivedKey.toString('hex')}`
}

export async function verifyPassword(password: string, hash: string): Promise<boolean> {
  const [salt, key] = hash.split(':')
  const derivedKey = (await scryptAsync(password, salt, KEY_LENGTH)) as Buffer
  const keyBuffer = Buffer.from(key, 'hex')
  return timingSafeEqual(derivedKey, keyBuffer)
}

export function generateToken(): string {
  return randomBytes(32).toString('hex')
}

export async function createSession(userId: string): Promise<string> {
  const token = generateToken()
  const expiresAt = new Date()
  expiresAt.setDate(expiresAt.getDate() + SESSION_EXPIRY_DAYS)

  await prisma.session.create({
    data: {
      userId,
      token,
      expiresAt,
    },
  })

  return token
}

export async function validateSession(token: string) {
  const session = await prisma.session.findUnique({
    where: { token },
    include: { user: true },
  })

  if (!session) return null
  if (session.expiresAt < new Date()) {
    await prisma.session.delete({ where: { id: session.id } })
    return null
  }

  return session.user
}

export async function deleteSession(token: string): Promise<void> {
  await prisma.session.deleteMany({ where: { token } })
}

export async function deleteAllUserSessions(userId: string): Promise<void> {
  await prisma.session.deleteMany({ where: { userId } })
}

export function getTokenFromHeaders(headers: Headers): string | null {
  const authHeader = headers.get('authorization')
  if (!authHeader?.startsWith('Bearer ')) return null
  return authHeader.slice(7)
}

export async function getCurrentUser(headers: Headers) {
  const token = getTokenFromHeaders(headers)
  if (!token) return null
  return validateSession(token)
}
