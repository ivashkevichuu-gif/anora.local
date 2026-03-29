import { useState, useCallback } from 'react'

/**
 * Hook for user-triggered async actions (form submits, button clicks).
 * Returns { execute, loading, error, success, reset }
 */
export function useAsync(asyncFn) {
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState(null)
  const [success, setSuccess] = useState(null)

  const execute = useCallback(async (...args) => {
    setLoading(true)
    setError(null)
    setSuccess(null)
    try {
      const result = await asyncFn(...args)
      setSuccess(result)
      return result
    } catch (e) {
      setError(e.message)
      throw e
    } finally {
      setLoading(false)
    }
  }, [asyncFn])

  const reset = useCallback(() => {
    setError(null)
    setSuccess(null)
  }, [])

  return { execute, loading, error, success, reset }
}
