import { api } from '../api/client'

export const authService = {
  getMe:     ()             => api.me(),
  login:     (email, pass)  => api.login({ email, password: pass }),
  logout:    ()             => api.logout(),
  register:  (email, pass, referralCode = null)  => api.register({ email, password: pass, ...(referralCode ? { referral_code: referralCode } : {}) }),
  verify:    (token)        => api.verify({ token }),
  oauthStart: (provider)    => api.oauthStart(provider),
}
