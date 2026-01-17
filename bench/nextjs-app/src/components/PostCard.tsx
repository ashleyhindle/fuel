'use client'

import Link from 'next/link'
import { formatDistanceToNow } from '@/lib/utils'

interface Author {
  id: string
  name: string | null
  avatar: string | null
}

interface Category {
  id: string
  name: string
  slug: string
}

interface Tag {
  id: string
  name: string
  slug: string
}

interface PostCardProps {
  post: {
    id: string
    title: string
    slug: string
    excerpt: string | null
    publishedAt: string | null
    viewCount: number
    author: Author
    category: Category | null
    tags: Tag[]
    commentCount: number
    likeCount: number
  }
  showAuthor?: boolean
}

export function PostCard({ post, showAuthor = true }: PostCardProps) {
  return (
    <article className="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow">
      <div className="flex flex-col gap-3">
        {/* Category and Tags */}
        <div className="flex flex-wrap gap-2">
          {post.category && (
            <Link
              href={`/posts?category=${post.category.slug}`}
              className="text-xs font-medium px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded"
            >
              {post.category.name}
            </Link>
          )}
          {post.tags.slice(0, 3).map(tag => (
            <Link
              key={tag.id}
              href={`/posts?tag=${tag.slug}`}
              className="text-xs px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded"
            >
              #{tag.name}
            </Link>
          ))}
        </div>

        {/* Title */}
        <h2 className="text-xl font-semibold">
          <Link
            href={`/posts/${post.slug}`}
            className="text-gray-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400"
          >
            {post.title}
          </Link>
        </h2>

        {/* Excerpt */}
        {post.excerpt && (
          <p className="text-gray-600 dark:text-gray-300 line-clamp-2">
            {post.excerpt}
          </p>
        )}

        {/* Meta */}
        <div className="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <div className="flex items-center gap-4">
            {showAuthor && (
              <Link
                href={`/users/${post.author.id}`}
                className="flex items-center gap-2 hover:text-gray-700 dark:hover:text-gray-200"
              >
                {post.author.avatar ? (
                  <img
                    src={post.author.avatar}
                    alt={post.author.name || 'Author'}
                    className="w-6 h-6 rounded-full"
                  />
                ) : (
                  <div className="w-6 h-6 rounded-full bg-gray-200 dark:bg-gray-600" />
                )}
                <span>{post.author.name || 'Anonymous'}</span>
              </Link>
            )}
            {post.publishedAt && (
              <time dateTime={post.publishedAt}>
                {formatDistanceToNow(new Date(post.publishedAt))}
              </time>
            )}
          </div>

          <div className="flex items-center gap-4">
            <span className="flex items-center gap-1">
              <HeartIcon className="w-4 h-4" />
              {post.likeCount}
            </span>
            <span className="flex items-center gap-1">
              <CommentIcon className="w-4 h-4" />
              {post.commentCount}
            </span>
            <span className="flex items-center gap-1">
              <EyeIcon className="w-4 h-4" />
              {post.viewCount}
            </span>
          </div>
        </div>
      </div>
    </article>
  )
}

function HeartIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
    </svg>
  )
}

function CommentIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
    </svg>
  )
}

function EyeIcon({ className }: { className?: string }) {
  return (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
    </svg>
  )
}
