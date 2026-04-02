/**
 * Canvas-based browser fingerprint utility.
 * Renders a predefined string to an offscreen <canvas>, extracts the image
 * data via toDataURL(), and hashes it with SHA-256 (SubtleCrypto).
 * Returns a hex-encoded hash string.
 */
export async function getCanvasFingerprint() {
  const canvas = document.createElement('canvas')
  canvas.width = 200
  canvas.height = 50

  const ctx = canvas.getContext('2d')
  ctx.textBaseline = 'top'
  ctx.font = '14px Arial'
  ctx.fillStyle = '#f60'
  ctx.fillRect(0, 0, 200, 50)
  ctx.fillStyle = '#069'
  ctx.fillText('anora.bet fingerprint', 2, 15)
  ctx.fillStyle = 'rgba(102, 204, 0, 0.7)'
  ctx.fillText('canvas-fp-v1', 4, 30)

  const dataUrl = canvas.toDataURL()
  const encoder = new TextEncoder()
  const data = encoder.encode(dataUrl)
  const hashBuffer = await crypto.subtle.digest('SHA-256', data)

  const hashArray = Array.from(new Uint8Array(hashBuffer))
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('')
}
