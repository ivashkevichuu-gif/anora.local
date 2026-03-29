import React from 'react'

export default function Spinner({ color = 'primary', fullPage = false }) {
  const spinner = <div className={`spinner-border text-${color}`} role="status" />
  if (fullPage) return <div className="text-center py-5">{spinner}</div>
  return spinner
}
