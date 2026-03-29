import React from 'react'

/**
 * Renders a Bootstrap alert for success or error states.
 * Pass either `error` (string) or `success` (object with .message)
 */
export default function StatusMessage({ error, success }) {
  if (error)            return <div className="alert alert-danger">{error}</div>
  if (success?.message) return <div className="alert alert-success">{success.message}</div>
  return null
}
