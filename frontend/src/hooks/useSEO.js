import { useEffect } from 'react'

/**
 * Set page title and meta description for SEO.
 * Resets to defaults on unmount.
 */
export function useSEO(title, description) {
  useEffect(() => {
    const prevTitle = document.title
    const metaDesc = document.querySelector('meta[name="description"]')
    const prevDesc = metaDesc?.getAttribute('content') || ''

    if (title) document.title = `${title} | ANORA`
    if (description && metaDesc) metaDesc.setAttribute('content', description)

    return () => {
      document.title = prevTitle
      if (metaDesc) metaDesc.setAttribute('content', prevDesc)
    }
  }, [title, description])
}
