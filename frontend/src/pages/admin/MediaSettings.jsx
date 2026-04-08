import React, { useState, useEffect, useCallback } from 'react'
import { api } from '../../api/client'

const ROOMS = ['1', '10', '100']

export default function MediaSettings() {
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [data, setData] = useState(null)

  const load = useCallback(async () => {
    try {
      setLoading(true)
      const res = await api.adminMediaSettings()
      setData(res)
    } catch (e) {
      setError(e.message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const save = async (section, payload) => {
    try {
      setSaving(true)
      setError('')
      setSuccess('')
      await api.adminMediaSettingsUpdate({ section, ...payload })
      setSuccess(`${section} settings saved`)
      await load()
      setTimeout(() => setSuccess(''), 3000)
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="text-center py-5"><div className="spinner-border" /></div>
  if (!data) return <div className="alert alert-danger">{error || 'Failed to load'}</div>

  return (
    <div>
      <h4 className="mb-4"><i className="bi bi-camera-reels me-2" />Media Settings</h4>

      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {/* Global toggles */}
      <GlobalToggles data={data.media} onSave={save} saving={saving} />

      <div className="row mt-4">
        <div className="col-lg-6 mb-4">
          <InstagramCard data={data.instagram} onSave={save} saving={saving} />
        </div>
        <div className="col-lg-6 mb-4">
          <TelegramCard data={data.telegram} onSave={save} saving={saving} />
        </div>
      </div>

      <SocialLinksCard data={data.social_links} onSave={save} saving={saving} />

      {data.recent_posts && data.recent_posts.length > 0 && (
        <RecentPosts posts={data.recent_posts} onRefresh={load} />
      )}
    </div>
  )
}

function GlobalToggles({ data, onSave, saving }) {
  const [ig, setIg] = useState(!!data?.instagram_enabled)
  const [tg, setTg] = useState(!!data?.telegram_enabled)

  useEffect(() => {
    setIg(!!data?.instagram_enabled)
    setTg(!!data?.telegram_enabled)
  }, [data])

  return (
    <div className="card bg-dark border-secondary">
      <div className="card-body d-flex align-items-center gap-4 flex-wrap">
        <span className="fw-bold" style={{ color: '#e2e8f0' }}>Global:</span>
        <div className="form-check form-switch">
          <input className="form-check-input" type="checkbox" checked={ig}
            onChange={e => setIg(e.target.checked)} id="globalIg" />
          <label className="form-check-label" htmlFor="globalIg">Instagram</label>
        </div>
        <div className="form-check form-switch">
          <input className="form-check-input" type="checkbox" checked={tg}
            onChange={e => setTg(e.target.checked)} id="globalTg" />
          <label className="form-check-label" htmlFor="globalTg">Telegram</label>
        </div>
        <button className="btn btn-sm btn-primary ms-auto" disabled={saving}
          onClick={() => onSave('media', { instagram_enabled: ig, telegram_enabled: tg })}>
          Save
        </button>
      </div>
    </div>
  )
}

function InstagramCard({ data, onSave, saving }) {
  const [enabled, setEnabled] = useState(!!data?.enabled)
  const [manualMode, setManualMode] = useState(!!data?.manual_mode)
  const [rooms, setRooms] = useState(data?.allowed_rooms || [])
  const [minWin, setMinWin] = useState(data?.min_win_amount || 0)
  const [maxPosts, setMaxPosts] = useState(data?.max_posts_per_day || 10)

  useEffect(() => {
    setEnabled(!!data?.enabled)
    setManualMode(!!data?.manual_mode)
    setRooms(data?.allowed_rooms || [])
    setMinWin(data?.min_win_amount || 0)
    setMaxPosts(data?.max_posts_per_day || 10)
  }, [data])

  const toggleRoom = (r) => {
    setRooms(prev => prev.includes(r) ? prev.filter(x => x !== r) : [...prev, r])
  }

  return (
    <div className="card bg-dark border-secondary h-100">
      <div className="card-header d-flex align-items-center">
        <i className="bi bi-instagram me-2" style={{ color: '#E1306C' }} />
        <span className="fw-bold">Instagram Reels</span>
      </div>
      <div className="card-body">
        <div className="form-check form-switch mb-3">
          <input className="form-check-input" type="checkbox" checked={enabled}
            onChange={e => setEnabled(e.target.checked)} id="igEnabled" />
          <label className="form-check-label" htmlFor="igEnabled">Enable Instagram posting</label>
        </div>

        <div className="form-check form-switch mb-3">
          <input className="form-check-input" type="checkbox" checked={manualMode}
            onChange={e => setManualMode(e.target.checked)} id="igManual"
            style={manualMode ? { backgroundColor: '#f59e0b', borderColor: '#f59e0b' } : {}} />
          <label className="form-check-label" htmlFor="igManual" style={{ color: '#cbd5e1' }}>
            Manual mode
            <span className="ms-2 badge" style={{
              background: manualMode ? 'rgba(245,158,11,0.2)' : 'rgba(34,197,94,0.2)',
              color: manualMode ? '#f59e0b' : '#22c55e',
              fontSize: '.7rem'
            }}>
              {manualMode ? 'Render only — download & post yourself' : 'Auto-publish via API'}
            </span>
          </label>
        </div>

        <label className="form-label" style={{ color: '#cbd5e1' }}>Allowed Rooms</label>
        <div className="d-flex gap-3 mb-3">
          {ROOMS.map(r => (
            <div className="form-check" key={r}>
              <input className="form-check-input" type="checkbox"
                checked={rooms.includes(r)} onChange={() => toggleRoom(r)} id={`room-${r}`} />
              <label className="form-check-label" htmlFor={`room-${r}`}>${r}</label>
            </div>
          ))}
        </div>

        <div className="mb-3">
          <label className="form-label" style={{ color: '#cbd5e1' }}>Min Win Amount ($)</label>
          <input type="number" className="form-control bg-dark text-white border-secondary"
            value={minWin} onChange={e => setMinWin(parseFloat(e.target.value) || 0)} step="0.01" />
        </div>

        <div className="mb-3">
          <label className="form-label" style={{ color: '#cbd5e1' }}>Max Reels / Day</label>
          <input type="number" className="form-control bg-dark text-white border-secondary"
            value={maxPosts} onChange={e => setMaxPosts(parseInt(e.target.value) || 1)} min="1" />
        </div>

        <div className="d-flex justify-content-between align-items-center mb-3">
          <span className="text-muted">Posted today: <strong className="text-white">{data?.posts_today || 0}</strong> / {data?.max_posts_per_day || 10}</span>
          <button className="btn btn-sm btn-outline-warning"
            onClick={() => onSave('reset_instagram_counter', {})} disabled={saving}>
            Reset Counter
          </button>
        </div>

        <button className="btn btn-primary w-100" disabled={saving}
          onClick={() => onSave('instagram', { enabled, manual_mode: manualMode, allowed_rooms: rooms, min_win_amount: minWin, max_posts_per_day: maxPosts })}>
          Save Instagram Settings
        </button>
      </div>
    </div>
  )
}

function TelegramCard({ data, onSave, saving }) {
  const [enabled, setEnabled] = useState(!!data?.enabled)
  const [postNew, setPostNew] = useState(!!data?.post_new_rooms)
  const [postFinished, setPostFinished] = useState(!!data?.post_finished_rooms)

  useEffect(() => {
    setEnabled(!!data?.enabled)
    setPostNew(!!data?.post_new_rooms)
    setPostFinished(!!data?.post_finished_rooms)
  }, [data])

  return (
    <div className="card bg-dark border-secondary h-100">
      <div className="card-header d-flex align-items-center">
        <i className="bi bi-telegram me-2" style={{ color: '#0088cc' }} />
        <span className="fw-bold">Telegram</span>
      </div>
      <div className="card-body">
        <div className="form-check form-switch mb-3">
          <input className="form-check-input" type="checkbox" checked={enabled}
            onChange={e => setEnabled(e.target.checked)} id="tgEnabled" />
          <label className="form-check-label" htmlFor="tgEnabled">Enable Telegram posting</label>
        </div>

        <div className="form-check form-switch mb-3">
          <input className="form-check-input" type="checkbox" checked={postNew}
            onChange={e => setPostNew(e.target.checked)} id="tgNew" />
          <label className="form-check-label" htmlFor="tgNew">Post new games (room started)</label>
        </div>

        <div className="form-check form-switch mb-3">
          <input className="form-check-input" type="checkbox" checked={postFinished}
            onChange={e => setPostFinished(e.target.checked)} id="tgFinished" />
          <label className="form-check-label" htmlFor="tgFinished">Post finished games</label>
        </div>

        <button className="btn btn-primary w-100" disabled={saving}
          onClick={() => onSave('telegram', { enabled, post_new_rooms: postNew, post_finished_rooms: postFinished })}>
          Save Telegram Settings
        </button>
      </div>
    </div>
  )
}

function SocialLinksCard({ data, onSave, saving }) {
  const [tgUrl, setTgUrl] = useState(data?.telegram_url || '')
  const [igUrl, setIgUrl] = useState(data?.instagram_url || '')

  useEffect(() => {
    setTgUrl(data?.telegram_url || '')
    setIgUrl(data?.instagram_url || '')
  }, [data])

  return (
    <div className="card bg-dark border-secondary mb-4">
      <div className="card-header fw-bold" style={{ color: '#e2e8f0' }}>
        <i className="bi bi-link-45deg me-2" />Social Links (Footer)
      </div>
      <div className="card-body">
        <div className="row">
          <div className="col-md-6 mb-3">
            <label className="form-label" style={{ color: '#cbd5e1' }}>Instagram URL</label>
            <input type="url" className="form-control bg-dark text-white border-secondary"
              value={igUrl} onChange={e => setIgUrl(e.target.value)}
              placeholder="https://instagram.com/anora.bet" />
          </div>
          <div className="col-md-6 mb-3">
            <label className="form-label" style={{ color: '#cbd5e1' }}>Telegram URL</label>
            <input type="url" className="form-control bg-dark text-white border-secondary"
              value={tgUrl} onChange={e => setTgUrl(e.target.value)}
              placeholder="https://t.me/anorachannel" />
          </div>
        </div>
        <button className="btn btn-primary" disabled={saving}
          onClick={() => onSave('social_links', { telegram_url: tgUrl, instagram_url: igUrl })}>
          Save Links
        </button>
      </div>
    </div>
  )
}

function RecentPosts({ posts, onRefresh }) {
  const [deleting, setDeleting] = useState(null)

  const statusBadge = (s) => {
    const map = {
      published: 'success', failed: 'danger', rendering: 'info',
      publishing: 'warning', queued: 'secondary', ready_for_download: 'warning'
    }
    const label = s === 'ready_for_download' ? '📥 ready' : s
    return <span className={`badge bg-${map[s] || 'secondary'}`}>{label}</span>
  }

  const getDownloadUrl = (p) => {
    if (!p.video_path) return null
    const filename = p.video_path.split('/').pop()
    return `/media/${filename}`
  }

  const handleDeleteVideo = async (postId) => {
    if (!confirm('Delete this video file from server?')) return
    setDeleting(postId)
    try {
      await api.adminMediaPostAction({ action: 'delete_video', post_id: postId })
      if (onRefresh) await onRefresh()
    } catch (e) {
      alert('Delete failed: ' + e.message)
    } finally {
      setDeleting(null)
    }
  }

  return (
    <div className="card bg-dark border-secondary">
      <div className="card-header fw-bold" style={{ color: '#e2e8f0' }}>
        <i className="bi bi-clock-history me-2" />Recent Posts
      </div>
      <div className="card-body p-0">
        <div className="table-responsive">
          <table className="table table-dark table-striped mb-0">
            <thead>
              <tr>
                <th>Round</th>
                <th>Platform</th>
                <th>Type</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {posts.map(p => {
                const downloadUrl = getDownloadUrl(p)
                const canDownload = p.video_path && ['ready_for_download', 'published', 'failed'].includes(p.status)
                return (
                  <tr key={p.id}>
                    <td>#{p.round_id}</td>
                    <td>{p.platform === 'instagram' ? '📸' : '📲'} {p.platform}</td>
                    <td>{p.post_type}</td>
                    <td>{statusBadge(p.status)}</td>
                    <td>{new Date(p.created_at).toLocaleString()}</td>
                    <td>
                      <div className="d-flex gap-1">
                        {canDownload && downloadUrl && (
                          <a href={downloadUrl} download className="btn btn-sm btn-outline-info"
                            title="Download video">
                            <i className="bi bi-download" />
                          </a>
                        )}
                        {p.video_path && (
                          <button className="btn btn-sm btn-outline-danger"
                            title="Delete video from server"
                            disabled={deleting === p.id}
                            onClick={() => handleDeleteVideo(p.id)}>
                            {deleting === p.id
                              ? <span className="spinner-border spinner-border-sm" />
                              : <i className="bi bi-trash" />}
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}
