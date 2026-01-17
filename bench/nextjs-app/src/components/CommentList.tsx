'use client'

import { useState } from 'react'
import { formatDistanceToNow } from '@/lib/utils'

interface Author {
  id: string
  name: string | null
  avatar: string | null
}

interface Comment {
  id: string
  content: string
  createdAt: string
  author: Author
  replies?: Comment[]
}

interface CommentListProps {
  comments: Comment[]
  onReply?: (commentId: string, content: string) => Promise<void>
  currentUserId?: string
}

export function CommentList({ comments, onReply, currentUserId }: CommentListProps) {
  return (
    <div className="space-y-6">
      {comments.map(comment => (
        <CommentItem
          key={comment.id}
          comment={comment}
          onReply={onReply}
          currentUserId={currentUserId}
          depth={0}
        />
      ))}
    </div>
  )
}

interface CommentItemProps {
  comment: Comment
  onReply?: (commentId: string, content: string) => Promise<void>
  currentUserId?: string
  depth: number
}

function CommentItem({ comment, onReply, currentUserId, depth }: CommentItemProps) {
  const [isReplying, setIsReplying] = useState(false)
  const [replyContent, setReplyContent] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  const handleSubmitReply = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!replyContent.trim() || !onReply) return

    setIsSubmitting(true)
    try {
      await onReply(comment.id, replyContent)
      setReplyContent('')
      setIsReplying(false)
    } catch (error) {
      console.error('Failed to submit reply:', error)
    } finally {
      setIsSubmitting(false)
    }
  }

  const maxDepth = 3

  return (
    <div className={depth > 0 ? 'ml-8 border-l-2 border-gray-200 dark:border-gray-700 pl-4' : ''}>
      <div className="flex gap-3">
        {comment.author.avatar ? (
          <img
            src={comment.author.avatar}
            alt={comment.author.name || 'Author'}
            className="w-8 h-8 rounded-full flex-shrink-0"
          />
        ) : (
          <div className="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-600 flex-shrink-0" />
        )}

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 text-sm">
            <span className="font-medium text-gray-900 dark:text-white">
              {comment.author.name || 'Anonymous'}
            </span>
            <span className="text-gray-500 dark:text-gray-400">
              {formatDistanceToNow(new Date(comment.createdAt))}
            </span>
          </div>

          <p className="mt-1 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
            {comment.content}
          </p>

          {currentUserId && depth < maxDepth && (
            <button
              onClick={() => setIsReplying(!isReplying)}
              className="mt-2 text-sm text-blue-600 dark:text-blue-400 hover:underline"
            >
              {isReplying ? 'Cancel' : 'Reply'}
            </button>
          )}

          {isReplying && (
            <form onSubmit={handleSubmitReply} className="mt-3">
              <textarea
                value={replyContent}
                onChange={(e) => setReplyContent(e.target.value)}
                placeholder="Write a reply..."
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <div className="mt-2 flex justify-end">
                <button
                  type="submit"
                  disabled={!replyContent.trim() || isSubmitting}
                  className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isSubmitting ? 'Posting...' : 'Post Reply'}
                </button>
              </div>
            </form>
          )}

          {comment.replies && comment.replies.length > 0 && (
            <div className="mt-4 space-y-4">
              {comment.replies.map(reply => (
                <CommentItem
                  key={reply.id}
                  comment={reply}
                  onReply={onReply}
                  currentUserId={currentUserId}
                  depth={depth + 1}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
