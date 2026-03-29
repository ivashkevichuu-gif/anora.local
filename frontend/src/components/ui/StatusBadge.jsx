import React from 'react'

const TYPE_CLASSES = {
  deposit:    'bg-success',
  withdrawal: 'bg-warning text-dark',
}

const STATUS_CLASSES = {
  pending:   'badge-pending',
  approved:  'badge-approved',
  rejected:  'badge-rejected',
  completed: 'badge-completed',
}

export function TypeBadge({ type }) {
  return (
    <span className={`badge ${TYPE_CLASSES[type] ?? 'bg-secondary'}`}>
      {type.charAt(0).toUpperCase() + type.slice(1)}
    </span>
  )
}

export function StatusBadge({ status }) {
  return (
    <span className={`badge ${STATUS_CLASSES[status] ?? 'bg-secondary'}`}>
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  )
}
