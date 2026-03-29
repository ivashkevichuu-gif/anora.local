import { useState } from 'react'

/**
 * Reusable pagination hook + component.
 *
 * Usage:
 *   const { page, setPage, paginated, totalPages } = usePagination(items, 20)
 *   <Pagination page={page} totalPages={totalPages} onChange={setPage} />
 */
export function usePagination(items = [], perPage = 20) {
  const [page, setPage] = useState(1)
  const totalPages = Math.max(1, Math.ceil(items.length / perPage))
  const safePage   = Math.min(page, totalPages)
  const paginated  = items.slice((safePage - 1) * perPage, safePage * perPage)
  return { page: safePage, setPage, paginated, totalPages, total: items.length }
}

export default function Pagination({ page, totalPages, onChange }) {
  if (totalPages <= 1) return null

  // Show at most 7 page buttons with ellipsis
  const pages = []
  if (totalPages <= 7) {
    for (let i = 1; i <= totalPages; i++) pages.push(i)
  } else {
    pages.push(1)
    if (page > 3)           pages.push('…')
    for (let i = Math.max(2, page - 1); i <= Math.min(totalPages - 1, page + 1); i++) pages.push(i)
    if (page < totalPages - 2) pages.push('…')
    pages.push(totalPages)
  }

  const btn = (label, target, disabled = false) => (
    <button
      key={label}
      onClick={() => !disabled && onChange(target)}
      disabled={disabled}
      className="btn btn-sm"
      style={{
        minWidth: 34,
        background: target === page && typeof target === 'number'
          ? 'rgba(124,58,237,0.3)'
          : 'rgba(255,255,255,0.04)',
        border: target === page && typeof target === 'number'
          ? '1px solid rgba(124,58,237,0.6)'
          : '1px solid rgba(255,255,255,0.08)',
        color: target === page && typeof target === 'number'
          ? 'var(--neon-purple)'
          : 'var(--text-muted)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.4 : 1,
      }}
    >
      {label}
    </button>
  )

  return (
    <div className="d-flex align-items-center gap-1 mt-3 flex-wrap">
      {btn('‹', page - 1, page === 1)}
      {pages.map((p, i) =>
        p === '…'
          ? <span key={`e${i}`} className="px-1" style={{ color: 'var(--text-muted)' }}>…</span>
          : btn(p, p)
      )}
      {btn('›', page + 1, page === totalPages)}
      <span className="ms-2 text-xs" style={{ color: 'var(--text-muted)' }}>
        Page {page} of {totalPages}
      </span>
    </div>
  )
}
