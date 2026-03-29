import { api } from '../api/client'

export const adminService = {
  login:           (username, password) => api.adminLogin({ username, password }),
  logout:          ()                   => api.adminLogout(),
  getMe:           ()                   => api.adminMe(),
  getUsers:        ()                   => api.adminUsers(),
  getTransactions: ()                   => api.adminTxs(),
  getWithdrawals:  ()                   => api.adminWithdrawals(),
  processWithdrawal: (id, action)       => api.adminAction({ id, action }),
}
