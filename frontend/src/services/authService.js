import { api } from '../api/client'

export const authService = {
  getMe:     ()             => api.me(),
  login:     (email, pass)  => api.login({ email, password: pass }),
  logout:    ()             => api.logout(),
  register:  (email, pass)  => api.register({ email, password: pass }),
  verify:    (token)        => api.verify({ token }),
}
